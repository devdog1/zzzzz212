<?php

class AzureADSSO
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $tenantId;
    private $authUrl;
    private $tokenUrl;
    private $scopes = "openid profile email offline_access Group.Read.All";
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
        $graphUrl = "https://graph.microsoft.com/v1.0/me/memberOf";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $graphUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $groupsData = json_decode($response, true);
            $groupNames = [];

            // Extract group names from the response
            foreach ($groupsData['value'] as $group) {
                if (isset($group['displayName'])) {
                    $groupNames[] = $group['displayName'];
                }
            }

            return $groupNames;
        }

        return [];
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

        return null;
    }
}
