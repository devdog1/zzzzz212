<?php

class NetBoxClient
{
    private $url;
    private $token;

    public function __construct(array $config)
    {
        $this->url = rtrim($config['api']['netbox']['url'] ?? '', '/');
        $this->token = $config['api']['netbox']['token'] ?? '';
    }

    public function searchCircuits($query)
    {
        $endpoint = "/api/circuits/circuits/?cid__ic=" . urlencode($query);
        $response = $this->urlGet($this->url . $endpoint);

        if ($response) {
            $data = json_decode($response, true);
            return $data['results'] ?? [];
        }
        return [];
    }

    public function getCircuitDetails($id)
    {
        $endpoint = "/api/circuits/circuits/" . intval($id) . "/";
        $response = $this->urlGet($this->url . $endpoint);

        if ($response) {
            return json_decode($response, true);
        }
        return null;
    }

    public function getTenantDetails($id)
    {
        $endpoint = "/api/tenancy/tenants/" . intval($id) . "/";
        $response = $this->urlGet($this->url . $endpoint);

        if ($response) {
            return json_decode($response, true);
        }
        return null;
    }

    private function urlGet($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Token ' . $this->token,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $this->log("CURL Error", ['error' => curl_error($ch), 'url' => $url]);
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300) {
            return $response;
        }

        $this->log("API Request Failed", ['url' => $url, 'http_code' => $httpCode, 'response' => $response]);
        return false;
    }

    private function log($message, $data = null)
    {
        $logFile = __DIR__ . '/../event_manager.log';
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [NetBoxClient] $message";
        if ($data) {
            $entry .= " | Data: " . json_encode($data);
        }
        file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    }
}
