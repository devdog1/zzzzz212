<?php /*
if(isset($_SESSION['username'])){
 if($_SESSION['username'] == 'laned' || $_SESSION['username'] == 'schwankek' || $_SESSION['username'] == 'schwankek.admin' || $_SESSION['username'] == 'laned.admin'){
 } }
else {
  error_reporting(0);
} */

function humanTime($time) {
        $time = time() - $time;
        $tokens = array (
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );
    foreach ($tokens as $unit => $text) {
        if ($time < $unit) continue;
        $numberOfUnits = floor($time / $unit);
        return $numberOfUnits.' '.$text.(($numberOfUnits>1)?'s':'');
    }
}
function humanTimeDiff($time) {
        $tokens = array (
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );
    foreach ($tokens as $unit => $text) {
        if ($time < $unit) continue;
        $numberOfUnits = floor($time / $unit);
        return $numberOfUnits.' '.$text.(($numberOfUnits>1)?'s':'');
    }
}

function localsFill($string, $updateMessage, $affectedService, $affectedArea, $impactLevel, $durationOfOutageTime, $customersImpacted, $eventType, $departmentInvestigating, $technicalDescription, $technicalUpdate, $ticketID) {
        $eventTypeCap = ucfirst($eventType);
    $aguments = array("%updateMessage%", "%service%", "%area%", "%impact%", "%time%", "%number%", "%event%", "%dept%", "%techDesc%", "%techUpdate%", "%ticketID%", "%eventCap%");
    $swiches = array($updateMessage, $affectedService, $affectedArea, $impactLevel, $durationOfOutageTime, $customersImpacted, $eventType, $departmentInvestigating, $technicalDescription, $technicalUpdate, $ticketID, $eventTypeCap);
    $string = str_replace($aguments, $swiches, $string);
    return $string;
}
function tonerUpdate($tonerID, $action, $amount, $location, $user, $currentAmount) {
        require '/var/www/dev/inc/config.php';
        $datetime = date('Y/m/d H:i:s');
        if($action == "Add"): $newAmount = $currentAmount + $amount;
        else: $newAmount = $currentAmount - $amount;
        endif;
        $dsn = 'mysql:dbname='.$dbname.';host='.$dbhost.';port=3306';
        try
        {
           $db = new PDO($dsn, $dbuser, $dbpass, array(
           PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
           ));
           $stm = "UPDATE tonerTypes SET current=".$newAmount." WHERE id=".$tonerID;
           $statement = $db->prepare($stm);
           $statement->execute();
           $_SESSION['success'][] = "Record Added";
           $stm = "INSERT INTO `tonerHistory` (`tonerID`, `action`, `amount`, `location`, `user`, `timeStamp`, `currentAmount`) VALUES('".$tonerID."','".$action."','".$amount."','".$location."','".$user."','".$datetime."','".$newAmount."')";
           $statement = $db->prepare($stm);
           $statement->execute();
           $count = $statement->rowCount();
           if($count >= 1): $_SESSION['success'][] = $count." affected rows";
           endif;;
        }
        catch(PDOException $error)
        {
           $_SESSION['error'][] = $error;
        }
        return "Toner has been added";
}
function tonerCreate($printer, $toner, $min, $max, $current, $ordering = NULL) {
        require 'config.php';
        $dsn = 'mysql:dbname='.$dbname.';host='.$dbhost.';port=3306';
        try
        {
           $db = new PDO($dsn, $dbuser, $dbpass, array(
           PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
           ));
           $stm ="INSERT INTO `tonerTypes` (`printer`, `toner`, `min`, `max`, `current`, `orderingInfo`) VALUES('".$printer."','".$toner."','".$min."','".$max."','".$current."','".$ordering."')";
           $statement = $db->prepare($stm);
           $statement->execute();
           $count = $statement->rowCount();
           if($count >= 1): $_SESSION['success'][] = $count." affected rows";
           endif;;
        }
        catch(PDOException $error)
        {
           $_SESSION['error'][] = $error;
        }
        return "Toner has been added";
}


/**
 * Redirect with POST data.
 *
 * @param string $url URL.
 * @param array $post_data POST data. Example: array('foo' => 'var', 'id' => 123)
 * @param array $headers Optional. Extra headers to send.
 */
function redirect_post($url, array $data, array $headers = null) {
    $params = array(
        'http' => array(
            'method' => 'POST',
            'content' => http_build_query($data)
        )
    );
    if (!is_null($headers)) {
        $params['http']['header'] = '';
        foreach ($headers as $k => $v) {
            $params['http']['header'] .= "$k: $v\n";
        }
    }
    $ctx = stream_context_create($params);
    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp) {
        echo @stream_get_contents($fp);
        die();
    } else {
        // Error
        throw new Exception("Error loading '$url', $php_errormsg");
    }
}

