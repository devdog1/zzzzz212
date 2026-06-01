<?php
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

$configPath = $docRoot . '/inc/config.php';
$hasConfig = file_exists($configPath);
$config = [];
if ($hasConfig) {
    require $configPath;
}

// Logic to force SQLite ONLY if MySQL config is missing or invalid
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

// Autoloading/Manual Includes
require_once $docRoot . '/Database.php';
require_once $docRoot . '/EventManager.php';
require_once $docRoot . '/classes/AzureADSSO.php';
require_once $docRoot . '/classes/Auth.php';

// Force SQLite setup if needed
if ($useSqlite) {
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
        $em->createState('Detected');
        $em->createState('Acknowledged');
        $em->createState('Investigating');
        $em->createState('Identified');
        $em->createState('Mitigating');
        $em->createState('Reopened');
        $em->createState('Resolved');
        $em->createState('Closed');
        $em->createService('Authentication Provider');
        $em->createService('Internal API');
        $em->createService('Main Website');
    }
}

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

$em = new EventManager($currentUser);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_event') {
            $em->createEvent($_POST);
        } elseif ($_POST['action'] === 'add_update') {
            $em->addEventUpdate($_POST['event_id'], $_POST['update_text']);
        } elseif ($_POST['action'] === 'update_metadata') {
            $em->updateEvent($_POST['event_id'], $_POST);
        }
    }
}

