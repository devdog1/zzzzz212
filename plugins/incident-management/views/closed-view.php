<?php
// Closed Archive View for Incident Management Plugin

if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_view_events</code></div>';
    return;
}

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'User');
$em = new EventManager($currentUser);

$events = $em->listEvents(true);
$events = array_filter($events, function($e) { return strtolower($e['state_name'] ?? '') === 'closed'; });

$deptMap = array_column($em->listDepartments(), 'name', 'id');
$typeMap = array_column($em->listTypes(), 'name', 'id');
$stateMap = array_column($em->listStates(), 'name', 'id');

function formatDurationClosed($seconds) {
    if ($seconds < 60) return $seconds . "s";
    if ($seconds < 3600) return floor($seconds / 60) . "m";
    return floor($seconds / 3600) . "h " . floor(($seconds % 3600) / 60) . "m";
}
?>

<style>
    .update-entry { border-left: 3px solid #6c757d; padding-left: 10px; margin-bottom: 8px; }
    .update-time { font-size: 0.75rem; color: #6c757d; }
</style>

<div class="row mb-4">
    <div class="col-md-12 text-start">
        <h1 class="h2"><i class="fa-solid fa-box-archive text-secondary me-2"></i>Closed Incident Archive</h1>
        <p class="text-muted">Historical archive of resolved and closed incidents, timeline updates, and metadata audit logs.</p>
    </div>
</div>

<div class="row text-start">
    <div class="col-md-12">
        <?php if (empty($events)): ?>
            <div class="alert alert-secondary shadow-sm"><i class="fa-solid fa-circle-info me-2"></i>No closed incidents found in the archive.</div>
        <?php else: ?>
            <div class="card shadow-sm border mb-4">
                <div class="card-header bg-dark text-white fw-bold">
                    <i class="fa-solid fa-list me-2"></i>Archived Incidents List (<?= count($events) ?>)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Resolution Time</th>
                                    <th>Type</th>
                                    <th>Dept</th>
                                    <th>Tags</th>
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
                                        <td><span class="badge bg-secondary">#<?= $e['id'] ?></span></td>
                                        <td><small><?= $lastState['enter_time'] ?? $e['update_time'] ?></small></td>
                                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($e['type_name'] ?? 'N/A') ?></span></td>
                                        <td><?= htmlspecialchars($e['department_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php foreach ($e['tags'] as $tag): ?>
                                                <span class="badge rounded-pill bg-light text-dark border small">#<?= htmlspecialchars($tag['name']) ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td><span class="badge bg-danger"><?= (int)($e['impactScore'] ?? 0) ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-<?= $e['id'] ?>">
                                                <i class="fa-solid fa-eye me-1"></i>View Details
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Details Modal -->
                                    <div class="modal fade text-start" id="modal-<?= $e['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header bg-dark text-white">
                                                    <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-file-lines me-2"></i>Incident #<?= $e['id'] ?> Archive Details</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <h6 class="fw-bold text-dark">Description</h6>
                                                    <p class="fw-bold text-secondary"><?= nl2br(htmlspecialchars($e['description'] ?? '')) ?></p>
                                                    <hr>
                                                    <div class="row mb-3">
                                                        <div class="col-md-6 border-end">
                                                            <h6 class="fw-bold text-dark">Geography & Impact</h6>
                                                            <div class="mb-2">
                                                                <strong>Areas:</strong>
                                                                <?php foreach ($e['areas'] as $area): ?>
                                                                    <span class="badge bg-success small"><?= htmlspecialchars($area['name']) ?></span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <div class="small"><strong>Customers Affected:</strong> <?= (int)($e['customers_affected'] ?? 0) ?></div>
                                                            <div class="small"><strong>Impact Score:</strong> <span class="badge bg-danger"><?= (int)($e['impactScore'] ?? 0) ?></span></div>
                                                        </div>
                                                        <div class="col-md-6 ps-4">
                                                            <h6 class="fw-bold text-dark">Metadata</h6>
                                                            <div class="small"><strong>Type:</strong> <?= htmlspecialchars($e['type_name'] ?? 'N/A') ?></div>
                                                            <div class="small"><strong>Dept:</strong> <?= htmlspecialchars($e['department_name'] ?? 'N/A') ?></div>
                                                            <div class="mt-2">
                                                                <strong>Services:</strong><br>
                                                                <?php foreach ($e['services'] as $svc): ?>
                                                                    <span class="badge bg-dark small"><?= htmlspecialchars($svc['name']) ?></span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <h6 class="fw-bold text-dark">State History</h6>
                                                    <div class="d-flex flex-wrap mb-3">
                                                        <?php foreach ($history as $h):
                                                            $enter = strtotime($h['enter_time']);
                                                            $exit = $h['exit_time'] ? strtotime($h['exit_time']) : time();
                                                            $duration = $exit - $enter;
                                                        ?>
                                                            <div class="me-3 border-start ps-2 mb-2">
                                                                <div class="small fw-bold text-dark"><?= htmlspecialchars($h['state_name']) ?></div>
                                                                <div class="small text-success"><?= formatDurationClosed($duration) ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-6 border-end">
                                                            <h6 class="fw-bold text-dark">Timeline Updates</h6>
                                                            <div class="update-list">
                                                                <?php
                                                                $updates = $em->getEventUpdates($e['id']);
                                                                if (empty($updates)) echo '<p class="text-muted small">No updates recorded.</p>';
                                                                foreach ($updates as $u): ?>
                                                                    <div class="update-entry small">
                                                                        <div class="update-time"><?= $u['create_time'] ?> by <?= htmlspecialchars($u['create_user']) ?></div>
                                                                        <div><?= htmlspecialchars($u['update_text']) ?></div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <h6 class="fw-bold text-dark">Audit History</h6>
                                                            <div class="audit-list overflow-auto" style="max-height: 200px;">
                                                                <?php
                                                                $audits = $em->getAuditTrail('plug_incident_management_wb_events', $e['id']);
                                                                $audits = array_reverse($audits);
                                                                foreach ($audits as $audit):
                                                                    if ($audit['action'] !== 'UPDATE') continue;
                                                                    $old = json_decode($audit['old_values'] ?? '{}', true);
                                                                    $new = json_decode($audit['new_values'] ?? '{}', true);
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
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
