<?php
// Departments & Reference Data View for Incident Management Plugin

if (!has_permission('incident_management_manage_departments') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_manage_departments</code></div>';
    return;
}

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'User');
$em = new EventManager($currentUser);
$emError = null;

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_dept') {
        $em->createDepartment($_POST['name'] ?? '', $_POST['azure_group_id'] ?? null);
        $message = "Department created successfully.";
    } elseif ($action === 'update_dept') {
        $em->updateDepartment($_POST['id'], [
            'name' => $_POST['name'],
            'azure_group_id' => $_POST['azure_group_id']
        ]);
        $message = "Department updated successfully.";
    } elseif ($action === 'delete_dept') {
        $em->deleteDepartment($_POST['id']);
        $message = "Department deleted successfully.";
    } elseif ($action === 'create_type') {
        $em->createType($_POST['name']);
        $message = "Type created successfully.";
    } elseif ($action === 'delete_type') {
        $em->deleteType($_POST['id']);
        $message = "Type deleted successfully.";
    } elseif ($action === 'create_state') {
        $em->createState($_POST['name']);
        $message = "State created successfully.";
    } elseif ($action === 'delete_state') {
        $em->deleteState($_POST['id']);
        $message = "State deleted successfully.";
    } elseif ($action === 'create_service') {
        $em->createService($_POST['name']);
        $message = "Service created successfully.";
    } elseif ($action === 'delete_service') {
        $em->deleteService($_POST['id']);
        $message = "Service deleted successfully.";
    } elseif ($action === 'create_tag') {
        $em->createTag($_POST['name']);
        $message = "Tag created successfully.";
    } elseif ($action === 'delete_tag') {
        $em->deleteTag($_POST['id']);
        $message = "Tag deleted successfully.";
    } elseif ($action === 'create_area') {
        $em->createArea($_POST['name']);
        $message = "Area created successfully.";
    } elseif ($action === 'delete_area') {
        $em->deleteArea($_POST['id']);
        $message = "Area deleted successfully.";
    }

    $emError = $em->getLastError();
}

$departments = $em->listDepartments(true);
$types = $em->listTypes();
$states = $em->listStates();
$services = $em->listServices();
$tags = $em->listAllTags();
$areas = $em->listAllAreas();

$azureGroups = [];
if (method_exists(get_auth(), 'getAccessToken')) {
    $token = get_auth()->getAccessToken();
    if ($token && method_exists(get_auth(), 'getSSO')) {
        $azureGroups = get_auth()->getSSO()->getAllGroups($token) ?? [];
    }
}
?>

