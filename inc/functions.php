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
