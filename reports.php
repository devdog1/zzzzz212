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
$reportType = $_GET['type'] ?? 'dept_impact';
$dateFrom   = $_GET['date_from'] ?? null;
$dateTo     = $_GET['date_to'] ?? null;

$reportData = $em->getReportData($reportType, $dateFrom, $dateTo);

$reportNames = [
    'dept_impact'    => 'Impact Score by Department',
    'type_freq'      => 'Incident Frequency by Type',
    'service_impact' => 'Impact Score by Affected Service',
    'location'       => 'Incidents by Location (Area)',
    'tag_usage'      => 'Tag Popularity'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports - Incident Manager</title>
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
                <li class="nav-item"><a class="nav-link" href="statistics.php">Statistics</a></li>
                <li class="nav-item"><a class="nav-link active" href="reports.php">Reports</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="type" value="<?= htmlspecialchars($reportType) ?>">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark btn-sm w-100">Filter Report</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="list-group shadow-sm border-0">
                <div class="list-group-item list-group-item-dark fw-bold">Available Reports</div>
                <?php foreach($reportNames as $key => $name): ?>
                    <a href="reports.php?type=<?= $key ?>" class="list-group-item list-group-item-action <?= $reportType === $key ? 'active' : '' ?>">
                        <?= $name ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="card mt-4 shadow-sm border-0">
                <div class="card-body small">
                    <h6>Export Options</h6>
                    <button class="btn btn-sm btn-outline-secondary w-100 mb-2" disabled>Export to CSV</button>
                    <button class="btn btn-sm btn-outline-secondary w-100" disabled>Generate PDF</button>
                </div>
            </div>
        </div>

        <div class="col-md-9">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?= $reportNames[$reportType] ?></h5>
                    <span class="badge bg-secondary"><?= count($reportData) ?> Rows</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($reportData)): ?>
                        <div class="p-4 text-center text-muted">No data available for this report.</div>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Category</th>
                                    <th class="text-end">Value</th>
                                    <th style="width: 40%">Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $max = 0;
                                foreach($reportData as $row) { if ($row['value'] > $max) $max = $row['value']; }
                                foreach($reportData as $row):
                                    $pct = $max > 0 ? ($row['value'] / $max) * 100 : 0;
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['label']) ?></td>
                                        <td class="text-end fw-bold"><?= number_format($row['value']) ?></td>
                                        <td>
                                            <div class="progress" style="height: 10px;">
                                                <div class="progress-bar bg-primary" style="width: <?= $pct ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
