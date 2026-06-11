<?php
require_once __DIR__ . "/autoload.php";
require_once 'inc/config.php';
$auth = new Auth($config);
$auth->logout();
header("Location: /login.php");
exit();
