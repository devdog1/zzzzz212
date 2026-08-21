<?php
// Search View for Incident Management Plugin

if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_view_events</code></div>';
    return;
}

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'User');
$em = new EventManager($currentUser);
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && count($_GET) > 1) {
    $operator = $_GET['operator'] ?? 'AND';
    $results = $em->searchEvents($_GET, $operator);
}

$departments = $em->listDepartments();
$types = $em->listTypes();
$states = $em->listStates();
?>

<div class="row mb-4">
    <div class="col-md-12 text-start">
        <h1 class="h2"><i class="fa-solid fa-magnifying-glass text-primary me-2"></i>Advanced Incident Search</h1>
        <p class="text-muted">Query historical and active incidents using multi-criteria boolean filters and date ranges.</p>
    </div>
</div>

<div class="card shadow-sm mb-4 text-start">
    <div class="card-header bg-primary text-white fw-bold">
        <i class="fa-solid fa-filter me-2"></i>Filter Criteria
    </div>
    <div class="card-body">
        <form method="GET" action="index.php" class="row g-3">
            <input type="hidden" name="route" value="incident_search">

            <div class="col-md-2">
                <label class="form-label small fw-bold">Incident ID</label>
                <input type="number" name="id" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['id'] ?? '') ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-bold">Subject / Title</label>
                <input type="text" name="title" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['title'] ?? '') ?>" placeholder="Search by title...">
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-bold">Description Keywords</label>
                <input type="text" name="description" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['description'] ?? '') ?>" placeholder="e.g. outage, network...">
            </div>

            <div class="col-md-3">
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
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1 fw-bold">
                    <i class="fa-solid fa-magnifying-glass me-1"></i>Search
                </button>
                <a href="<?= url_for('incident_search') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-rotate-left me-1"></i>Clear
                </a>
            </div>
        </form>
    </div>
</div>

<?php if ($results !== null): ?>
    <div class="text-start">
        <h4 class="mb-3 fw-bold text-dark"><i class="fa-solid fa-list-check me-2"></i>Search Results (<?= count($results) ?> matches)</h4>
        <?php if (empty($results)): ?>
            <div class="alert alert-info shadow-sm"><i class="fa-solid fa-circle-info me-2"></i>No incidents matched your search criteria.</div>
        <?php else: ?>
            <div class="table-responsive bg-white shadow-sm border rounded">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Subject / Title</th>
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
                                <td>
                                    <a href="<?= url_for('incident_active') ?>#collapse-<?= $e['id'] ?>" class="badge bg-secondary text-decoration-none">
                                        #<?= $e['id'] ?>
                                    </a>
                                </td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($e['title'] ?: 'Incident #' . $e['id']) ?></td>
                                <td><small class="text-muted"><?= $e['create_time'] ?></small></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($e['type_name'] ?? 'N/A') ?></span></td>
                                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($e['state_name'] ?? 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($e['department_name'] ?? 'N/A') ?></td>
                                <td class="text-truncate" style="max-width: 300px;"><?= htmlspecialchars($e['description'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
