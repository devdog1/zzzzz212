<?php
// Helper functions for Incident Management Plugin

if (!function_exists('humanTime')) {
    function humanTime($time) {
        $time = time() - $time;
        $tokens = [
            31536000 => 'year',
            2592000  => 'month',
            604800   => 'week',
            86400    => 'day',
            3600     => 'hour',
            60       => 'minute',
            1        => 'second'
        ];
        foreach ($tokens as $unit => $text) {
            if ($time < $unit) continue;
            $numberOfUnits = floor($time / $unit);
            return $numberOfUnits . ' ' . $text . (($numberOfUnits > 1) ? 's' : '');
        }
        return '0 seconds';
    }
}

if (!function_exists('humanTimeDiff')) {
    function humanTimeDiff($time) {
        return humanTime($time);
    }
}

if (!function_exists('getImpact')) {
    function getImpact($customersImpacted) {
        if ($customersImpacted > 0 && $customersImpacted <= 1) return 'Individual';
        if ($customersImpacted >= 2 && $customersImpacted <= 9) return 'Minor';
        if ($customersImpacted >= 10 && $customersImpacted <= 249) return 'Major';
        if ($customersImpacted >= 250) return 'Critical';
        return "Undefined";
    }
}

if (!function_exists('localsFill')) {
    function localsFill($string, $updateMessage, $affectedService, $affectedArea, $impactLevel, $durationOfOutageTime, $customersImpacted, $eventType, $departmentInvestigating, $technicalDescription, $technicalUpdate, $ticketID) {
        $eventTypeCap = ucfirst($eventType);
        $aguments = ["%updateMessage%", "%service%", "%area%", "%impact%", "%time%", "%number%", "%event%", "%dept%", "%techDesc%", "%techUpdate%", "%ticketID%", "%eventCap%"];
        $swiches = [$updateMessage, $affectedService, $affectedArea, $impactLevel, $durationOfOutageTime, $customersImpacted, $eventType, $departmentInvestigating, $technicalDescription, $technicalUpdate, $ticketID, $eventTypeCap];
        return str_replace($aguments, $swiches, $string);
    }
}

if (!function_exists('bsAuth')) {
    function bsAuth($tokenId, $tokenSecret) {
        return base64_encode($tokenId . ":" . $tokenSecret);
    }
}

if (!function_exists('extractValue')) {
    function extractValue($block, $tag) {
        if (preg_match('/<' . preg_quote($tag) . '[^>]*>(.*?)<\/' . preg_quote($tag) . '>/s', $block, $m)) {
            return trim($m[1]);
        }
        return "";
    }
}

if (!function_exists('parseChannels')) {
    function parseChannels($text) {
        $channels = [];
        preg_match_all('/<dn>(.*?)<\/dn>(.*?)(?=<dn>|$)/s', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fullName = trim($match[1]);
            $block = $match[0];

            if (!str_contains($fullName, "1680") && !str_contains($fullName, "1681")) {
                continue;
            }

            $name = preg_replace('/\s*-\s*168[01]/', '', $fullName);

            if (!isset($channels[$name])) {
                $channels[$name] = [
                    "name" => $name,
                    "address" => extractValue($block, "address"),
                    "port" => extractValue($block, "port"),
                    "a" => "",
                    "b" => "",
                    "out" => ""
                ];
            }

            $ssm = extractValue($block, "ssm_address");

            if (str_contains($fullName, "1680")) {
                $channels[$name]["a"] = $ssm;
            }

            if (str_contains($fullName, "1681")) {
                $channels[$name]["b"] = $ssm;
            }

            if (preg_match('/232\.\d+\.\d+\.\d+/', $block, $out)) {
                $channels[$name]["out"] = $out[0];
            }
        }

        return $channels;
    }
}

