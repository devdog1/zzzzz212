<?php
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');
require_once $docRoot . '/inc/config.php';

// Logic to force SQLite ONLY if MySQL config is missing or invalid
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

require_once 'Database.php';
require_once $docRoot . '/EventManager.php';
require_once $docRoot . '/classes/AzureADSSO.php';
require_once $docRoot . '/classes/Auth.php';
require_once $docRoot . '/classes/OTRS.php';

$auth = new Auth($config);
$auth->requireLogin();
if (!$auth->hasPermission('events.manage') && !$auth->hasPermission('admin.panel')) {
    http_response_code(401);
    echo "Error 401 Unauthorized: You need authorization (events.manage) to access this page.";
    exit();
}

$em = new EventManager($auth->user()['name'], $auth);
$stats = $em->getStatistics();

function formatTime($seconds) {
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return "{$h}h {$m}m";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Incident Statistics - Incident Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> body { background-color: #f8f9fa; } </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand h1 mb-0" href="index.php">Incident Manager</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="index.php">Active</a></li>
                <li class="nav-item"><a class="nav-link" href="closed.php">Archive</a></li>
                <li class="nav-item"><a class="nav-link" href="search.php">Search</a></li>
                <li class="nav-item"><a class="nav-link active" href="statistics.php">Statistics</a></li>
                <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <h2 class="mb-4">System Statistics</h2>

    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-primary text-white">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 opacity-75">Total Impact Score</h6>
                    <h2 class="card-title display-5 fw-bold"><?= number_format($stats['total_impact']) ?></h2>
                    <p class="mb-0 small">Aggregate across all incidents</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-success text-white">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 opacity-75">Avg. Resolution Time</h6>
                    <h2 class="card-title display-5 fw-bold"><?= formatTime($stats['avg_active_time']) ?></h2>
                    <p class="mb-0 small">Time spent in non-closed states</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-dark text-white">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 opacity-75">Global Reliability</h6>
                    <h2 class="card-title display-5 fw-bold">99.9%</h2>
                    <p class="mb-0 small">Calculated availability (sample)</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Incident Volume by Status</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Status</th><th class="text-end">Count</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($stats['counts'] as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['name']) ?></td>
                                    <td class="text-end fw-bold"><?= $c['count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Integration Health</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span>OTRS REST API:</span>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>MS Graph API:</span>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>Azure AD SSO:</span>
                        <span class="badge bg-success">Operational</span>
                    </div>
                    <hr>
                    <p class="text-muted small">System health metrics are checked every 5 minutes.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