$events = $em->listEvents(false); // Only non-closed
$departments = $em->listDepartments();
$types = $em->listTypes();
$states = $em->listStates();
$services = $em->listServices();
$allTags = $em->listAllTags();
$allAreas = $em->listAllAreas();

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
    <title>Incident Manager</title>
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

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand h1 mb-0" href="index.php">Incident Manager</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link active" href="index.php">Active Incidents</a></li>
                <li class="nav-item"><a class="nav-link" href="closed.php">Closed Archive</a></li>
            </ul>
        </div>
        <div class="navbar-text text-white text-end">
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
                    <h5 class="mb-0">Report New Incident</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_event">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Description</label>
                            <textarea name="description" class="form-control form-control-sm" rows="3" required></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col">
                                <label class="form-label small fw-bold">Type</label>
                                <select name="type_id" class="form-select form-select-sm">
                                    <?php foreach ($types as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col">
                                <label class="form-label small fw-bold">Department</label>
                                <select name="department_id" class="form-select form-select-sm">
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Affected Areas (comma separated)</label>
                            <input type="text" name="areas" class="form-control form-control-sm" list="areaList" placeholder="Town, Node, etc.">
                            <datalist id="areaList">
                                <?php foreach ($allAreas as $a): ?>
                                    <option value="<?= htmlspecialchars($a['name']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Customers Affected</label>
                            <input type="number" name="customers_affected" class="form-control form-control-sm" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Affected Services</label>
                            <select name="service_ids[]" class="form-select form-select-sm" multiple size="3">
                                <?php foreach ($services as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Tags (comma separated)</label>
                            <input type="text" name="tags" class="form-control form-control-sm" list="tagList">
                            <datalist id="tagList">
                                <?php foreach ($allTags as $t): ?>
                                    <option value="<?= htmlspecialchars($t['name']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Report Incident</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Event List -->
        <div class="col-md-8">
            <h2 class="mb-3">Active Incidents</h2>
            <?php if (empty($events)): ?>
                <div class="alert alert-info">No active incidents reported.</div>
            <?php else: ?>
                <div class="accordion" id="incidentAccordion">
                    <?php foreach ($events as $e):
                        $history = $em->getStateHistory($e['id']);
                        $lastState = end($history);
                        $stateEnterTime = $lastState ? $lastState['enter_time'] : $e['create_time'];
                    ?>
                        <div class="card shadow-sm mb-3">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center"
                                 data-bs-toggle="collapse" data-bs-target="#collapse-<?= $e['id'] ?>">
                                <span>
                                    <span class="badge bg-secondary me-2">ID: <?= $e['id'] ?></span>
                                    <span class="badge bg-info text-dark"><?= htmlspecialchars($e['type_name']) ?></span>
                                    <span class="badge bg-warning text-dark ms-2"><?= htmlspecialchars($e['state_name']) ?></span>
                                    <span class="counter-box" data-start-time="<?= $e['create_time'] ?>" title="Time since creation">
                                        Age: <span class="creation-counter">0s</span>
                                    </span>
                                    <span class="counter-box" data-start-time="<?= $stateEnterTime ?>" title="Time in current state">
                                        In State: <span class="state-counter">0s</span>
                                    </span>
                                </span>
                                <small class="text-muted"><?= $e['create_time'] ?></small>
                            </div>

                            <div id="collapse-<?= $e['id'] ?>" class="collapse" data-bs-parent="#incidentAccordion">
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-8">
                                            <h6 class="text-primary"><?= htmlspecialchars($e['department_name']) ?></h6>
                                            <p class="card-text fw-bold fs-5"><?= nl2br(htmlspecialchars($e['description'])) ?></p>
                                            <div class="mb-2">
                                                <?php foreach ($e['services'] as $svc): ?>
                                                    <span class="badge bg-dark me-1">Service: <?= htmlspecialchars($svc['name']) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="mb-2">
                                                <?php foreach ($e['areas'] as $area): ?>
                                                    <span class="badge bg-success me-1">Area: <?= htmlspecialchars($area['name']) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div>
                                                <?php foreach ($e['tags'] as $tag): ?>
                                                    <span class="badge rounded-pill bg-light text-dark border tag-badge me-1">#<?= htmlspecialchars($tag['name']) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4 border-start text-end">
                                            <div class="small"><strong>Customers:</strong> <?= (int)$e['customers_affected'] ?></div>
                                            <div class="small"><strong>Impact Score:</strong> <span class="badge bg-danger"><?= (int)$e['impactScore'] ?></span></div>
                                        </div>
                                    </div>

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
                                                    <div class="state-duration text-success"><?= formatDuration($duration) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Metadata/State Update Form -->
                                    <form method="POST" class="bg-light p-3 rounded border mb-4">
                                        <input type="hidden" name="action" value="update_metadata">
                                        <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-4">
                                                <label class="small fw-bold">Change State</label>
                                                <select name="state_id" class="form-select form-select-sm">
                                                    <?php foreach ($states as $s): ?>
                                                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $e['state_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="small fw-bold">Department</label>
                                                <select name="department_id" class="form-select form-select-sm">
                                                    <?php foreach ($departments as $d): ?>
                                                        <option value="<?= $d['id'] ?>" <?= $d['id'] == $e['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="small fw-bold">Customers Affected</label>
                                                <input type="number" name="customers_affected" class="form-control form-control-sm" value="<?= (int)$e['customers_affected'] ?>">
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="small fw-bold">Update Tags</label>
                                                <input type="text" name="tags" class="form-control form-control-sm" value="<?= htmlspecialchars(implode(',', array_column($e['tags'], 'name'))) ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="small fw-bold">Update Areas</label>
                                                <input type="text" name="areas" class="form-control form-control-sm" value="<?= htmlspecialchars(implode(',', array_column($e['areas'], 'name'))) ?>">
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-12">
                                                <label class="small fw-bold">Update Services</label>
                                                <select name="service_ids[]" class="form-select form-select-sm" multiple size="2">
                                                    <?php
                                                    $selectedSvcs = array_column($e['services'], 'id');
                                                    foreach ($services as $s): ?>
                                                        <option value="<?= $s['id'] ?>" <?= in_array($s['id'], $selectedSvcs) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-dark w-100 mt-2">Apply Metadata Changes</button>
                                    </form>

                                    <hr>
                                    <h6>Timeline Updates</h6>
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
                                            <input type="text" name="update_text" class="form-control form-control-sm" placeholder="Post new update..." required>
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Post</button>
                                        </div>
                                    </form>
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
        const startTimeStr = box.getAttribute('data-start-time');
        if (!startTimeStr) return;
        const startTime = new Date(startTimeStr.replace(' ', 'T'));
        const diffInSeconds = Math.floor((now - startTime) / 1000);
        let text = "";
        if (diffInSeconds < 0) text = "0s";
        else if (diffInSeconds < 60) text = diffInSeconds + "s";
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