<div class="row mb-4 text-start">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-sitemap text-primary me-2"></i>Reference Data & Departments</h1>
        <p class="text-muted">Manage system departments, Azure AD group assignments, incident types, states, services, tags, and geographical areas.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm text-start" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($emError): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm text-start" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($emError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row text-start">
    <!-- Department Creation & List -->
    <div class="col-md-4 mb-4">
        <div class="card shadow-sm border mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                <i class="fa-solid fa-plus me-1"></i>Add Department
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_dept">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Department Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Azure AD Group ID (Optional)</label>
                        <?php if (!empty($azureGroups)): ?>
                            <select name="azure_group_id" class="form-select form-select-sm">
                                <option value="">-- None --</option>
                                <?php foreach ($azureGroups as $g): ?>
                                    <option value="<?= htmlspecialchars($g['id']) ?>"><?= htmlspecialchars($g['displayName']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="azure_group_id" class="form-control form-control-sm" placeholder="e.g. 00000000-0000-0000-0000-000000000000">
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold"><i class="fa-solid fa-save me-1"></i>Add Department</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8 mb-4">
        <div class="card shadow-sm border">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="fa-solid fa-building me-2"></i>Existing Departments
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
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
                                    #<?= $d['id'] ?>
                                    <form method="POST" id="<?= $formId ?>">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_dept">
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    </form>
                                </td>
                                <td>
                                    <input type="text" name="name" form="<?= $formId ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($d['name']) ?>" required>
                                </td>
                                <td>
                                    <input type="text" name="azure_group_id" form="<?= $formId ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($d['azure_group_id'] ?? '') ?>">
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button type="submit" form="<?= $formId ?>" class="btn btn-sm btn-success fw-bold"><i class="fa-solid fa-check me-1"></i>Save</button>
                                        <form method="POST" onsubmit="return confirm('Delete this department?');">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_dept">
                                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Reference Tables Overview (Types, States, Services, Tags, Areas) -->
<div class="row text-start g-4">
    <!-- Types -->
    <div class="col-md-4">
        <div class="card shadow-sm border h-100">
            <div class="card-header bg-light fw-bold text-dark d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-tag me-1 text-info"></i>Incident Types</span>
                <span class="badge bg-secondary"><?= count($types) ?></span>
            </div>
            <div class="card-body p-3">
                <form method="POST" class="d-flex gap-2 mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_type">
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="New type name..." required>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
                </form>
                <ul class="list-group list-group-flush small overflow-auto" style="max-height: 200px;">
                    <?php foreach ($types as $t): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1">
                            <span><?= htmlspecialchars($t['name']) ?></span>
                            <form method="POST" class="m-0" onsubmit="return confirm('Delete type?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_type">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-xs text-danger border-0"><i class="fa-solid fa-trash-can"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- States -->
    <div class="col-md-4">
        <div class="card shadow-sm border h-100">
            <div class="card-header bg-light fw-bold text-dark d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-circle-nodes me-1 text-warning"></i>Incident States</span>
                <span class="badge bg-secondary"><?= count($states) ?></span>
            </div>
            <div class="card-body p-3">
                <form method="POST" class="d-flex gap-2 mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_state">
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="New state name..." required>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
                </form>
                <ul class="list-group list-group-flush small overflow-auto" style="max-height: 200px;">
                    <?php foreach ($states as $s): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1">
                            <span><?= htmlspecialchars($s['name']) ?></span>
                            <form method="POST" class="m-0" onsubmit="return confirm('Delete state?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_state">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-xs text-danger border-0"><i class="fa-solid fa-trash-can"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Services -->
    <div class="col-md-4">
        <div class="card shadow-sm border h-100">
            <div class="card-header bg-light fw-bold text-dark d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-server me-1 text-success"></i>Services</span>
                <span class="badge bg-secondary"><?= count($services) ?></span>
            </div>
            <div class="card-body p-3">
                <form method="POST" class="d-flex gap-2 mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_service">
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="New service name..." required>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
                </form>
                <ul class="list-group list-group-flush small overflow-auto" style="max-height: 200px;">
                    <?php foreach ($services as $svc): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1">
                            <span><?= htmlspecialchars($svc['name']) ?></span>
                            <form method="POST" class="m-0" onsubmit="return confirm('Delete service?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_service">
                                <input type="hidden" name="id" value="<?= $svc['id'] ?>">
                                <button type="submit" class="btn btn-xs text-danger border-0"><i class="fa-solid fa-trash-can"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Tags -->
    <div class="col-md-6">
        <div class="card shadow-sm border h-100">
            <div class="card-header bg-light fw-bold text-dark d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-tags me-1 text-primary"></i>Tags</span>
                <span class="badge bg-secondary"><?= count($tags) ?></span>
            </div>
            <div class="card-body p-3">
                <form method="POST" class="d-flex gap-2 mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_tag">
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="New tag name..." required>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
                </form>
                <div class="d-flex flex-wrap gap-1 overflow-auto" style="max-height: 200px;">
                    <?php foreach ($tags as $t): ?>
                        <span class="badge bg-light text-dark border p-2 d-inline-flex align-items-center gap-1">
                            #<?= htmlspecialchars($t['name']) ?>
                            <form method="POST" class="m-0 d-inline" onsubmit="return confirm('Delete tag?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_tag">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-xs text-danger border-0 p-0 ms-1">&times;</button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Areas -->
    <div class="col-md-6">
        <div class="card shadow-sm border h-100">
            <div class="card-header bg-light fw-bold text-dark d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-location-dot me-1 text-danger"></i>Geographical Areas</span>
                <span class="badge bg-secondary"><?= count($areas) ?></span>
            </div>
            <div class="card-body p-3">
                <form method="POST" class="d-flex gap-2 mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_area">
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="New area name..." required>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
                </form>
                <div class="d-flex flex-wrap gap-1 overflow-auto" style="max-height: 200px;">
                    <?php foreach ($areas as $a): ?>
                        <span class="badge bg-light text-dark border p-2 d-inline-flex align-items-center gap-1">
                            <?= htmlspecialchars($a['name']) ?>
                            <form method="POST" class="m-0 d-inline" onsubmit="return confirm('Delete area?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_area">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-xs text-danger border-0 p-0 ms-1">&times;</button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
