<?php
require_once 'inc/config.php';
// Mock session for demo environment
if (getenv('USE_SQLITE') === 'true') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 1;
        $_SESSION['user'] = ['name' => 'Demo User'];
    }
}

require_once 'classes/AzureADSSO.php';
require_once 'classes/Auth.php';
require_once 'EventManager.php';

// Initialize Authentication
$auth = new Auth($config);

// Force login (skipping in this environment to allow demonstration)
if (getenv('USE_SQLITE') !== 'true') {
    $auth->requireLogin();
}

$currentUser = $auth->user()['name'] ?? 'Demo User';

// Force SQLite for this demo environment
putenv('USE_SQLITE=true');
if (!file_exists('event_mgmt.sqlite') || filesize('event_mgmt.sqlite') < 1000) {
    $db = new PDO("sqlite:event_mgmt.sqlite");
    $sql = file_get_contents('schema_sqlite.sql');
    $db->exec($sql);

    // Seed data
    $em = new EventManager('system_seed');
    $em->createDepartment('IT Support');
    $em->createDepartment('Network Operations');
    $em->createType('Outage');
    $em->createType('Degradation');
    $em->createState('Identified');
    $em->createState('Monitoring');
    $em->createState('Resolved');
    $em->createService('Authentication Provider');
    $em->createService('Internal API');
    $em->createService('Main Website');
}

$em = new EventManager($currentUser);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_event') {
            $em->createEvent($_POST);
        } elseif ($_POST['action'] === 'add_update') {
            $em->addEventUpdate($_POST['event_id'], $_POST['update_text']);
        } elseif ($_POST['action'] === 'change_state') {
            $em->updateEvent($_POST['event_id'], ['state_id' => $_POST['state_id']]);
        }
    }
}

$events = $em->listEvents();
$departments = $em->listDepartments();
$types = $em->listTypes();
$states = $em->listStates();
$services = $em->listServices();

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
    <title>Event Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .update-entry { border-left: 3px solid #0d6efd; padding-left: 10px; margin-bottom: 8px; }
        .update-time { font-size: 0.75rem; color: #6c757d; }
        .state-duration { font-size: 0.75rem; color: #198754; font-weight: bold; }
        .tag-badge { font-size: 0.75rem; cursor: default; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <span class="navbar-brand mb-0 h1">Incident Manager</span>
        <div class="navbar-text text-white">
            Logged in as: <strong><?= htmlspecialchars($currentUser) ?></strong>
        </div>
    </div>
</nav>

<div class="container">
    <div class="row">
        <!-- Event Creation Form -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">New Incident</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_event">
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select name="type_id" class="form-select">
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select">
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Affected Services (Parent Services)</label>
                            <select name="service_ids[]" class="form-select" multiple size="3">
                                <?php foreach ($services as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Hold Ctrl/Cmd to select multiple.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tags (comma separated)</label>
                            <input type="text" name="tags" class="form-control" placeholder="network, urgent, api">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Initial State</label>
                            <select name="state_id" class="form-select">
                                <?php foreach ($states as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Report Incident</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Event List -->
        <div class="col-md-8">
            <h2 class="mb-3">Incidents</h2>
            <?php if (empty($events)): ?>
                <div class="alert alert-info">No incidents reported yet.</div>
            <?php else: ?>
                <?php foreach ($events as $e): ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>
                                <span class="badge bg-secondary me-2">ID: <?= $e['id'] ?></span>
                                <span class="badge bg-info text-dark"><?= htmlspecialchars($e['type_name']) ?></span>
                                <span class="badge bg-warning text-dark ms-2"><?= htmlspecialchars($e['state_name']) ?></span>
                            </span>
                            <small class="text-muted"><?= $e['create_time'] ?></small>
                        </div>
                        <div class="card-body">
                            <h6 class="card-subtitle mb-2 text-muted"><?= htmlspecialchars($e['department_name']) ?></h6>

                            <!-- Services and Tags -->
                            <div class="mb-2">
                                <?php foreach ($e['services'] as $svc): ?>
                                    <span class="badge bg-dark me-1">Service: <?= htmlspecialchars($svc['name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="mb-3">
                                <?php foreach ($e['tags'] as $tag): ?>
                                    <span class="badge rounded-pill bg-light text-dark border tag-badge me-1">#<?= htmlspecialchars($tag['name']) ?></span>
                                <?php endforeach; ?>
                            </div>

                            <p class="card-text fw-bold"><?= nl2br(htmlspecialchars($e['description'])) ?></p>

                            <!-- State History -->
                            <div class="mb-3">
                                <h6>State Timeline</h6>
                                <div class="d-flex flex-wrap">
                                    <?php
                                    $history = $em->getStateHistory($e['id']);
                                    foreach ($history as $h):
                                        $enter = strtotime($h['enter_time']);
                                        $exit = $h['exit_time'] ? strtotime($h['exit_time']) : time();
                                        $duration = $exit - $enter;
                                    ?>
                                        <div class="me-3 mb-2 border-start ps-2">
                                            <div class="small fw-bold"><?= htmlspecialchars($h['state_name']) ?></div>
                                            <div class="state-duration"><?= formatDuration($duration) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- State Change Form -->
                            <form method="POST" class="row g-2 mb-3 bg-light p-2 rounded border">
                                <input type="hidden" name="action" value="change_state">
                                <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                <div class="col-auto align-self-center"><small class="fw-bold">Switch State:</small></div>
                                <div class="col">
                                    <select name="state_id" class="form-select form-select-sm">
                                        <?php foreach ($states as $s): ?>
                                            <option value="<?= $s['id'] ?>" <?= $s['id'] == $e['state_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($s['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-sm btn-dark">Update State</button>
                                </div>
                            </form>

                            <hr>
                            <h6>Updates</h6>
                            <div class="update-list mb-3">
                                <?php
                                $updates = $em->getEventUpdates($e['id']);
                                foreach ($updates as $u): ?>
                                    <div class="update-entry">
                                        <div class="update-time"><?= $u['create_time'] ?> by <?= htmlspecialchars($u['create_user']) ?></div>
                                        <div><?= htmlspecialchars($u['update_text']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <form method="POST" class="row g-2">
                                <input type="hidden" name="action" value="add_update">
                                <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                <div class="col">
                                    <input type="text" name="update_text" class="form-control form-control-sm" placeholder="Add update..." required>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Add</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
