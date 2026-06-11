<?php
require_once __DIR__ . "/autoload.php";
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

$configPath = $docRoot . '/inc/config.php';
$hasConfig = file_exists($configPath);
$config = [];
if ($hasConfig) {
    require $configPath;
}

$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
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
    if (!$auth_obj->hasPermission('admin.panel')) {
        http_response_code(401);
        echo "Error 401 Unauthorized: You need authorization (admin.panel) to access this page.";
        exit();
    }
}
$em = new EventManager($currentUser, $auth_obj);
$emError = null;

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_dept') {
            $em->createDepartment($_POST['name'], $_POST['azure_group_id']);
            $message = "Department created successfully.";
        } elseif ($_POST['action'] === 'update_dept') {
            $em->updateDepartment($_POST['id'], [
                'name' => $_POST['name'],
                'azure_group_id' => $_POST['azure_group_id']
            ]);
            $message = "Department updated successfully.";
        } elseif ($_POST['action'] === 'delete_dept') {
            $em->deleteDepartment($_POST['id']);
            $message = "Department deleted successfully.";
        }
        $emError = $em->getLastError();
    }
}

$departments = $em->listDepartments(true);
$azureGroups = [];

if (!$useSqlite && $auth_obj) {
    $token = $auth_obj->getAccessToken();
    if ($token) {
        $azureGroups = $auth_obj->getSSO()->getAllGroups($token);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Departments - Incident Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
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
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Add Department</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_dept">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Department Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Azure AD Group ID (Optional)</label>
                            <?php if (!empty($azureGroups)): ?>
                                <select name="azure_group_id" class="form-select">
                                    <option value="">-- None --</option>
                                    <?php foreach ($azureGroups as $g): ?>
                                        <option value="<?= htmlspecialchars($g['id']) ?>"><?= htmlspecialchars($g['displayName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="azure_group_id" class="form-control" placeholder="e.g. 00000000-0000-0000-0000-000000000000">
                                <small class="text-muted">Teams chat members will be synced from this group.</small>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Add Department</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Existing Departments</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Azure Group ID</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($departments as $d):
                                $formId = "update-dept-" . $d['id'];
                            ?>
                                <tr>
                                    <td>
                                        <?= $d['id'] ?>
                                        <form method="POST" id="<?= $formId ?>">
                                            <input type="hidden" name="action" value="update_dept">
                                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        </form>
                                    </td>
                                    <td>
                                        <input type="text" name="name" form="<?= $formId ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($d['name']) ?>" required>
                                    </td>
                                    <td>
                                            <?php
                                            $memberList = "";
                                            if (!empty($d['members'])) {
                                                $names = array_column($d['members'], 'displayName');
                                                $memberList = implode(", ", $names);
                                            }
                                            ?>
                                            <?php if (!empty($azureGroups)): ?>
                                                <select name="azure_group_id" form="<?= $formId ?>" class="form-select form-select-sm" title="<?= htmlspecialchars($memberList) ?>">
                                                    <option value="">-- None --</option>
                                                    <?php foreach ($azureGroups as $g): ?>
                                                        <option value="<?= htmlspecialchars($g['id']) ?>" <?= $d['azure_group_id'] == $g['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($g['id']) ?> (<?= htmlspecialchars($g['displayName']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="text" name="azure_group_id" form="<?= $formId ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($d['azure_group_id'] ?? '') ?>" title="<?= htmlspecialchars($memberList) ?>">
                                            <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex">
                                            <button type="submit" form="<?= $formId ?>" class="btn btn-sm btn-success me-1">Save</button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');">
                                                <input type="hidden" name="action" value="delete_dept">
                                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">
                <h6>Audit History (Departments)</h6>
                <div class="audit-list overflow-auto" style="max-height: 300px;">
                    <?php
                    $audits = $em->getAuditTrail('department');
                    foreach ($audits as $audit):
                    ?>
                        <div class="mb-2 p-2 bg-white border rounded small">
                            <div class="text-muted" style="font-size:0.7rem;"><?= $audit['timestamp'] ?> by <?= htmlspecialchars($audit['user']) ?> (ID: <?= $audit['record_id'] ?>)</div>
                            <div><strong>Action:</strong> <?= htmlspecialchars($audit['action']) ?></div>
                            <?php if ($audit['action'] === 'UPDATE'):
                                $old = json_decode($audit['old_values'], true);
                                $new = json_decode($audit['new_values'], true);
                            ?>
                                <?php foreach ($new as $key => $val):
                                    $oldVal = $old[$key] ?? null;
                                    if (json_encode($oldVal) === json_encode($val)) continue;
                                ?>
                                    <div>
                                        <span class="fw-bold"><?= htmlspecialchars($key) ?>:</span>
                                        <span class="text-decoration-line-through text-muted"><?= htmlspecialchars($oldVal) ?></span>
                                        &rarr;
                                        <span><?= htmlspecialchars($val) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($audit['action'] === 'CREATE'):
                                $new = json_decode($audit['new_values'], true);
                            ?>
                                <div><strong>Values:</strong> <?= htmlspecialchars(json_encode($new)) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