if (!function_exists('generateSession')) {
    function generateSession($channel, $serverConfig = null) {
        $out = $channel["out"] ?: "232.0.0.1";
        $localaddr = $serverConfig['localaddr'] ?? '127.0.0.1';

        $json = [
            "capture_card_input" => [
                "id" => "",
                "port" => "",
                "protocol" => "",
                "format" => "",
                "bit_depth" => 0
            ],
            "cover_image_url" => "",
            "description" => $channel["name"],
            "enable_failover" => true,
            "enable_hls_scte35_passthrough" => false,
            "enable_scte104_to_35" => false,
            "enable_slates" => false,
            "enable_srt_encryption" => false,
            "failover_error_threshold_percent" => 3,
            "failover_recovery_interval_seconds" => -1,
            "input_type" => "multicast_pull",
            "input_urls" => [[
                "region" => 'us-east-1',
                "urls" => [
                    "udp://{$channel["address"]}:{$channel["port"]}?sources={$channel["a"]}&localaddr={$localaddr}",
                    "udp://{$channel["address"]}:{$channel["port"]}?sources={$channel["b"]}&localaddr={$localaddr}"
                ]
            ]],
            "name" => $channel["name"],
            "playbacks" => [
                [
                    "output_name" => $channel["name"] . "-web",
                    "template_id" => 'default',
                    "output_type" => "http",
                    "http_settings" => [
                        "visibility" => "public",
                        "enable_hls" => true,
                        "enable_dash" => true,
                        "recording_settings" => [
                            "enabled" => false,
                            "base_path" => ""
                        ]
                    ]
                ]
            ],
            "regions" => ['us-east-1'],
            "srt_passphrase" => ""
        ];

        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('apiCall')) {
    function apiCall($url, $tokenId, $tokenSecret, $method = 'GET', $payload = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_POSTREDIR, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $headers = ["Authorization: Basic " . bsAuth($tokenId, $tokenSecret)];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $headers[] = "Content-Type: application/json";
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            if ($payload) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $headers[] = "Content-Type: application/json";
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'response' => $code === 0 ? curl_error($ch) : $response];
    }
}

if (!function_exists('fetchAllStreams')) {
    function fetchAllStreams($baseUrl, $tokenId, $tokenSecret) {
        $allStreams = [];
        $page = 1;
        $limit = 25;

        while (true) {
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            $url = "{$baseUrl}{$separator}page={$page}&limit={$limit}";
            $result = apiCall($url, $tokenId, $tokenSecret);

            if ($result['code'] < 200 || $result['code'] >= 300) break;

            $data = json_decode($result['response'], true);
            $list = $data['data']['list'] ?? [];
            $total = $data['data']['total'] ?? 0;

            if (empty($list)) break;
            foreach ($list as $item) $allStreams[] = $item;
            if (count($allStreams) >= $total || count($list) < $limit) break;
            $page++;
            if ($page > 1000) break;
        }
        return $allStreams;
    }
}

if (!function_exists('getIncaStreams')) {
    function getIncaStreams($address, $community) {
        if (!function_exists('snmp2_real_walk')) return [];
        $streams = [];
        $oid_base = ".1.3.6.1.4.1.39938.2.1.1.1.1";
        $descrs = @snmp2_real_walk($address, $community, "{$oid_base}.3");
        if (!$descrs) return [];

        foreach ($descrs as $oid => $name) {
            $parts = explode('.', $oid);
            $index = array_pop($parts);
            $direction = array_pop($parts);
            if ($direction != "2") continue;
            $name = trim(preg_replace('/^[A-Z0-9-]+: /i', '', $name), '" ');
            if (preg_match('/.*test.*|.*xcode.*/i', $name)) continue;
            $bitrate = @snmp2_get($address, $community, "{$oid_base}.6.{$direction}.{$index}");
            $bitrate = trim(preg_replace('/^[A-Z0-9-]+: /i', '', $bitrate));
            $errors = @snmp2_get($address, $community, "{$oid_base}.8.{$direction}.{$index}");
            $errors = trim(preg_replace('/^[A-Z0-9-]+: /i', '', $errors));
            $streams[] = [
                'stream_id' => "inca_{$direction}_{$index}",
                'name' => $name,
                'status' => 'inca_active',
                'bitrate' => $bitrate,
                'errors' => $errors,
                'type' => 'inca'
            ];
        }
        return $streams;
    }
}
