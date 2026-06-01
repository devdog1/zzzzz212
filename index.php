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
    $em->createDepartment('IT');
    $em->createType('Hardware');
    $em->createState('Open');
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
    <title>Event Management System</title>
    <style>
        body { font-family: sans-serif; margin: 20px; line-height: 1.6; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f4f4f4; }
        .form-section { background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px; }
        .update-list { font-size: 0.9em; color: #555; }
    </style>
</head>
<body>
    <h1>Event Management System</h1>

    <div class="form-section">
        <h2>Create New Event</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create_event">
            <div>
                <label>Description:</label><br>
                <textarea name="description" required></textarea>
            </div>
            <div>
                <label>Department:</label>
                <select name="department_id">
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Type:</label>
                <select name="type_id">
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>State:</label>
                <select name="state_id">
                    <?php foreach ($states as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-top: 10px;">
                <button type="submit">Create Event</button>
            </div>
        </form>
    </div>

    <h2>Events</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Time</th>
                <th>Type</th>
                <th>Dept</th>
                <th>Description</th>
                <th>State</th>
                <th>Updates</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $e): ?>
                <tr>
                    <td><?= $e['id'] ?></td>
                    <td><?= $e['create_time'] ?></td>
                    <td><?= htmlspecialchars($e['type_name']) ?></td>
                    <td><?= htmlspecialchars($e['department_name']) ?></td>
                    <td><?= htmlspecialchars($e['description']) ?></td>
                    <td><?= htmlspecialchars($e['state_name']) ?></td>
                    <td>
                        <div class="update-list">
                            <?php
                            $updates = $em->getEventUpdates($e['id']);
                            foreach ($updates as $u): ?>
                                <div>[<?= $u['create_time'] ?>] <?= htmlspecialchars($u['update_text']) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" style="margin-top:5px;">
                            <input type="hidden" name="action" value="add_update">
                            <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                            <input type="text" name="update_text" placeholder="Add update..." required>
                            <button type="submit">Add</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
