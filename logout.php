<?php
require_once 'inc/config.php';
require_once 'classes/AzureADSSO.php';
require_once 'classes/Auth.php';

$auth = new Auth($config);
$auth->logout();
header("Location: /login.php");
exit();
