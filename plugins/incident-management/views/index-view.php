<?php
// Active Incidents View for Incident Management Plugin

if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_view_events</code></div>';
    return;
}

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'User');
$em = new EventManager($currentUser);
$emError = null;

// Handle form submissions (PRG Pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_event') {
            $em->createEvent($_POST);
        } elseif ($_POST['action'] === 'add_update') {
            $customMsg = !empty($_POST['custom_external_message']) ? $_POST['custom_external_message'] : null;
            $em->addEventUpdate($_POST['event_id'], $_POST['update_text'], isset($_POST['message_external']), $customMsg);
        } elseif ($_POST['action'] === 'update_metadata') {
            $em->updateEvent($_POST['event_id'], $_POST);
        }
        $emError = $em->getLastError();
        if (!$emError) {
            header("Location: index.php?route=incident_active");
            exit;
        }
    }
}

$slaThresholdMinutes = (int)($em->getDefault('sla_threshold_minutes') ?: 30);
$events = $em->listEvents(false);
$departments = $em->listDepartments();
$types = $em->listTypes();
$states = $em->listStates();
$services = $em->listServices();
$allTags = $em->listAllTags();
$allAreas = $em->listAllAreas();

$deptMap = array_column($departments, 'name', 'id');
$typeMap = array_column($types, 'name', 'id');
$stateMap = array_column($states, 'name', 'id');

function formatDurationLocal($seconds) {
    if ($seconds < 60) return $seconds . "s";
    if ($seconds < 3600) return floor($seconds / 60) . "m";
    return floor($seconds / 3600) . "h " . floor(($seconds % 3600) / 60) . "m";
}
?>

