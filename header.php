<?php
require_once __DIR__ . "/autoload.php";
$configPath = __DIR__ . '/inc/config.php';
if (file_exists($configPath)) {
    require $configPath; // Assuming it defines $config
}
if (!isset($config)) {
    $config = [];
}

// Demo/SQLite logic
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

$auth = new Auth($config);
$auth->requireLogin();
$user = $auth->user();

$em = new EventManager($user['name'] ?? 'System', $auth);
$nav = new NavigationBuilder($em->db, $auth);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Incident Manager') ?></title>

    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body { background-color: #f8f9fa; }
        .navbar { margin-bottom: 2rem; }
    </style>
</head>
<body>

<?= $nav->render() ?>
