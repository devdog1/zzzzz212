<?php
require_once __DIR__ . "/autoload.php";
require_once 'inc/config.php';
$auth = new Auth($config);
if ($auth->handleCallback()) {
    $returnUrl = $_SESSION['auth_return_url'] ?? '/index.php';
    unset($_SESSION['auth_return_url']);
    header("Location: " . $returnUrl);
} else {
    echo "Authentication failed. <a href='/login.php'>Try again</a>";
}
exit();