<style>
    .update-entry { border-left: 3px solid #0d6efd; padding-left: 10px; margin-bottom: 8px; }
    .update-time { font-size: 0.75rem; color: #6c757d; }
    .state-duration { font-size: 0.75rem; color: #198754; font-weight: bold; }
    .tag-badge { font-size: 0.75rem; cursor: default; }
    .counter-box { font-size: 0.85rem; color: #444; background: #eee; padding: 2px 8px; border-radius: 4px; margin-left: 5px; }
    .card-header-clickable { cursor: pointer; transition: background 0.2s; }
    .card-header-clickable:hover { background-color: #f0f0f0 !important; }

    .pill-container { border: 1px solid #dee2e6; padding: 5px; border-radius: 0.375rem; background: white; display: flex; flex-wrap: wrap; gap: 5px; }
    .pill-container input { border: none; outline: none; flex-grow: 1; min-width: 100px; font-size: 0.875rem; }
    .pill { display: inline-flex; align-items: center; background: #0d6efd; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; }
    .pill .remove { margin-left: 5px; cursor: pointer; font-weight: bold; }
    .pill .remove:hover { color: #ffc107; }
</style>

<div class="row mb-4 align-items-center">
    <div class="col-md-7 text-start">
        <h1 class="h2"><i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Active Incident Management</h1>
        <p class="text-muted mb-0">Track active incidents, manage updates, state transitions, NetBox circuits, and external communications.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <div class="d-inline-flex align-items-center bg-light border p-2 rounded shadow-sm">
            <i class="fa-solid fa-desktop me-2 text-primary"></i>
            <span class="small fw-bold me-2">Wallboard Auto-Refresh:</span>
            <select id="autoRefreshInterval" class="form-select form-select-sm me-2" style="width: auto;" onchange="toggleAutoRefresh(this.value)">
                <option value="off">Off</option>
                <option value="30">30s</option>
                <option value="60">60s</option>
            </select>
            <span id="refreshCountdown" class="badge bg-secondary font-monospace" style="display:none;">30s</span>
        </div>
    </div>
</div>

<?php if ($emError): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm text-start" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><strong>System Error:</strong> <?= htmlspecialchars($emError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php
$maintBannerChanges = [];
try {
    $otrsDB = $em->getOTRSDB();
    if ($otrsDB && $otrsDB->isConnected()) {
        $maintBannerChanges = $otrsDB->getChangeOverview();
    }
} catch (Throwable $e) {}

if (!empty($maintBannerChanges)): ?>
    <div class="alert alert-warning alert-dismissible fade show shadow-sm text-start mb-4 border-start border-4 border-warning" role="alert">
        <div class="d-flex align-items-center">
            <i class="fa-solid fa-triangle-exclamation text-warning fs-3 me-3"></i>
            <div>
                <strong class="text-dark">Global Maintenance Advisory:</strong>
                <span class="ms-1">There are currently <?= count($maintBannerChanges) ?> active or upcoming scheduled change window(s) in OTRS. Check <a href="<?= url_for('incident_overview') ?>" class="alert-link">Network Overview</a> for schedule details.</span>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row text-start">
    <!-- Report New Incident -->
    <div class="col-md-4 mb-4">
        <div class="card shadow-sm border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0 fs-6 fw-bold"><i class="fa-solid fa-plus-circle me-2"></i>Report New Incident</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_event">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Incident Title / Subject</label>
                        <input type="text" name="title" class="form-control form-control-sm" placeholder="Auto-generated (Area - Service) if empty">
                        <div class="form-text text-muted small" style="font-size: 0.7rem;">Leave empty to auto-generate from Area + Service.</div>
                    </div>
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
                    <button type="submit" class="btn btn-primary w-100 btn-sm fw-bold">
                        <i class="fa-solid fa-paper-plane me-1"></i>Report Incident
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Active Events List -->
    <div class="col-md-8">
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <h4 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-fire text-danger me-2"></i>Active Incidents (<?= count($events) ?>)</h4>
            <!-- Quick Filter Chips -->
            <div class="btn-group btn-group-sm" role="group" id="incidentFilterGroup">
                <button type="button" class="btn btn-outline-dark active" onclick="filterIncidents('all', this)">All</button>
                <?php foreach ($departments as $d): ?>
                    <button type="button" class="btn btn-outline-primary" onclick="filterIncidents('dept-<?= $d['id'] ?>', this)"><?= htmlspecialchars($d['name']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($events)): ?>
            <div class="alert alert-success shadow-sm"><i class="fa-solid fa-circle-check me-2"></i>No active incidents currently reported. System normal!</div>
        <?php else: ?>
            <div class="accordion" id="incidentAccordion">
                <?php foreach ($events as $e):
                    $history = $em->getStateHistory($e['id']);
                    $lastState = end($history);
                    $stateEnterTime = $lastState ? $lastState['enter_time'] : $e['create_time'];
                ?>
                    <?php
                    $updatesForE = $em->getEventUpdates($e['id']);
                    $lastUpdateObj = end($updatesForE);
                    $lastUpdateTime = $lastUpdateObj ? strtotime($lastUpdateObj['create_time']) : strtotime($stateEnterTime);
                    $minutesSinceUpdate = floor((time() - $lastUpdateTime) / 60);
                    $isStaleSla = $minutesSinceUpdate >= $slaThresholdMinutes;
                    ?>
                    <div class="card shadow-sm mb-3 border <?= $isStaleSla ? 'border-danger border-2' : '' ?> incident-card dept-<?= $e['department_id'] ?>">
                        <div class="card-header card-header-clickable bg-white d-flex justify-content-between align-items-center py-2"
                             data-bs-toggle="collapse" data-bs-target="#collapse-<?= $e['id'] ?>">
                            <span>
                                <span class="badge bg-secondary me-2">ID: #<?= $e['id'] ?></span>
                                <strong class="text-dark me-2"><?= htmlspecialchars($e['title'] ?: 'Incident #' . $e['id']) ?></strong>
                                <span class="badge bg-info text-dark me-1"><?= htmlspecialchars($e['type_name'] ?? 'General') ?></span>
                                <span class="badge bg-warning text-dark me-1"><?= htmlspecialchars($e['state_name'] ?? 'Detected') ?></span>
                                <?php if ($isStaleSla): ?>
                                    <span class="badge bg-danger me-1" title="No update for over <?= $slaThresholdMinutes ?> minutes"><i class="fa-solid fa-bell me-1"></i>SLA Stale (<?= $minutesSinceUpdate ?>m)</span>
                                <?php endif; ?>
                                <span class="counter-box" data-start-time="<?= $e['create_time'] ?>" title="Time since creation">
                                    Age: <span class="creation-counter">0s</span>
                                </span>
                                <span class="counter-box" data-start-time="<?= $stateEnterTime ?>" title="Time in current state">
                                    In State: <span class="state-counter">0s</span>
                                </span>
                            </span>
                            <small class="text-muted"><i class="fa-solid fa-clock me-1"></i><?= $e['create_time'] ?></small>
                        </div>

                        <div id="collapse-<?= $e['id'] ?>" class="collapse" data-bs-parent="#incidentAccordion">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-8">
                                        <div class="d-flex align-items-center mb-2">
                                            <h6 class="text-primary mb-0 me-3 fw-bold"><i class="fa-solid fa-sitemap me-1"></i><?= htmlspecialchars($e['department_name'] ?: 'No Department') ?></h6>
                                            <span class="badge bg-info text-dark me-2">Type: <?= htmlspecialchars($e['type_name'] ?: 'N/A') ?></span>
                                            <?php if(!empty($e['ticket_nr']) && $e['ticket_nr'] !== '0'): ?>
                                                <span class="badge bg-secondary">OTRS Ticket #: <?= htmlspecialchars($e['ticket_nr']) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <p class="card-text fw-bold fs-5 mb-3 text-dark"><?= nl2br(htmlspecialchars($e['description'] ?? '')) ?></p>

                                        <div class="mb-3">
                                            <div class="small text-muted mb-1">Affected Areas & Services:</div>
                                            <?php foreach ($e['areas'] as $area): ?>
                                                <span class="badge bg-success me-1"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($area['name']) ?></span>
                                            <?php endforeach; ?>
                                            <?php foreach ($e['services'] as $svc): ?>
                                                <span class="badge bg-dark me-1"><i class="fa-solid fa-server me-1"></i><?= htmlspecialchars($svc['name']) ?></span>
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
                                                        <span class="ms-1 text-warning" style="cursor:pointer;" onclick="removeCircuit(<?= $e['id'] ?>, <?= $c['circuit_id'] ?>)">&times;</span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if($em->getDefault('netbox_enabled') === '1'): ?>
                                                <button class="btn btn-xs btn-outline-secondary mt-1" style="font-size: 0.7rem;" onclick="showCircuitSearch(<?= $e['id'] ?>)">+ Add Circuit</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4 border-start text-end">
                                        <div class="p-3 bg-light rounded border">
                                            <div class="h6 mb-3 border-bottom pb-2 text-center fw-bold text-dark">Impact Statistics</div>
                                            <div class="d-flex justify-content-between mb-2 small">
                                                <strong>Affected Customers:</strong>
                                                <span><?= number_format((int)($e['customers_affected'] ?? 0)) ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-2 small">
                                                <strong>Impact Score:</strong>
                                                <span class="badge bg-danger fs-6"><?= number_format((int)($e['impactScore'] ?? 0)) ?></span>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.7rem;">
                                                (Customers &times; Outage Minutes)
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <h6 class="fw-bold text-dark"><i class="fa-solid fa-timeline me-1"></i>State Timeline</h6>
                                    <div class="d-flex flex-wrap">
                                        <?php foreach ($history as $h):
                                            $enter = strtotime($h['enter_time']);
                                            $exit = $h['exit_time'] ? strtotime($h['exit_time']) : time();
                                            $duration = $exit - $enter;
                                        ?>
                                            <div class="me-3 mb-2 border-start ps-2">
                                                <div class="small fw-bold text-dark"><?= htmlspecialchars($h['state_name']) ?></div>
                                                <div class="state-duration text-success"><?= formatDurationLocal($duration) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Metadata/State Update Form -->
                                <form method="POST" class="bg-light p-3 rounded border mb-4">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_metadata">
                                    <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                    <div class="mb-3">
                                        <label class="small fw-bold">Subject / Title</label>
                                        <input type="text" name="title" class="form-control form-control-sm" value="<?= htmlspecialchars($e['title'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="small fw-bold">Incident Description</label>
                                        <textarea name="description" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($e['description'] ?? '') ?></textarea>
                                    </div>
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
                                            <input type="number" name="customers_affected" class="form-control form-control-sm" value="<?= (int)($e['customers_affected'] ?? 0) ?>">
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
                                    <button type="submit" class="btn btn-sm btn-dark w-100 mt-2 fw-bold">
                                        <i class="fa-solid fa-save me-1"></i>Apply Metadata Changes
                                    </button>
                                </form>

                                <hr>
                                <div class="row">
                                    <div class="col-md-6 border-end">
                                        <h6 class="fw-bold text-dark"><i class="fa-solid fa-comments me-1"></i>Timeline Updates</h6>
                                        <div class="update-list mb-3">
                                            <?php
                                            $updates = $em->getEventUpdates($e['id']);
                                            if (empty($updates)) echo '<p class="text-muted small">No updates posted yet.</p>';
                                            foreach ($updates as $u): ?>
                                                <div class="update-entry">
                                                    <div class="update-time"><?= $u['create_time'] ?> by <?= htmlspecialchars($u['create_user']) ?></div>
                                                    <div class="small text-dark"><?= htmlspecialchars($u['update_text']) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <form method="POST" class="row g-2 mb-3">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="add_update">
                                            <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                            <div class="col-12">
                                                <select class="form-select form-select-sm mb-2 text-muted" onchange="if(this.value){ this.form.update_text.value = this.value; }">
                                                    <option value="">-- 1-Click Update Presets --</option>
                                                    <option value="Investigating root cause with upstream engineering teams.">Investigating root cause with upstream engineering teams.</option>
                                                    <option value="Mitigation applied - monitoring network & service stability.">Mitigation applied - monitoring network & service stability.</option>
                                                    <option value="Issue identified - emergency fix deployment in progress.">Issue identified - emergency fix deployment in progress.</option>
                                                    <option value="Service fully restored - verifying customer impact & starting post-mortem.">Service fully restored - verifying customer impact & starting post-mortem.</option>
                                                    <option value="External customer communications and status advisory dispatched.">External customer communications and status advisory dispatched.</option>
                                                </select>
                                                <textarea name="update_text" class="form-control form-control-sm" placeholder="Post new status update..." rows="3" required></textarea>
                                            </div>
                                            <?php if($em->getDefault('netbox_enabled') === '1'): ?>
                                            <div class="col-12 mt-1">
                                                <div class="form-check form-check-inline mb-2">
                                                    <input class="form-check-input" type="checkbox" name="message_external" id="msgExt-<?= $e['id'] ?>" onchange="document.getElementById('extMsgBox-<?= $e['id'] ?>').style.display = this.checked ? 'block' : 'none';">
                                                    <label class="form-check-label small fw-bold text-primary" for="msgExt-<?= $e['id'] ?>">
                                                        <i class="fa-solid fa-envelope me-1"></i>Msg External (Notify Circuit Tenants)
                                                    </label>
                                                </div>
                                                <div id="extMsgBox-<?= $e['id'] ?>" class="bg-light p-2 border rounded mb-2 text-start" style="display:none;">
                                                    <label class="small text-muted fw-bold mb-1">External Notification Content / Template Preset:</label>
                                                    <select class="form-select form-select-sm mb-2" onchange="if(this.value){ document.getElementById('custExt-<?= $e['id'] ?>').value = this.value; }">
                                                        <option value="">-- Use Internal Update Text OR Select External Template --</option>
                                                        <option value="<?= htmlspecialchars($em->getDefault('external_email_template') ?: '') ?>">Template 1: Default</option>
                                                        <option value="<?= htmlspecialchars($em->getDefault('external_email_template_2') ?: '') ?>">Template 2: Outage / Advisory</option>
                                                        <option value="<?= htmlspecialchars($em->getDefault('external_email_template_3') ?: '') ?>">Template 3: Resolution</option>
                                                    </select>
                                                    <textarea name="custom_external_message" id="custExt-<?= $e['id'] ?>" class="form-control form-control-sm" rows="2" placeholder="Custom external message (leave blank to send internal update text)..."></textarea>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <div class="col-auto">
                                                <button type="submit" class="btn btn-outline-primary btn-sm fw-bold"><i class="fa-solid fa-plus me-1"></i>Post Update</button>
                                            </div>
                                        </form>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="fw-bold text-dark"><i class="fa-solid fa-clock-rotate-left me-1"></i>Audit History</h6>
                                        <div class="audit-list overflow-auto" style="max-height: 250px;">
                                            <?php
                                            $audits = $em->getAuditTrail('plug_incident_management_wb_events', $e['id']);
                                            $audits = array_reverse($audits);
                                            foreach ($audits as $audit):
                                                $action = $audit['action'];
                                                $new = json_decode($audit['new_values'] ?? '{}', true);
                                                $old = json_decode($audit['old_values'] ?? '{}', true);
                                            ?>
                                                <div class="mb-2 p-2 bg-light border rounded small" style="font-size: 0.75rem;">
                                                    <div class="text-muted" style="font-size:0.65rem;"><?= $audit['timestamp'] ?> by <?= htmlspecialchars($audit['user']) ?></div>

                                                    <?php if ($action === 'UPDATE' && is_array($new)): ?>
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

                                                    <?php elseif ($action === 'OTRS_TICKET_FAILED'): ?>
                                                        <div class="text-danger fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i>OTRS Ticket Creation Failed</div>
                                                        <div class="text-secondary">Error: <?= htmlspecialchars($new['error'] ?? 'API Error') ?></div>
                                                        <?php if (!empty($new['url'])): ?><div>API URL: <code><?= htmlspecialchars($new['url']) ?></code></div><?php endif; ?>
                                                        <?php if (!empty($new['http_code'])): ?><div>HTTP Status: <code><?= htmlspecialchars($new['http_code']) ?></code></div><?php endif; ?>
                                                        <?php if (!empty($new['curl_error'])): ?><div>cURL Error: <code><?= htmlspecialchars($new['curl_error']) ?></code></div><?php endif; ?>
                                                        <?php if (!empty($new['response'])): ?><div class="text-muted text-break mt-1" style="font-size:0.65rem;">Raw Response: <code><?= htmlspecialchars($new['response']) ?></code></div><?php endif; ?>

                                                    <?php elseif ($action === 'OTRS_TICKET_CREATED'): ?>
                                                        <div class="text-success fw-bold"><i class="fa-solid fa-circle-check me-1"></i>OTRS Ticket Created</div>
                                                        <div>Ticket #: <strong><?= htmlspecialchars($new['ticketnr'] ?? ($new['ticket_nr'] ?? 'N/A')) ?></strong> (ID: <?= htmlspecialchars($new['ticketid'] ?? ($new['ticket_id'] ?? 'N/A')) ?>)</div>
                                                        <?php if (!empty($new['url'])): ?><div>API URL: <code><?= htmlspecialchars($new['url']) ?></code></div><?php endif; ?>

                                                    <?php elseif ($action === 'OTRS_ARTICLE_FAILED'): ?>
                                                        <div class="text-warning fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i>OTRS Article Creation Failed</div>
                                                        <div class="text-secondary">Error: <?= htmlspecialchars($new['error'] ?? 'API Error') ?></div>
                                                        <?php if (!empty($new['url'])): ?><div>API URL: <code><?= htmlspecialchars($new['url']) ?></code></div><?php endif; ?>
                                                        <?php if (!empty($new['response'])): ?><div class="text-muted text-break mt-1" style="font-size:0.65rem;">Raw Response: <code><?= htmlspecialchars($new['response']) ?></code></div><?php endif; ?>

                                                    <?php elseif ($action === 'OTRS_ARTICLE_CREATED'): ?>
                                                        <div class="text-success fw-bold"><i class="fa-solid fa-file-circle-check me-1"></i>OTRS Article Created</div>
                                                        <?php if (!empty($new['url'])): ?><div>API URL: <code><?= htmlspecialchars($new['url']) ?></code></div><?php endif; ?>

                                                    <?php elseif ($action === 'TEAMS_CHAT_CREATED'): ?>
                                                        <div class="text-primary fw-bold"><i class="fa-brands fa-microsoft me-1"></i>Teams Group Chat Created</div>
                                                        <div>Topic: <strong><?= htmlspecialchars($new['topic'] ?? 'N/A') ?></strong></div>
                                                        <?php if (!empty($new['teams_chat_id'])): ?><div>Chat ID: <code><?= htmlspecialchars($new['teams_chat_id']) ?></code></div><?php endif; ?>
                                                        <?php if (!empty($new['members_count'])): ?><div class="text-muted">Members Invited: <?= (int)$new['members_count'] ?></div><?php endif; ?>

                                                    <?php elseif ($action === 'TEAMS_CHAT_FAILED'): ?>
                                                        <div class="text-danger fw-bold"><i class="fa-brands fa-microsoft me-1"></i>Teams Group Chat Creation Failed</div>
                                                        <div class="text-secondary">Error: <?= htmlspecialchars($new['error'] ?? 'Teams API Error') ?></div>
                                                        <?php if (!empty($new['department'])): ?><div>Department: <?= htmlspecialchars($new['department']) ?></div><?php endif; ?>
                                                        <?php if (isset($new['resolved_members_count'])): ?><div>Resolved Member OIDs: <?= (int)$new['resolved_members_count'] ?> (minimum 2 required)</div><?php endif; ?>

                                                    <?php elseif ($action === 'TEAMS_MEMBERS_SYNCED'): ?>
                                                        <div class="text-info fw-bold"><i class="fa-brands fa-microsoft me-1"></i>Teams Chat Members Synced</div>
                                                        <div>Synced <?= (int)($new['count'] ?? 0) ?> member(s) to Teams chat</div>

                                                    <?php elseif ($action === 'CREATE'): ?>
                                                        <div class="text-primary fw-bold"><i class="fa-solid fa-asterisk me-1"></i>Incident Reported</div>

                                                    <?php else: ?>
                                                        <div><strong class="badge bg-secondary"><?= htmlspecialchars($action) ?></strong> <?= htmlspecialchars(substr($audit['new_values'] ?? '', 0, 100)) ?></div>
                                                    <?php endif; ?>
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

<!-- Circuit Search Modal -->
<div class="modal fade" id="circuitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-network-wired me-2"></i>Search NetBox Circuits</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="activeEventId">
                <div class="input-group mb-3">
                    <input type="text" id="circuitQuery" class="form-control form-control-sm" placeholder="Circuit ID / CID...">
                    <button class="btn btn-sm btn-primary" onclick="searchCircuits()">Search</button>
                </div>
                <div id="circuitResults" class="list-group">
                    <!-- Results will appear here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let circuitModal;
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('circuitModal');
    if (el && typeof bootstrap !== 'undefined') {
        circuitModal = new bootstrap.Modal(el);
    }
});

function showCircuitSearch(eventId) {
    document.getElementById('activeEventId').value = eventId;
    document.getElementById('circuitResults').innerHTML = '';
    document.getElementById('circuitQuery').value = '';
    if (circuitModal) circuitModal.show();
}

async function searchCircuits() {
    const q = document.getElementById('circuitQuery').value;
    const results = document.getElementById('circuitResults');
    results.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>';

    try {
        const response = await fetch(`index.php?route=incident_api&action=netbox_search&q=${encodeURIComponent(q)}`);
        const data = await response.json();
        results.innerHTML = '';
        if (!data || data.length === 0) {
            results.innerHTML = '<div class="list-group-item text-muted small">No matching circuits found</div>';
            return;
        }
        data.forEach(c => {
            const btn = document.createElement('button');
            btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center small';
            const tenantName = c.tenant ? c.tenant.name : 'No Tenant';
            btn.innerHTML = `
                <span><strong>${c.cid}</strong> <small class="text-muted">(${tenantName})</small></span>
                <span class="badge bg-primary">Add</span>
            `;
            btn.onclick = () => addCircuit(c.id, c.cid, tenantName);
            results.appendChild(btn);
        });
    } catch (e) {
        results.innerHTML = '<div class="list-group-item text-danger small">Search failed</div>';
    }
}

async function addCircuit(circuitId, cid, provider) {
    const eventId = document.getElementById('activeEventId').value;
    try {
        const response = await fetch('index.php?route=incident_api&action=add_circuit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: eventId, circuit_id: circuitId, circuit_cid: cid, provider: provider })
        });
        if (response.ok) {
            window.location.href = 'index.php?route=incident_active';
        }
    } catch (e) {
        alert('Failed to add circuit');
    }
}

async function removeCircuit(eventId, circuitId) {
    if (!confirm('Remove this circuit association?')) return;
    try {
        const response = await fetch(`index.php?route=incident_api&action=remove_circuit&event_id=${eventId}&circuit_id=${circuitId}`, {
            method: 'DELETE'
        });
        if (response.ok) {
            window.location.href = 'index.php?route=incident_active';
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

    if (input) {
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

        input.oninput = () => {
            const val = input.value.trim();
            const list = input.getAttribute('list');
            if (list) {
                const listEl = document.getElementById(list);
                if (listEl) {
                    const options = listEl.options;
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
            }
        };
    }
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

function filterIncidents(filterClass, btn) {
    document.querySelectorAll('#incidentFilterGroup .btn').forEach(b => b.classList.remove('active', 'btn-dark'));
    document.querySelectorAll('#incidentFilterGroup .btn-outline-primary, #incidentFilterGroup .btn-outline-dark').forEach(b => {
        if (b !== btn) b.classList.remove('active');
    });
    btn.classList.add('active');

    const cards = document.querySelectorAll('.incident-card');
    cards.forEach(card => {
        if (filterClass === 'all' || card.classList.contains(filterClass)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

let refreshTimer = null;
let countdownSecs = 0;

function toggleAutoRefresh(val) {
    if (refreshTimer) clearInterval(refreshTimer);
    const badge = document.getElementById('refreshCountdown');

    if (val === 'off') {
        badge.style.display = 'none';
        localStorage.removeItem('incident_wallboard_refresh');
        return;
    }

    localStorage.setItem('incident_wallboard_refresh', val);
    countdownSecs = parseInt(val, 10);
    badge.style.display = 'inline-block';
    badge.textContent = countdownSecs + 's';

    refreshTimer = setInterval(() => {
        countdownSecs--;
        if (countdownSecs <= 0) {
            location.reload();
        } else {
            badge.textContent = countdownSecs + 's';
        }
    }, 1000);
}

document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('incident_wallboard_refresh');
    if (saved && saved !== 'off') {
        const sel = document.getElementById('autoRefreshInterval');
        if (sel) {
            sel.value = saved;
            toggleAutoRefresh(saved);
        }
    }
});
</script>
