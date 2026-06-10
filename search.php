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
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET)) {
    $operator = $_GET['operator'] ?? 'AND';
    $results = $em->searchEvents($_GET, $operator);
}

$departments = $em->listDepartments();
$types = $em->listTypes();
$states = $em->listStates();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Search Incidents - Incident Manager</title>
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
                <li class="nav-item"><a class="nav-link active" href="search.php">Search</a></li>
                <li class="nav-item"><a class="nav-link" href="statistics.php">Statistics</a></li>
                <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Advanced Incident Search</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Incident ID</label>
                    <input type="number" name="id" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['id'] ?? '') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-bold">Description Keywords</label>
                    <input type="text" name="description" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['description'] ?? '') ?>" placeholder="e.g. outage, network...">
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-bold">Ticket #</label>
                    <input type="text" name="ticket_nr" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['ticket_nr'] ?? '') ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold">Department</label>
                    <select name="department_id" class="form-select form-select-sm">
                        <option value="">-- All --</option>
                        <?php foreach($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ($_GET['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Type</label>
                    <select name="type_id" class="form-select form-select-sm">
                        <option value="">-- All --</option>
                        <?php foreach($types as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($_GET['type_id'] ?? '') == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Status</label>
                    <select name="state_id" class="form-select form-select-sm">
                        <option value="">-- All --</option>
                        <?php foreach($states as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($_GET['state_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Logic Operator</label>
                    <select name="operator" class="form-select form-select-sm border-primary text-primary">
                        <option value="AND" <?= ($_GET['operator'] ?? 'AND') === 'AND' ? 'selected' : '' ?>>Match ALL (AND)</option>
                        <option value="OR" <?= ($_GET['operator'] ?? '') === 'OR' ? 'selected' : '' ?>>Match ANY (OR)</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold">From Date</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">To Date</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Perform Search</button>
                    <a href="search.php" class="btn btn-outline-secondary ms-2">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($results !== null): ?>
        <h4 class="mb-3">Results (<?= count($results) ?> found)</h4>
        <?php if (empty($results)): ?>
            <div class="alert alert-info">No incidents matched your criteria.</div>
        <?php else: ?>
            <div class="table-responsive bg-white shadow-sm border rounded">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Created</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Department</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($results as $e): ?>
                            <tr>
                                <td><a href="index.php?id=<?= $e['id'] ?>" class="badge bg-secondary text-decoration-none">#<?= $e['id'] ?></a></td>
                                <td><small><?= $e['create_time'] ?></small></td>
                                <td><?= htmlspecialchars($e['type_name']) ?></td>
                                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($e['state_name']) ?></span></td>
                                <td><?= htmlspecialchars($e['department_name']) ?></td>
                                <td class="text-truncate" style="max-width: 300px;"><?= htmlspecialchars($e['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
