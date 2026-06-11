<?php
require_once __DIR__ . "/autoload.php";
require_once 'inc/config.php';
$auth = new Auth($config);
$auth->login($_GET['return_url'] ?? null);
