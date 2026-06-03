<?php

class OTRSClient
{
    private $url;
    private $key;
    private $type;
    private $queue;
    private $priority;
    private $state;
    private $userID;
    private $ArticleType = "note-internal";

    private $ticketid = null;

    public function __construct(array $config)
    {
        $otrs = $config['api']['otrs'];
        $this->url      = $otrs['url'];
        $this->key      = $otrs['key'];
        $this->type     = $otrs['default']['type'];
        $this->queue    = $otrs['default']['queue'];
        $this->priority = $otrs['default']['priority'];
        $this->state    = $otrs['default']['state'];
        $this->userID   = $otrs['default']['userID'];
    }

    public function getUserId($username)
    {
        $url = $this->url;
        $post = array (
            "username"  => $username,
            "function" => "getUserID",
            "apikey" => $this->key
        );
        $postData = $this->urlPost($url, $post);
        if($postData) {
            $userID = $postData;
            return $userID;
        }
        else {
            return FALSE;
        }
    }

    public function CreateTicket($data)
    {
        if(isset($data['type'])) $this->type = $data['type'];
        if(isset($data['queue'])) $this->queue = $data['queue'];
        if(isset($data['state'])) $this->state = $data['state'];
        if(isset($data['priority'])) $this->priority = $data['priority'];
        if(isset($data['userID'])) $this->userID = $data['userID'];

        if(empty($data['title'])) {
            $this->log("No title given for ticket creation");
            return false;
        }

        $post = [
            "title"    => $data['title'],
            "queue"    => $this->queue,
            "user"     => $this->userID,
            "priority" => $this->priority,
            "function" => "createTicket",
            "type"     => $this->type,
            "apikey"   => $this->key
        ];

        $res = $this->urlPost($this->url, $post);
        if($res) {
            $ticketData = json_decode($res);
            if ($ticketData && isset($ticketData->ticketID)) {
                $data['ticketid'] = $ticketData->ticketID;
                $this->ticketid   = $ticketData->ticketID;
                $data['ticketnr'] = $ticketData->ticketNum;
                $this->log("Ticket Created via API", ['ticket_id' => $data['ticketid'], 'ticket_nr' => $data['ticketnr']]);
                return $data;
            }
        }
        $this->log("API Ticket Creation Failed", ['response' => $res]);
        return false;
    }

    public function createArticle($data)
    {
        if(isset($data['ArticleType'])) $this->ArticleType = $data['ArticleType'];
        if(isset($data['Ticketid'])) $this->ticketid = $data['Ticketid'];
        if(isset($data['userID'])) $this->userID = $data['userID'];

        if(empty($data['subject']) || empty($data['body'])) {
            $this->log("Subject or Body missing for article creation");
            return false;
        }

        $post = [
            'user'     => $this->userID,
            'function' => 'createArticle',
            'ticketID' => $this->ticketid,
            'subject'  => $data['subject'],
            'body'     => $data['body'],
            'ContentType' => $data['ContentType'] ?? 'text/html; charset=utf-8',
            'type'     => $this->ArticleType,
            'comment'  => 'created by Whiteboard',
            'from'     => 'Whiteboard',
            'apikey'   => $this->key
        ];

        $res = $this->urlPost($this->url, $post);
        if ($res) {
            $this->log("Article Created via API", ['ticket_id' => $this->ticketid]);
            $data['articleid'] = "notused";
            return $data;
        }
        $this->log("API Article Creation Failed", ['response' => $res]);
        return false;
    }

    private function urlPost($url, $data) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_TIMEOUT        => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $this->log("CURL Error", ['error' => curl_error($ch)]);
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300) {
            return $response;
        }
        return false;
    }

    private function log($message, $data = null) {
        $logFile = __DIR__ . '/../otrs_integration.log';
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [OTRSClient] $message";
        if ($data) {
            $entry .= " | Data: " . json_encode($data);
        }
        file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    }
}
