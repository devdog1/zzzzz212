<?php

class OTRS
{
    private SoapClient $client;

    private bool $debug = true;

    private string $url;
    private string $username;
    private string $password;
    private string $key;

    private string $type;
    private string $queue;
    private string $priority;
    private string $state;
    private int $userID;

    private string $articleType = 'note-internal';

    private ?int $ticketid = null;
    private ?string $ticketnr = null;
    private ?string $title = null;
    private ?string $articleid = null;

    /**
     * Initialize OTRS SOAP client and load configuration.
     */
    public function __construct(array $config)
    {
        $this->url      = $config['soap']['otrs']['url'];
        $this->username = $config['soap']['otrs']['user'];
        $this->password = $config['soap']['otrs']['pass'];
        $this->key      = $config['soap']['otrs']['apikey'];

        $this->type     = $config['soap']['otrs']['default']['type'];
        $this->queue    = $config['soap']['otrs']['default']['queue'];
        $this->priority = $config['soap']['otrs']['default']['priority'];
        $this->state    = $config['soap']['otrs']['default']['state'];
        $this->userID   = (int)$config['soap']['otrs']['default']['userID'];

        $this->client = new SoapClient(
            null,
            [
                'location' => $this->url,
                'uri'      => 'Core',
                'trace'    => true,
                'login'    => $this->username,
                'password' => $this->password,
                'style'    => SOAP_RPC,
                'use'      => SOAP_ENCODED,
            ]
        );
    }

    private function log($message, $data = null) {
        if (!$this->debug) return;
        $logFile = __DIR__ . '/../otrs_integration.log';
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] $message";
        if ($data) {
            $entry .= " | Data: " . json_encode($data);
        }
        file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    }

    /**
     * Convert OTRS SOAP response into an associative array.
     */
    private function parseSoapResponse($response): array
    {
        if (!is_array($response) && !is_object($response)) return [];

        $result = [];
        $temp   = [];
        $i      = 0;

        foreach ((array)$response as $name => $value) {
            if (strpos($name, 's-gensym') === false) {
                continue;
            }

            $temp[$i] = $value;
            $key      = ($i === 0) ? $temp[$i] : $temp[$i - 1];

            if ($i % 2 !== 0) {
                $result[$key] = $value;
            }

            $i++;
        }

        return $result;
    }

    /**
     * Execute a SOAP dispatch call.
     */
    private function dispatch(array $params)
    {
        try {
            $res = $this->client->__soapCall(
                'Dispatch',
                array_merge(
                    [
                        $this->username,
                        $this->password
                    ],
                    $params
                )
            );
            $this->log("SOAP Dispatch Success", ['params' => $params]);
            return $res;
        } catch (Exception $e) {
            $this->log("SOAP Dispatch Failed", ['params' => $params, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function ticketCreate(string $title, string $customerUser, string $body)
    {
        $res = $this->dispatch([
            'TicketObject',
            'TicketCreate',
            'Title',        $title,
            'Queue',        $this->queue,
            'Lock',         'unlock',
            'Type',         $this->type,
            'Service',      '',
            'SLA',          '',
            'State',        $this->state,
            'Priority',     $this->priority,
            'CustomerUser', $customerUser,
            'OwnerID',      $this->userID,
            'UserID',       $this->userID,
        ]);

        if (!$res) return false;

        $ticketId = (int)$res;
        $this->log("Ticket Created", ['ticket_id' => $ticketId, 'title' => $title]);

        // Create initial article
        $this->articleCreate($ticketId, $title, $body);

        // Get Ticket Number
        $info = $this->ticketInfo($ticketId);
        return [
            'ticket_id' => $ticketId,
            'ticket_nr' => $info['TicketNumber'] ?? 'N/A'
        ];
    }

    public function articleCreate(int $ticketId, string $subject, string $body)
    {
        $res = $this->dispatch([
            'TicketObject',
            'ArticleCreate',
            'TicketID',             $ticketId,
            'ArticleType',          $this->articleType,
            'SenderType',           'agent',
            'From',                 $this->username,
            'Subject',              $subject,
            'Body',                 $body,
            'ContentType',          'text/plain; charset=utf8',
            'HistoryType',          'OwnerUpdate',
            'HistoryComment',       'Incident Manager Update',
            'UserID',               $this->userID,
            'NoAgentNotify',        1,
        ]);

        if ($res) {
            $this->log("Article Created", ['ticket_id' => $ticketId, 'article_id' => $res]);
        }
        return $res;
    }

    public function getUserId(string $username)
    {
        $response = $this->urlPost(
            'https://otrs.westmancom.com/wcg/api.php',
            [
                'username' => $username,
                'function' => 'getUserID',
                'apikey'   => $this->key,
            ]
        );

        return $response ?: false;
    }

    public function ticketInfo(int $ticketId): array
    {
        $response = $this->dispatch([
            'TicketObject',
            'TicketGet',
            'TicketID',
            $ticketId,
            'DynamicFields',
            1,
            'Extended',
            1,
        ]);

        if (!$response) return [];

        $ticketInfo = $this->parseSoapResponse((array)$response);

        $this->type     = $ticketInfo['Type'] ?? '';
        $this->queue    = $ticketInfo['Queue'] ?? '';
        $this->priority = $ticketInfo['Priority'] ?? '';
        $this->state    = $ticketInfo['State'] ?? '';
        $this->userID   = (int)($ticketInfo['ResponsibleID'] ?? 0);
        $this->title    = $ticketInfo['Title'] ?? '';
        $this->ticketid = (int)($ticketInfo['TicketID'] ?? 0);
        $this->ticketnr = $ticketInfo['TicketNumber'] ?? '';

        return $ticketInfo;
    }

    public function ticketResponsibleSet(
        int $ticketID,
        int $responsibleID,
        int $userID
    ) {
        return $this->dispatch([
            'TicketObject',
            'TicketResponsibleSet',
            'ResponsibleID',
            $responsibleID,
            'TicketID',
            $ticketID,
            'UserID',
            $userID,
        ]);
    }

    public static function getQueues(): array
    {
        return [
            'TAC',
            'NOC',
            'Network Services',
            'TAC::Business Provisioning',
            'TAC::TAC Provisioning',
            'Network Services::NS Maint',
            'Warehouse',
            'Corporate Support'
        ];
    }

    public static function getTypes(): array
    {
        return [
            'Incident',
            'Problem',
            'Incident::ServiceRequest',
            'Incident::Maintenance',
            'RfC'
        ];
    }

    private function urlPost(
        string $url,
        ?array $data = null,
        ?array $headers = null
    ): string|false {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        if ($headers !== null) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new RuntimeException(
                'cURL Error: ' . curl_error($ch)
            );
        }

        curl_close($ch);

        return $response;
    }
}
