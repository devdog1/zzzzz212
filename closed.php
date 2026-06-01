<?php
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

$configPath = $docRoot . '/inc/config.php';
$hasConfig = file_exists($configPath);
if ($hasConfig) {
    require_once $configPath;
}

// Logic to force SQLite ONLY if MySQL config is missing
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

require_once $docRoot . '/classes/AzureADSSO.php';
require_once $docRoot . '/classes/Auth.php';
require_once $docRoot . '/EventManager.php';

$currentUser = 'Demo User';
if (!$useSqlite && $hasConfig) {
    $auth = new Auth($config);
    $auth->requireLogin();
    $currentUser = $auth->user()['name'] ?? 'Unknown User';
} else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user'] = ['name' => 'Demo User'];
}

$em = new EventManager($currentUser);

$events = $em->listEvents(true); // Show closed only
$events = array_filter($events, function($e) { return strtolower($e['state_name']) === 'closed'; });

function formatDuration($seconds) {
    if ($seconds < 60) return $seconds . "s";
    if ($seconds < 3600) return floor($seconds / 60) . "m";
    return floor($seconds / 3600) . "h " . floor(($seconds % 3600) / 60) . "m";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Closed Archive - Incident Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .update-entry { border-left: 3px solid #6c757d; padding-left: 10px; margin-bottom: 8px; }
        .update-time { font-size: 0.75rem; color: #6c757d; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand h1 mb-0" href="index.php">Incident Manager</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="index.php">Active Incidents</a></li>
                <li class="nav-item"><a class="nav-link active" href="closed.php">Closed Archive</a></li>
            </ul>
        </div>
        <div class="navbar-text text-white">
            Logged in as: <strong><?= htmlspecialchars($currentUser) ?></strong>
        </div>
    </div>
</nav>

<div class="container">
    <h2 class="mb-4">Closed Incident Archive</h2>

    <?php if (empty($events)): ?>
        <div class="alert alert-secondary">No closed incidents found in the archive.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover bg-white shadow-sm rounded border">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Resolution Time</th>
                        <th>Type</th>
                        <th>Dept</th>
                        <th>Description</th>
                        <th>Impact Score</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $e):
                        $history = $em->getStateHistory($e['id']);
                        $lastState = end($history);
                    ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= $e['id'] ?></span></td>
                            <td><small><?= $lastState['enter_time'] ?? $e['update_time'] ?></small></td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($e['type_name']) ?></span></td>
                            <td><?= htmlspecialchars($e['department_name']) ?></td>
                            <td><?= htmlspecialchars(substr($e['description'], 0, 50)) ?>...</td>
                            <td><span class="badge bg-danger"><?= (int)$e['impactScore'] ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-<?= $e['id'] ?>">View Details</button>
                            </td>
                        </tr>

                        <!-- Details Modal -->
                        <div class="modal fade" id="modal-<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header border-0 bg-dark text-white">
                                        <h5 class="modal-title">Incident #<?= $e['id'] ?> Details</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <h6>Description</h6>
                                        <p class="fw-bold"><?= nl2br(htmlspecialchars($e['description'])) ?></p>
                                        <hr>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <small><strong>Area:</strong> <?= htmlspecialchars($e['area_affected'] ?? 'N/A') ?></small><br>
                                                <small><strong>Customers:</strong> <?= (int)$e['customers_affected'] ?></small>
                                            </div>
                                            <div class="col-md-6">
                                                <small><strong>Impact Score:</strong> <?= (int)$e['impactScore'] ?></small><br>
                                                <small><strong>Final State:</strong> Closed</small>
                                            </div>
                                        </div>

                                        <h6>State History</h6>
                                        <div class="d-flex flex-wrap mb-3">
                                            <?php foreach ($history as $h):
                                                $enter = strtotime($h['enter_time']);
                                                $exit = $h['exit_time'] ? strtotime($h['exit_time']) : time();
                                                $duration = $exit - $enter;
                                            ?>
                                                <div class="me-3 border-start ps-2 mb-2">
                                                    <div class="small fw-bold"><?= htmlspecialchars($h['state_name']) ?></div>
                                                    <div class="small text-success"><?= formatDuration($duration) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <h6>Updates</h6>
                                        <div class="update-list shadow-none">
                                            <?php
                                            $updates = $em->getEventUpdates($e['id']);
                                            foreach ($updates as $u): ?>
                                                <div class="update-entry small">
                                                    <div class="update-time"><?= $u['create_time'] ?> by <?= htmlspecialchars($u['create_user']) ?></div>
                                                    <div><?= htmlspecialchars($u['update_text']) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="modal-footer border-0">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
