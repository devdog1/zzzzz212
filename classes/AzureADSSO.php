<?php

class AzureADSSO
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $tenantId;
    private $authUrl;
    private $tokenUrl;
    private $scopes = "openid profile email offline_access Group.Read.All Chat.Create Chat.ReadWrite Chat.ReadWrite.All GroupMember.Read.All";
    private $logoutUrl = "https://login.microsoftonline.com/{tenant}/oauth2/v2.0/logout";

    public function __construct($clientId, $clientSecret, $redirectUri, $tenantId = 'common')
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->tenantId = $tenantId;

        $this->authUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize";
        $this->tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
        $this->logoutUrl = str_replace("{tenant}", $tenantId, $this->logoutUrl);
    }

    public function getAuthUrl($state)
    {
        $queryParams = http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'response_mode' => 'query',
            'scope'         => $this->scopes,
            'state'         => $state,
        ]);

        return $this->authUrl . '?' . $queryParams;
    }

    public function getAccessToken($authorizationCode)
    {
        $postFields = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $authorizationCode,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => $this->scopes,
        ];

        return $this->makePostRequest($this->tokenUrl, $postFields);
    }

    public function getUserInfo($idToken)
    {
        list($header, $payload, $signature) = explode(".", $idToken);
        $decodedPayload = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload));
        return json_decode($decodedPayload, true);
    }

    public function getLogoutUrl($postLogoutRedirectUri)
    {
        return $this->logoutUrl . '?post_logout_redirect_uri=' . urlencode($postLogoutRedirectUri);
    }

    public function getUserGroups($accessToken)
    {
        $response = $this->makeGetRequest("https://graph.microsoft.com/v1.0/me/memberOf", $accessToken);
        if ($response) {
            $groupNames = [];
            foreach ($response['value'] as $group) {
                if (isset($group['displayName'])) {
                    $groupNames[] = $group['displayName'];
                }
            }
            return $groupNames;
        }
        return [];
    }

    public function getAllGroups($accessToken) {
        $response = $this->makeGetRequest("https://graph.microsoft.com/v1.0/groups", $accessToken);
        if ($response && isset($response['value'])) {
            return $response['value'];
        }
        return [];
    }

    public function createChat($accessToken, $topic, $userOids)
    {
        $members = [];
        foreach ($userOids as $oid) {
            $members[] = [
                '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                'roles' => ['owner'],
                'user@odata.bind' => "https://graph.microsoft.com/v1.0/users/$oid"
            ];
        }

        $body = [
            'chatType' => 'group',
            'topic' => $topic,
            'members' => $members
        ];

        $this->log("Attempting to create Teams chat", ['topic' => $topic, 'member_count' => count($userOids)]);
        return $this->makePostRequestJson("https://graph.microsoft.com/v1.0/chats", $body, $accessToken);
    }

    public function addMembersToChat($accessToken, $chatId, $userOids)
    {
        foreach ($userOids as $oid) {
            $body = [
                '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                'roles' => ['member'],
                'user@odata.bind' => "https://graph.microsoft.com/v1.0/users/$oid"
            ];
            $this->makePostRequestJson("https://graph.microsoft.com/v1.0/chats/$chatId/members", $body, $accessToken);
        }
        return true;
    }

    public function sendMessageToChat($accessToken, $chatId, $message) {
        $body = [
            'body' => [
                'content' => $message
            ]
        ];
        return $this->makePostRequestJson("https://graph.microsoft.com/v1.0/chats/$chatId/messages", $body, $accessToken);
    }

    public function getGroupMembers($accessToken, $groupId) {
        $response = $this->makeGetRequest("https://graph.microsoft.com/v1.0/groups/$groupId/members", $accessToken);
        if ($response && isset($response['value'])) {
            return $response['value'];
        }
        return [];
    }

    private function log($message, $data = null) {
        $logFile = __DIR__ . '/../teams_integration.log';
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] $message";
        if ($data) {
            $entry .= " | Data: " . json_encode($data);
        }
        file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
        error_log($entry);
    }

    private function makeGetRequest($url, $accessToken) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        $this->log("GET Request Failed", [
            'url' => $url,
            'httpCode' => $httpCode,
            'response' => $response
        ]);
        return null;
    }

    private function makePostRequestJson($url, $body, $accessToken) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        $this->log("POST JSON Request Failed", [
            'url' => $url,
            'httpCode' => $httpCode,
            'body' => $body,
            'response' => $response
        ]);
        return null;
    }

    private function makePostRequest($url, $postFields)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            return json_decode($response, true);
        }

        $this->log("POST Request Failed (Form Data)", [
            'url' => $url,
            'httpCode' => $httpCode,
            'response' => $response
        ]);
        return null;
    }
}
