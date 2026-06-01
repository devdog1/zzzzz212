<?php
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

require_once $docRoot . '/inc/config.php';

// Check for demo mode (SQLite fallback)
$isDemo = (getenv('USE_SQLITE') === 'true');

// Identity Management Classes
require_once $docRoot . '/classes/AzureADSSO.php';
require_once $docRoot . '/classes/Auth.php';
require_once $docRoot . '/EventManager.php';

// Initialize Authentication (only if not in demo mode or if bypass is desired)
$currentUser = 'Demo User';
if (!$isDemo) {
    $auth = new Auth($config);
    $auth->requireLogin();
    $currentUser = $auth->user()['name'] ?? 'Unknown User';
} else {
    // Mock session for demo
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user'] = ['name' => 'Demo User'];
}

// Auto-seed for demo/test environments
if ($isDemo) {
    $dbFile = $docRoot . '/event_mgmt.sqlite';
    if (!file_exists($dbFile) || filesize($dbFile) < 1000) {
        $db = new PDO("sqlite:$dbFile");
        $sql = file_get_contents($docRoot . '/schema_sqlite.sql');
        $db->exec($sql);

        $em = new EventManager('system_seed');
        $em->createDepartment('IT Support');
        $em->createDepartment('Network Operations');
        $em->createType('Outage');
        $em->createType('Degradation');
        $em->createState('Identified');
        $em->createState('Monitoring');
        $em->createState('Resolved');
        $em->createState('Closed');
        $em->createService('Authentication Provider');
        $em->createService('Internal API');
        $em->createService('Main Website');
    }
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
        .counter-box { font-size: 0.85rem; color: #444; background: #eee; padding: 2px 8px; border-radius: 4px; margin-left: 5px; }
        .card-header { cursor: pointer; transition: background 0.2s; }
        .card-header:hover { background-color: #f0f0f0 !important; }
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
                <div class="card-header bg-primary text-white" style="cursor:default;">
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
                            <label class="form-label">Affected Services</label>
                            <select name="service_ids[]" class="form-select" multiple size="3">
                                <?php foreach ($services as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tags (comma separated)</label>
                            <input type="text" name="tags" class="form-control" placeholder="network, urgent">
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
                <div class="accordion" id="incidentAccordion">
                    <?php foreach ($events as $index => $e):
                        $isClosed = (strtolower($e['state_name']) === 'closed');
                        $history = $em->getStateHistory($e['id']);
                        $lastState = end($history);
                        $stateEnterTime = $lastState ? $lastState['enter_time'] : $e['create_time'];
                    ?>
                        <div class="card shadow-sm mb-3">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center"
                                 data-bs-toggle="collapse" data-bs-target="#collapse-<?= $e['id'] ?>"
                                 aria-expanded="false" aria-controls="collapse-<?= $e['id'] ?>">
                                <span>
                                    <span class="badge bg-secondary me-2">ID: <?= $e['id'] ?></span>
                                    <span class="badge bg-info text-dark"><?= htmlspecialchars($e['type_name']) ?></span>
                                    <span class="badge bg-<?= $isClosed ? 'dark' : 'warning' ?> text-<?= $isClosed ? 'white' : 'dark' ?> ms-2">
                                        <?= htmlspecialchars($e['state_name']) ?>
                                    </span>
                                    <span class="counter-box" data-start-time="<?= $e['create_time'] ?>" title="Time since creation">
                                        Total: <span class="creation-counter">0s</span>
                                    </span>
                                    <span class="counter-box" data-start-time="<?= $stateEnterTime ?>" title="Time in current state">
                                        In State: <span class="state-counter">0s</span>
                                    </span>
                                </span>
                                <small class="text-muted"><?= $e['create_time'] ?></small>
                            </div>

                            <div id="collapse-<?= $e['id'] ?>" class="collapse" data-bs-parent="#incidentAccordion">
                                <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted"><?= htmlspecialchars($e['department_name']) ?></h6>

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
                                            <?php foreach ($history as $h):
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

                                    <?php if (!$isClosed): ?>
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
                                    <?php else: ?>
                                        <div class="alert alert-secondary py-1 px-2 mb-3"><small>This incident is <strong>Closed</strong>. No further modifications allowed.</small></div>
                                    <?php endif; ?>

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

                                    <?php if (!$isClosed): ?>
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
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateCounters() {
    const now = new Date();

    document.querySelectorAll('.counter-box').forEach(box => {
        const startTime = new Date(box.getAttribute('data-start-time').replace(' ', 'T'));
        const diffInSeconds = Math.floor((now - startTime) / 1000);

        let text = "";
        if (diffInSeconds < 60) text = diffInSeconds + "s";
        else if (diffInSeconds < 3600) text = Math.floor(diffInSeconds / 60) + "m";
        else text = Math.floor(diffInSeconds / 3600) + "h " + Math.floor((diffInSeconds % 3600) / 60) + "m";

        const creationSpan = box.querySelector('.creation-counter');
        const stateSpan = box.querySelector('.state-counter');

        if (creationSpan) creationSpan.textContent = text;
        if (stateSpan) stateSpan.textContent = text;
    });
}

setInterval(updateCounters, 1000);
updateCounters();
</script>
</body>
</html>