function getImpact ($customersImpacted) {
 if($customersImpacted > 0 && $customersImpacted <= 1): $impactLevel = 'Individual';
 elseif($customersImpacted >= 2 && $customersImpacted <= 9): $impactLevel = 'Minor';
 elseif($customersImpacted >= 10 && $customersImpacted <= 249): $impactLevel = 'Major';
 elseif($customersImpacted >= 250): $impactLevel = 'Critical';
 else: $impactLevel = 'Undefined';  // If the user enters 0 or something strange happens
 endif;
 if(isset($impactLevel)) return $impactLevel;
 else return "Undefined";
}

function bsAuth($tokenId, $tokenSecret)
{
    return base64_encode($tokenId . ":" . $tokenSecret);
}

function extractValue($block, $tag)
{
    if (preg_match('/<' . preg_quote($tag) . '[^>]*>(.*?)<\/' . preg_quote($tag) . '>/s', $block, $m)) {
        return trim($m[1]);
    }
    return "";
}

function parseChannels($text)
{
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

function generateSession($channel, $serverConfig = null)
{
    global $config;
    $out = $channel["out"] ?: "232.0.0.1";
    $localaddr = $serverConfig['localaddr'] ?? ($config['localaddr'] ?? '127.0.0.1');

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
            "region" => $config['default_region'] ?? 'us-east-1',
            "urls" => [
                "udp://{$channel["address"]}:{$channel["port"]}?sources={$channel["a"]}&localaddr={$localaddr}",
                "udp://{$channel["address"]}:{$channel["port"]}?sources={$channel["b"]}&localaddr={$localaddr}"
            ]
        ]],
        "name" => $channel["name"],
        "playbacks" => [
            [
                "output_name" => $channel["name"] . "-web",
                "template_id" => $config['default_template_id'] ?? 'default',
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
            ],
            [
                "output_name" => $channel["name"] . "-udp",
                "template_id" => $config['default_template_id'] ?? 'default',
                "output_type" => "multicast",
                "mpegts_settings" => [
                    "enable" => true,
                    "program_number" => 1
                ],
                "stream_remap" => [
                    "enabled" => true,
                    "stream_mappings" => [
                        [
                            "order" => "0",
                            "type" => "PMT",
                            "lang" => "*",
                            "input_pid" => "*",
                            "codec" => "*",
                            "mode" => "remap",
                            "output_pid" => "1906"
                        ],
                        [
                            "order" => "1",
                            "type" => "video",
                            "lang" => "*",
                            "input_pid" => "*",
                            "codec" => "*",
                            "mode" => "remap",
                            "output_pid" => "400"
                        ],
                        [
                            "order" => "2",
                            "type" => "audio",
                            "lang" => "*",
                            "input_pid" => "*",
                            "codec" => "aac",
                            "mode" => "remap",
                            "output_pid" => "483"
                        ],
                        [
                            "order" => "3",
                            "type" => "audio",
                            "lang" => "*",
                            "input_pid" => "*",
                            "codec" => "ac3",
                            "mode" => "remap",
                            "output_pid" => "482"
                        ],
                        [
                            "order" => "#",
                            "type" => "*",
                            "lang" => "*",
                            "input_pid" => "*",
                            "codec" => "*",
                            "mode" => "drop",
                            "output_pid" => "*"
                        ]
                    ]
                ],
                "output_urls" => [[
                    "region" => $config['default_region'] ?? 'us-east-1',
                    "urls" => [
                        "udp://{$out}:3001?localaddr={$localaddr}",
                        "udp://{$out}:3002?localaddr={$localaddr}",
                        "udp://{$out}:3003?localaddr={$localaddr}",
                        "udp://{$out}:3004?localaddr={$localaddr}",
                        "udp://{$out}:3005?localaddr={$localaddr}"
                    ]
                ]]
            ]
        ],
        "regions" => [
            $config['default_region'] ?? 'us-east-1'
        ],
        "srt_passphrase" => ""
    ];

    return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function apiCall($url, $tokenId, $tokenSecret, $method = 'GET', $payload = null)
{
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

function fetchAllStreams($baseUrl, $tokenId, $tokenSecret)
{
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

function getIncaStreams($address, $community)
{
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

function fetchIncaBackup($address, $user, $pass)
{
    $url = "http://{$address}/sys/svc/core/api/v1/devices/1/configuration.bak";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$user}:{$pass}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? $response : null;
}

function incaRawCall($address, $path, $user, $pass)
{
    $url = "http://{$address}/sys/svc/core/api/v1{$path}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$user}:{$pass}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function incaApiCall($address, $path, $user, $pass)
{
    $url = "http://{$address}/sys/svc/core/api/v1{$path}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$user}:{$pass}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? json_decode($response, true) : null;
}
