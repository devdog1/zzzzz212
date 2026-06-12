<?php
require_once __DIR__ . "/autoload.php";
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

$auth_obj = null;
if (!$useSqlite) {
    $auth_obj = new Auth($config);
    if (!$auth_obj->hasPermission('events.manage') && !$auth_obj->hasPermission('admin.panel')) {
        http_response_code(401);
        echo "Error 401 Unauthorized: You need authorization (events.manage) to access this page.";
        exit();
    }
}
$em = new EventManager($currentUser, $auth_obj);
$emError = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_event') {
            $em->createEvent($_POST);
        } elseif ($_POST['action'] === 'add_update') {
            $em->addEventUpdate($_POST['event_id'], $_POST['update_text'], isset($_POST['message_external']));
        } elseif ($_POST['action'] === 'update_metadata') {
            $em->updateEvent($_POST['event_id'], $_POST);
        }
        $emError = $em->getLastError();
    }
}

$events = $em->listEvents(false); // Only non-closed
$departments = $em->listDepartments();
$types = $em->listTypes();
$states = $em->listStates();
$services = $em->listServices();
$allTags = $em->listAllTags();
$allAreas = $em->listAllAreas();

// Name maps for Audit translation
$deptMap = array_column($departments, 'name', 'id');
$typeMap = array_column($types, 'name', 'id');
$stateMap = array_column($states, 'name', 'id');

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

        .pill-container { border: 1px solid #dee2e6; padding: 5px; border-radius: 0.375rem; background: white; display: flex; flex-wrap: wrap; gap: 5px; }
        .pill-container input { border: none; outline: none; flex-grow: 1; min-width: 100px; font-size: 0.875rem; }
        .pill { display: inline-flex; align-items: center; background: #0d6efd; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; }
        .pill .remove { margin-left: 5px; cursor: pointer; font-weight: bold; }
        .pill .remove:hover { color: #ffc107; }
    </style>
</head>
<body>

<?php
$nav = new NavigationBuilder($em->db, $auth_obj);
echo $nav->render();
?>

<div class="container">
    <?php if ($emError): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm" role="alert">
            <strong>System Error:</strong> <?= htmlspecialchars($emError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

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
                            <label class="form-label small fw-bold">Affected Areas</label>
                            <div class="pill-container" id="area-container-new">
                                <input type="text" class="pill-input" list="areaList" placeholder="Add area...">
                                <input type="hidden" name="areas" class="pill-hidden">
                            </div>
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
                            <label class="form-label small fw-bold">Tags</label>
                            <div class="pill-container" id="tag-container-new">
                                <input type="text" class="pill-input" list="tagList" placeholder="Add tag...">
                                <input type="hidden" name="tags" class="pill-hidden">
                            </div>
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
                                            <div class="d-flex align-items-center mb-2">
                                                <h6 class="text-primary mb-0 me-3"><?= htmlspecialchars($e['department_name'] ?: 'No Department') ?></h6>
                                                <span class="badge bg-info text-dark me-2">Type: <?= htmlspecialchars($e['type_name'] ?: 'N/A') ?></span>
                                                <?php if($e['ticket_nr'] && $e['ticket_nr'] !== '0'): ?>
                                                    <span class="badge bg-secondary">Ticket: <?= htmlspecialchars($e['ticket_nr']) ?></span>
                                                <?php endif; ?>
                                            </div>

                                            <p class="card-text fw-bold fs-5 mb-3"><?= nl2br(htmlspecialchars($e['description'])) ?></p>

                                            <div class="mb-3">
                                                <div class="small text-muted mb-1">Affected Areas & Services:</div>
                                                <?php foreach ($e['areas'] as $area): ?>
                                                    <span class="badge bg-success me-1">Area: <?= htmlspecialchars($area['name']) ?></span>
                                                <?php endforeach; ?>
                                                <?php foreach ($e['services'] as $svc): ?>
                                                    <span class="badge bg-dark me-1">Service: <?= htmlspecialchars($svc['name']) ?></span>
                                                <?php endforeach; ?>
                                            </div>

                                            <div class="mb-3">
                                                <div class="small text-muted mb-1">Tags:</div>
                                                <?php foreach ($e['tags'] as $tag): ?>
                                                    <span class="badge rounded-pill bg-light text-dark border tag-badge me-1">#<?= htmlspecialchars($tag['name']) ?></span>
                                                <?php endforeach; ?>
                                            </div>

                                            <div class="mb-3">
                                                <div class="small text-muted mb-1">Associated Circuits (NetBox):</div>
                                                <div id="circuit-list-<?= $e['id'] ?>">
                                                    <?php if (empty($e['circuits'])): ?>
                                                        <span class="text-muted small">None</span>
                                                    <?php endif; ?>
                                                    <?php foreach ($e['circuits'] as $c): ?>
                                                        <div class="badge bg-secondary me-1 mb-1">
                                                            <?= htmlspecialchars($c['circuit_cid']) ?> (<?= htmlspecialchars($c['provider']) ?>)
                                                            <span class="ms-1 cursor-pointer text-warning" onclick="removeCircuit(<?= $e['id'] ?>, <?= $c['circuit_id'] ?>)">&times;</span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php if($em->getDefault('netbox_enabled') === '1'): ?>
                                                    <button class="btn btn-xs btn-outline-secondary mt-1" style="font-size: 0.7rem;" onclick="showCircuitSearch(<?= $e['id'] ?>)">+ Add Circuit</button>
                                                <?php endif; ?>
                                            </div>

                                            <?php if($e['teams_chat_id']): ?>
                                                <div class="mb-3">
                                                    <a href="https://teams.microsoft.com/l/chat/0/0?users=8:orgid:<?= $e['teams_chat_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <img src="https://upload.wikimedia.org/wikipedia/commons/c/c9/Microsoft_Office_Teams_%282018%E2%80%93present%29.svg" width="16" class="me-1">
                                                        Open Teams Chat
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-4 border-start text-end">
                                            <div class="p-3 bg-light rounded">
                                                <div class="h6 mb-3 border-bottom pb-2 text-center">Impact Statistics</div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <strong>Affected:</strong>
                                                    <span><?= number_format((int)$e['customers_affected']) ?> cust.</span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <strong>Impact Score:</strong>
                                                    <span class="badge bg-danger fs-6"><?= number_format((int)$e['impactScore']) ?></span>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.7rem;">
                                                    (Customers &times; Outage Minutes)
                                                </div>
                                            </div>
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
                                                <div class="pill-container" id="tag-container-<?= $e['id'] ?>" data-initial="<?= htmlspecialchars(implode(',', array_column($e['tags'], 'name'))) ?>">
                                                    <input type="text" class="pill-input" list="tagList">
                                                    <input type="hidden" name="tags" class="pill-hidden">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="small fw-bold">Update Areas</label>
                                                <div class="pill-container" id="area-container-<?= $e['id'] ?>" data-initial="<?= htmlspecialchars(implode(',', array_column($e['areas'], 'name'))) ?>">
                                                    <input type="text" class="pill-input" list="areaList">
                                                    <input type="hidden" name="areas" class="pill-hidden">
                                                </div>
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
                                    <div class="row">
                                        <div class="col-md-6 border-end">
                                            <h6>Timeline Updates</h6>
                                            <div class="update-list mb-3">
                                                <?php
                                                $updates = $em->getEventUpdates($e['id']);
                                                if (empty($updates)) echo '<p class="text-muted small">No updates yet.</p>';
                                                foreach ($updates as $u): ?>
                                                    <div class="update-entry">
                                                        <div class="update-time"><?= $u['create_time'] ?> by <?= htmlspecialchars($u['create_user']) ?></div>
                                                        <div><?= htmlspecialchars($u['update_text']) ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <form method="POST" class="row g-2 mb-3">
                                                <input type="hidden" name="action" value="add_update">
                                                <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                                <div class="col">
                                                    <input type="text" name="update_text" class="form-control form-control-sm" placeholder="Post new update..." required>
                                                </div>
                                                <?php if($em->getDefault('netbox_enabled') === '1'): ?>
                                                <div class="col-auto d-flex align-items-center">
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input" type="checkbox" name="message_external" id="msgExt-<?= $e['id'] ?>">
                                                        <label class="form-check-label small" for="msgExt-<?= $e['id'] ?>">Msg External</label>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <div class="col-auto">
                                                    <button type="submit" class="btn btn-outline-primary btn-sm">Post</button>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Audit History</h6>
                                            <div class="audit-list overflow-auto" style="max-height: 250px;">
                                                <?php
                                                $audits = $em->getAuditTrail('wb_events', $e['id']);
                                                $audits = array_reverse($audits);
                                                foreach ($audits as $audit):
                                                    if ($audit['action'] !== 'UPDATE') continue;
                                                    $old = json_decode($audit['old_values'], true);
                                                    $new = json_decode($audit['new_values'], true);
                                                    if (!$old || !$new) continue;
                                                ?>
                                                    <div class="mb-2 p-2 bg-light border rounded small" style="font-size: 0.75rem;">
                                                        <div class="text-muted" style="font-size:0.65rem;"><?= $audit['timestamp'] ?> by <?= htmlspecialchars($audit['user']) ?></div>
                                                        <?php foreach ($new as $key => $val):
                                                            if (in_array($key, ['update_time', 'update_user'])) continue;
                                                            $oldVal = $old[$key] ?? null;
                                                            if (json_encode($oldVal) === json_encode($val)) continue;

                                                            $label = $key;
                                                            $v_old = $oldVal;
                                                            $v_new = $val;

                                                            if ($key === 'state_id') { $label = 'Status'; $v_old = $stateMap[$oldVal] ?? $oldVal; $v_new = $stateMap[$val] ?? $val; }
                                                            if ($key === 'type_id') { $label = 'Type'; $v_old = $typeMap[$oldVal] ?? $oldVal; $v_new = $typeMap[$val] ?? $val; }
                                                            if ($key === 'department_id') { $label = 'Dept'; $v_old = $deptMap[$oldVal] ?? $oldVal; $v_new = $deptMap[$val] ?? $val; }
                                                        ?>
                                                            <div>
                                                                <strong><?= htmlspecialchars($label) ?>:</strong>
                                                                <span class="text-decoration-line-through text-muted"><?= htmlspecialchars(is_array($v_old) ? json_encode($v_old) : $v_old) ?></span>
                                                                &rarr;
                                                                <span><?= htmlspecialchars(is_array($v_new) ? json_encode($v_new) : $v_new) ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Circuit Search Modal -->
<div class="modal fade" id="circuitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Search NetBox Circuits</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="activeEventId">
                <div class="input-group mb-3">
                    <input type="text" id="circuitQuery" class="form-control" placeholder="Circuit ID / CID...">
                    <button class="btn btn-primary" onclick="searchCircuits()">Search</button>
                </div>
                <div id="circuitResults" class="list-group">
                    <!-- Results will appear here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let circuitModal;
document.addEventListener('DOMContentLoaded', () => {
    circuitModal = new bootstrap.Modal(document.getElementById('circuitModal'));
});

function showCircuitSearch(eventId) {
    document.getElementById('activeEventId').value = eventId;
    document.getElementById('circuitResults').innerHTML = '';
    document.getElementById('circuitQuery').value = '';
    circuitModal.show();
}

async function searchCircuits() {
    const q = document.getElementById('circuitQuery').value;
    const results = document.getElementById('circuitResults');
    results.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm"></div></div>';

    try {
        const response = await fetch(`api/v1/index.php/netbox/search?q=${encodeURIComponent(q)}`);
        const data = await response.json();
        results.innerHTML = '';
        if (data.length === 0) {
            results.innerHTML = '<div class="list-group-item text-muted">No circuits found</div>';
            return;
        }
        data.forEach(c => {
            const btn = document.createElement('button');
            btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            btn.innerHTML = `
                <span><strong>${c.cid}</strong> <small class="text-muted">(${c.provider.name})</small></span>
                <span class="badge bg-primary">Add</span>
            `;
            btn.onclick = () => addCircuit(c.id, c.cid, c.provider.name);
            results.appendChild(btn);
        });
    } catch (e) {
        results.innerHTML = '<div class="list-group-item text-danger">Search failed</div>';
    }
}

async function addCircuit(circuitId, cid, provider) {
    const eventId = document.getElementById('activeEventId').value;
    try {
        const response = await fetch('api/v1/index.php/netbox/circuits', {
            method: 'POST',
            body: JSON.stringify({ event_id: eventId, circuit_id: circuitId, circuit_cid: cid, provider: provider })
        });
        if (response.ok) {
            location.reload();
        }
    } catch (e) {
        alert('Failed to add circuit');
    }
}

async function removeCircuit(eventId, circuitId) {
    if (!confirm('Remove this circuit association?')) return;
    try {
        const response = await fetch(`api/v1/index.php/netbox/circuits?event_id=${eventId}&circuit_id=${circuitId}`, {
            method: 'DELETE'
        });
        if (response.ok) {
            location.reload();
        }
    } catch (e) {
        alert('Failed to remove circuit');
    }
}
function initPillInput(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const input = container.querySelector('.pill-input');
    const hidden = container.querySelector('.pill-hidden');
    let items = [];

    const initial = container.getAttribute('data-initial');
    if (initial) {
        items = initial.split(',').filter(i => i.trim() !== '');
        render();
    }

    function render() {
        container.querySelectorAll('.pill').forEach(p => p.remove());
        items.forEach((item, index) => {
            const pill = document.createElement('span');
            pill.className = 'pill';
            pill.innerHTML = `${item} <span class="remove" data-index="${index}">&times;</span>`;
            container.insertBefore(pill, input);
        });
        hidden.value = items.join(',');

        container.querySelectorAll('.remove').forEach(r => {
            r.onclick = (e) => {
                items.splice(e.target.getAttribute('data-index'), 1);
                render();
            };
        });
    }

    input.onkeydown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const val = input.value.trim();
            if (val && !items.includes(val)) {
                items.push(val);
                render();
                input.value = '';
            }
        } else if (e.key === 'Backspace' && input.value === '' && items.length > 0) {
            items.pop();
            render();
        }
    };

    // Also handle selection from datalist
    input.oninput = (e) => {
        const val = input.value.trim();
        // Check if value matches an option in datalist
        const list = input.getAttribute('list');
        if (list) {
            const options = document.getElementById(list).options;
            for (let i = 0; i < options.length; i++) {
                if (options[i].value === val) {
                    if (!items.includes(val)) {
                        items.push(val);
                        render();
                        input.value = '';
                    }
                    break;
                }
            }
        }
    };
}

document.addEventListener('DOMContentLoaded', () => {
    initPillInput('area-container-new');
    initPillInput('tag-container-new');
    document.querySelectorAll('[id^="tag-container-"], [id^="area-container-"]').forEach(c => {
        initPillInput(c.id);
    });
});

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
