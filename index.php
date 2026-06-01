<?php
require_once 'EventManager.php';

// Force SQLite for this demo environment
putenv('USE_SQLITE=true');
if (!file_exists('event_mgmt.sqlite')) {
    $db = new PDO("sqlite:event_mgmt.sqlite");
    $sql = file_get_contents('schema_sqlite.sql');
    $db->exec($sql);

    // Seed some data
    $em = new EventManager();
    $em->createDepartment('IT Support');
    $em->createDepartment('Facilities');
    $em->createType('Hardware');
    $em->createType('Software');
    $em->createState('Active');
    $em->createState('Resolved');
    $em->createState('Closed');
}

$em = new EventManager('web_user');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_event') {
            $em->createEvent($_POST);
        } elseif ($_POST['action'] === 'add_update') {
            $em->addEventUpdate($_POST['event_id'], $_POST['update_text']);
        }
    }
}

$events = $em->listEvents();
$departments = $em->listDepartments();
$types = $em->listTypes();
$states = $em->listStates();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Management System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .update-entry { border-left: 3px solid #0d6efd; padding-left: 10px; margin-bottom: 8px; }
        .update-time { font-size: 0.75rem; color: #6c757d; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <span class="navbar-brand mb-0 h1">Incident Manager</span>
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
                            <textarea name="description" class="form-control" rows="3" required placeholder="Describe the incident..."></textarea>
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
                            <label class="form-label">Type</label>
                            <select name="type_id" class="form-select">
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
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
            <h2 class="mb-3">Active Incidents</h2>
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
                            <p class="card-text fw-bold"><?= nl2br(htmlspecialchars($e['description'])) ?></p>

                            <hr>
                            <h6>Timeline Updates</h6>
                            <div class="update-list mb-3">
                                <?php
                                $updates = $em->getEventUpdates($e['id']);
                                if (empty($updates)): ?>
                                    <small class="text-muted">No updates yet.</small>
                                <?php else: ?>
                                    <?php foreach ($updates as $u): ?>
                                        <div class="update-entry">
                                            <div class="update-time"><?= $u['create_time'] ?> by <?= htmlspecialchars($u['create_user']) ?></div>
                                            <div><?= htmlspecialchars($u['update_text']) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <form method="POST" class="row g-2">
                                <input type="hidden" name="action" value="add_update">
                                <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                <div class="col">
                                    <input type="text" name="update_text" class="form-control form-control-sm" placeholder="New update message..." required>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Add Update</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
