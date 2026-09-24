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
    private $lastDebug = [];

    public function __construct(array $config)
    {
        $otrs = $config['api']['otrs'] ?? [];
        $this->url      = $otrs['url'] ?? '';
        $this->key      = $otrs['key'] ?? '';
        $this->type     = $otrs['default']['type'] ?? '';
        $this->queue    = $otrs['default']['queue'] ?? '';
        $this->priority = $otrs['default']['priority'] ?? '';
        $this->state    = $otrs['default']['state'] ?? '';
        $this->userID   = $otrs['default']['userID'] ?? '';
    }

    public function getUserId($username)
    {
        $url = $this->url;
        $post = [
            "username"  => $username,
            "function" => "getUserID",
            "apikey" => $this->key
        ];
        $postData = $this->urlPost($url, $post);
        if ($postData && isset($postData['response'])) {
            return trim($postData['response']);
        }
        return false;
    }

    public function CreateTicket($data)
    {
        if (isset($data['type'])) $this->type = $data['type'];
        if (isset($data['queue'])) $this->queue = $data['queue'];
        if (isset($data['state'])) $this->state = $data['state'];
        if (isset($data['priority'])) $this->priority = $data['priority'];
        if (isset($data['userID'])) $this->userID = $data['userID'];

        if (empty($data['title'])) {
            $msg = "No title given for ticket creation";
            $this->log($msg);
            return ['error' => $msg];
        }

        if (empty($this->url)) {
            $msg = "OTRS API URL is not configured in system settings";
            $this->log($msg);
            return ['error' => $msg];
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

        $this->log("Initiating Ticket Creation", ['url' => $this->url, 'queue' => $this->queue, 'title' => $data['title']]);

        $res = $this->urlPost($this->url, $post);
        if ($res && isset($res['response']) && $res['http_code'] >= 200 && $res['http_code'] < 300) {
            $ticketData = json_decode($res['response']);
            if ($ticketData && isset($ticketData->ticketID)) {
                $data['ticketid'] = $ticketData->ticketID;
                $this->ticketid   = $ticketData->ticketID;
                $data['ticketnr'] = $ticketData->ticketNum;
                $this->log("Ticket Created Successfully via OTRS API", ['ticket_id' => $data['ticketid'], 'ticket_nr' => $data['ticketnr']]);
                return $data;
            } else {
                $err = "OTRS API returned response without valid ticketID";
                $this->log($err, ['raw_response' => $res['response']]);
                return ['error' => $err, 'raw_response' => $res['response'], 'http_code' => $res['http_code']];
            }
        }

        $errMsg = "OTRS Ticket Creation API Request Failed";
        $this->log($errMsg, $res);
        return array_merge(['error' => $errMsg], is_array($res) ? $res : []);
    }

    public function createArticle($data)
    {
        if (isset($data['ArticleType'])) $this->ArticleType = $data['ArticleType'];
        if (isset($data['Ticketid'])) $this->ticketid = $data['Ticketid'];
        if (isset($data['userID'])) $this->userID = $data['userID'];

        if (empty($data['subject']) || empty($data['body'])) {
            $msg = "Subject or Body missing for article creation";
            $this->log($msg);
            return ['error' => $msg];
        }

        if (empty($this->url)) {
            $msg = "OTRS API URL is not configured in system settings";
            $this->log($msg);
            return ['error' => $msg];
        }

        $contentType = $data['ContentType'] ?? 'text/html; charset=utf-8';
        $body = $data['body'];
        if (strpos($contentType, 'html') !== false && strpos($body, '<html') === false) {
            $body = "<html><body>" . $body . "</body></html>";
        }

        $post = [
            'user'     => $this->userID,
            'function' => 'createArticle',
            'ticketID' => $this->ticketid,
            'subject'  => $data['subject'],
            'body'     => $body,
            'ContentType' => $contentType,
            'MimeType'    => (strpos($contentType, 'html') !== false) ? 'text/html' : 'text/plain',
            'Charset'     => 'utf-8',
            'type'     => $this->ArticleType,
            'comment'  => 'created by Whiteboard',
            'from'     => 'Whiteboard',
            'apikey'   => $this->key
        ];

        $res = $this->urlPost($this->url, $post);
        if ($res && isset($res['response']) && $res['http_code'] >= 200 && $res['http_code'] < 300) {
            $this->log("Article Created Successfully via OTRS API", ['ticket_id' => $this->ticketid]);
            $data['articleid'] = "notused";
            return $data;
        }

        $errMsg = "OTRS Article Creation API Request Failed";
        $this->log($errMsg, $res);
        return array_merge(['error' => $errMsg], is_array($res) ? $res : []);
    }

    public function getLastDebug() {
        return $this->lastDebug;
    }

    private function urlPost($url, $data) {
        if (empty($url)) {
            return ['error' => 'URL is empty', 'http_code' => 0];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        $debugInfo = [
            'url' => $url,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'response' => $response
        ];

        $this->lastDebug = $debugInfo;

        if ($curlErrno) {
            $this->log("cURL Transport Error", $debugInfo);
            return $debugInfo;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log("HTTP Status Error Code: $httpCode", $debugInfo);
        }

        return $debugInfo;
    }

    private function log($message, $data = null) {
        $logFile = __DIR__ . '/../event_manager.log';
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [OTRSClient] $message";
        if ($data) {
            $entry .= " | " . json_encode($data);
        }
        @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    }
}
