<?php
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

$configPath = $docRoot . '/inc/config.php';
$hasConfig = file_exists($configPath);
$config = [];
if ($hasConfig) {
    require $configPath;
}

$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

require_once 'Database.php';
require_once $docRoot . '/EventManager.php';
require_once $docRoot . '/classes/AzureADSSO.php';
require_once $docRoot . '/classes/Auth.php';
require_once $docRoot . '/classes/OTRS.php';

$currentUser = 'Demo User';
if (!$useSqlite) {
    try {
        $auth = new Auth($config);
        $auth->requireLogin();
        $currentUser = $auth->user()['name'] ?? 'Unknown User';
    } catch (PDOException $e) {
        putenv('USE_SQLITE=true');
        $useSqlite = true;
    }
}

if ($useSqlite) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user'] = ['name' => 'Demo User'];
}

$auth_obj = null;
if (!$useSqlite) {
    $auth_obj = new Auth($config);
    if (!$auth_obj->hasPermission('admin.panel')) {
        http_response_code(401);
        echo "Error 401 Unauthorized: You need authorization (admin.panel) to access this page.";
        exit();
    }
}
$em = new EventManager($currentUser, $auth_obj);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_defaults') {
        foreach ($_POST['settings'] as $key => $value) {
            $em->updateDefault($key, $value);
        }
        $message = "Settings updated successfully.";
    }
}

$defaults = $em->getDefaults();
$azureGroups = [];

if (!$useSqlite && $auth_obj) {
    $token = $auth_obj->getAccessToken();
    if ($token) {
        $azureGroups = $auth_obj->getSSO()->getAllGroups($token);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Settings - Incident Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand h1 mb-0" href="index.php">Incident Manager</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="index.php">Active Incidents</a></li>
                <li class="nav-item"><a class="nav-link" href="closed.php">Closed Archive</a></li>
                <li class="nav-item"><a class="nav-link" href="departments.php">Departments</a></li>
                <li class="nav-item"><a class="nav-link active" href="settings.php">Settings</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Global Defaults & Integration Settings</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_defaults">

                        <?php foreach ($defaults as $d): ?>
                            <div class="mb-4">
                                <label class="form-label fw-bold"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($d['setting_key']))) ?></label>
                                <div class="text-muted small mb-2"><?= htmlspecialchars($d['description']) ?></div>

                                <?php if ($d['setting_key'] === 'always_include_azure_group_id'): ?>
                                    <?php if (!empty($azureGroups)): ?>
                                        <select name="settings[<?= $d['setting_key'] ?>]" class="form-select">
                                            <option value="">-- None --</option>
                                            <?php foreach ($azureGroups as $g): ?>
                                                <option value="<?= htmlspecialchars($g['id']) ?>" <?= $d['setting_value'] == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['displayName']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" name="settings[<?= $d['setting_key'] ?>]" class="form-control" value="<?= htmlspecialchars($d['setting_value'] ?? '') ?>" placeholder="Enter GUID">
                                    <?php endif; ?>
                                <?php elseif ($d['setting_key'] === 'otrs_enabled'): ?>
                                    <select name="settings[<?= $d['setting_key'] ?>]" class="form-select">
                                        <option value="0" <?= $d['setting_value'] === '0' ? 'selected' : '' ?>>Disabled</option>
                                        <option value="1" <?= $d['setting_value'] === '1' ? 'selected' : '' ?>>Enabled</option>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="settings[<?= $d['setting_key'] ?>]" class="form-control" value="<?= htmlspecialchars($d['setting_value'] ?? '') ?>">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </form>
                </div>
            </div>

            <div class="mt-4">
                <h6>Audit History (Settings)</h6>
                <div class="audit-list overflow-auto" style="max-height: 250px;">
                    <?php
                    $audits = $em->getAuditTrail('defaults');
                    foreach ($audits as $audit):
                        $new = json_decode($audit['new_values'], true);
                    ?>
                        <div class="mb-2 p-2 bg-white border rounded small">
                            <div class="text-muted" style="font-size:0.7rem;"><?= $audit['timestamp'] ?> by <?= htmlspecialchars($audit['user']) ?></div>
                            <div>Updated <strong><?= htmlspecialchars($new['setting_key'] ?? 'N/A') ?></strong> to <code><?= htmlspecialchars($new['setting_value'] ?? 'NULL') ?></code></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
