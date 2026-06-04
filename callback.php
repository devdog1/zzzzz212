<?php
require_once 'inc/config.php';
require_once 'classes/AzureADSSO.php';
require_once 'classes/Auth.php';

$auth = new Auth($config);
if ($auth->handleCallback()) {
    header("Location: /index.php");
} else {
    echo "Authentication failed. <a href='/login.php'>Try again</a>";
}
exit();
