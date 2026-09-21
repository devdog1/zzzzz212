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
    } elseif ($action === 'toggle_dept') {
        $em->toggleDepartment($_POST['id'], (int)($_POST['is_disabled'] ?? 0));
        $message = "Department status updated successfully.";
    } elseif ($action === 'create_type') {
        $em->createType($_POST['name']);
        $message = "Type created successfully.";
    } elseif ($action === 'toggle_type') {
        $em->toggleType($_POST['id'], (int)($_POST['is_disabled'] ?? 0));
        $message = "Type status updated successfully.";
    } elseif ($action === 'create_state') {
        $em->createState($_POST['name']);
        $message = "State created successfully.";
    } elseif ($action === 'toggle_state') {
        $em->toggleState($_POST['id'], (int)($_POST['is_disabled'] ?? 0));
        $message = "State status updated successfully.";
    } elseif ($action === 'create_service') {
        $em->createService($_POST['name']);
        $message = "Service created successfully.";
    } elseif ($action === 'toggle_service') {
        $em->toggleService($_POST['id'], (int)($_POST['is_disabled'] ?? 0));
        $message = "Service status updated successfully.";
    } elseif ($action === 'create_tag') {
        $em->createTag($_POST['name']);
        $message = "Tag created successfully.";
    } elseif ($action === 'toggle_tag') {
        $em->toggleTag($_POST['id'], (int)($_POST['is_disabled'] ?? 0));
        $message = "Tag status updated successfully.";
    } elseif ($action === 'create_area') {
        $em->createArea($_POST['name']);
        $message = "Area created successfully.";
    } elseif ($action === 'toggle_area') {
        $em->toggleArea($_POST['id'], (int)($_POST['is_disabled'] ?? 0));
        $message = "Area status updated successfully.";
    }

    $emError = $em->getLastError();
}

$departments = $em->listDepartments(true, true);
$types = $em->listTypes(true);
$states = $em->listStates(true);
$services = $em->listServices(true);
$tags = $em->listAllTags(true);
$areas = $em->listAllAreas(true);

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
        <p class="text-muted">Manage system departments, Azure AD group assignments, incident types, states, services, tags, and geographical areas. Disabled reference data will be hidden from new incident selection dropdowns while preserving historical records.</p>
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
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $d):
                            $formId = "update-dept-" . $d['id'];
                            $isDisabled = !empty($d['is_disabled']);
                        ?>
                            <tr class="<?= $isDisabled ? 'table-secondary text-muted' : '' ?>">
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
                                    <?php if ($isDisabled): ?>
                                        <span class="badge bg-secondary">Disabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button type="submit" form="<?= $formId ?>" class="btn btn-sm btn-success fw-bold"><i class="fa-solid fa-check me-1"></i>Save</button>
                                        <form method="POST">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_dept">
                                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                            <input type="hidden" name="is_disabled" value="<?= $isDisabled ? 0 : 1 ?>">
                                            <button type="submit" class="btn btn-sm <?= $isDisabled ? 'btn-outline-success' : 'btn-outline-warning' ?>" title="<?= $isDisabled ? 'Enable' : 'Disable' ?>">
                                                <i class="fa-solid <?= $isDisabled ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                                <?= $isDisabled ? 'Enable' : 'Disable' ?>
                                            </button>
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
                <ul class="list-group list-group-flush small overflow-auto" style="max-height: 220px;">
                    <?php foreach ($types as $t):
                        $dis = !empty($t['is_disabled']);
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1 <?= $dis ? 'text-muted' : '' ?>">
                            <span class="<?= $dis ? 'text-decoration-line-through' : '' ?>"><?= htmlspecialchars($t['name']) ?></span>
                            <form method="POST" class="m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_type">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <input type="hidden" name="is_disabled" value="<?= $dis ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-xs <?= $dis ? 'btn-outline-success' : 'btn-outline-warning' ?> border-0" title="<?= $dis ? 'Enable' : 'Disable' ?>">
                                    <i class="fa-solid <?= $dis ? 'fa-toggle-off text-secondary' : 'fa-toggle-on text-warning' ?>"></i>
                                </button>
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
                <ul class="list-group list-group-flush small overflow-auto" style="max-height: 220px;">
                    <?php foreach ($states as $s):
                        $dis = !empty($s['is_disabled']);
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1 <?= $dis ? 'text-muted' : '' ?>">
                            <span class="<?= $dis ? 'text-decoration-line-through' : '' ?>"><?= htmlspecialchars($s['name']) ?></span>
                            <form method="POST" class="m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_state">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <input type="hidden" name="is_disabled" value="<?= $dis ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-xs <?= $dis ? 'btn-outline-success' : 'btn-outline-warning' ?> border-0" title="<?= $dis ? 'Enable' : 'Disable' ?>">
                                    <i class="fa-solid <?= $dis ? 'fa-toggle-off text-secondary' : 'fa-toggle-on text-warning' ?>"></i>
                                </button>
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
                <ul class="list-group list-group-flush small overflow-auto" style="max-height: 220px;">
                    <?php foreach ($services as $svc):
                        $dis = !empty($svc['is_disabled']);
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1 <?= $dis ? 'text-muted' : '' ?>">
                            <span class="<?= $dis ? 'text-decoration-line-through' : '' ?>"><?= htmlspecialchars($svc['name']) ?></span>
                            <form method="POST" class="m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_service">
                                <input type="hidden" name="id" value="<?= $svc['id'] ?>">
                                <input type="hidden" name="is_disabled" value="<?= $dis ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-xs <?= $dis ? 'btn-outline-success' : 'btn-outline-warning' ?> border-0" title="<?= $dis ? 'Enable' : 'Disable' ?>">
                                    <i class="fa-solid <?= $dis ? 'fa-toggle-off text-secondary' : 'fa-toggle-on text-warning' ?>"></i>
                                </button>
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
                <div class="d-flex flex-wrap gap-1 overflow-auto" style="max-height: 220px;">
                    <?php foreach ($tags as $t):
                        $dis = !empty($t['is_disabled']);
                    ?>
                        <span class="badge <?= $dis ? 'bg-secondary text-light' : 'bg-light text-dark border' ?> p-2 d-inline-flex align-items-center gap-1">
                            #<span class="<?= $dis ? 'text-decoration-line-through' : '' ?>"><?= htmlspecialchars($t['name']) ?></span>
                            <form method="POST" class="m-0 d-inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_tag">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <input type="hidden" name="is_disabled" value="<?= $dis ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-xs border-0 p-0 ms-1 <?= $dis ? 'text-light' : 'text-warning' ?>" title="<?= $dis ? 'Enable' : 'Disable' ?>">
                                    <i class="fa-solid <?= $dis ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                </button>
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
                <div class="d-flex flex-wrap gap-1 overflow-auto" style="max-height: 220px;">
                    <?php foreach ($areas as $a):
                        $dis = !empty($a['is_disabled']);
                    ?>
                        <span class="badge <?= $dis ? 'bg-secondary text-light' : 'bg-light text-dark border' ?> p-2 d-inline-flex align-items-center gap-1">
                            <span class="<?= $dis ? 'text-decoration-line-through' : '' ?>"><?= htmlspecialchars($a['name']) ?></span>
                            <form method="POST" class="m-0 d-inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_area">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <input type="hidden" name="is_disabled" value="<?= $dis ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-xs border-0 p-0 ms-1 <?= $dis ? 'text-light' : 'text-warning' ?>" title="<?= $dis ? 'Enable' : 'Disable' ?>">
                                    <i class="fa-solid <?= $dis ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                </button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
