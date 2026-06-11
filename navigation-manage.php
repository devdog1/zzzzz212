<?php
require_once __DIR__ . "/autoload.php";
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');
require_once $docRoot . '/inc/config.php';

// Logic to force SQLite ONLY if MySQL config is missing or invalid
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

$auth = new Auth($config);
$auth->requireLogin();
if (!$auth->hasPermission('admin.panel')) {
    http_response_code(401);
    exit("Unauthorized");
}

$em = new EventManager($auth->user()['name'], $auth);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $em->createNavigation($_POST);
        $message = "Navigation item created.";
    } elseif ($action === 'update') {
        $em->updateNavigation($_POST['id'], $_POST);
        $message = "Navigation item updated.";
    } elseif ($action === 'delete') {
        $em->deleteNavigation($_POST['id']);
        $message = "Navigation item deleted.";
    }
}

$allItems = $em->listAllNavigation();
$parentItems = array_filter($allItems, function($i) { return empty($i['parent_id']); });

function getChildren($items, $parentId) {
    return array_filter($items, function($i) use ($parentId) { return $i['parent_id'] == $parentId; });
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Navigation Management - Incident Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .nav-card { border-left: 5px solid #0d6efd; transition: transform 0.1s; }
        .nav-card:hover { transform: scale(1.01); }
        .child-row { margin-left: 3rem; border-left: 2px dashed #dee2e6; padding-left: 1rem; }
    </style>
</head>
<body>

<?php
$nav = new NavigationBuilder($em->db, $auth);
echo $nav->render();
?>

<div class="container pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Navigation Management</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-add">Add Top-Level Item</button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="navigation-tree">
        <?php foreach ($parentItems as $p):
            $children = getChildren($allItems, $p['id']);
        ?>
            <div class="card shadow-sm mb-3 nav-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-secondary me-2">ID: <?= $p['id'] ?></span>
                            <span class="fw-bold fs-5"><?= htmlspecialchars($p['label']) ?></span>
                            <code class="ms-3 text-muted"><?= htmlspecialchars($p['url']) ?></code>
                            <span class="ms-3 badge bg-light text-dark border"><?= $p['alignment'] ?></span>
                            <span class="ms-1 badge bg-info text-dark">Weight: <?= $p['weight'] ?></span>
                            <?php if ($p['permission']): ?><span class="ms-1 badge bg-warning text-dark"><?= $p['permission'] ?></span><?php endif; ?>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modal-edit-<?= $p['id'] ?>">Edit</button>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-add-child-<?= $p['id'] ?>">Add Child</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Children -->
            <?php foreach ($children as $c): ?>
                <div class="child-row mb-2">
                    <div class="card shadow-sm border-0 bg-white">
                        <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-light text-muted me-2"><?= $c['id'] ?></span>
                                <strong><?= htmlspecialchars($c['label']) ?></strong>
                                <code class="ms-3 small text-muted"><?= htmlspecialchars($c['url']) ?></code>
                            </div>
                            <div>
                                <button class="btn btn-link btn-sm text-dark" data-bs-toggle="modal" data-bs-target="#modal-edit-<?= $c['id'] ?>">Edit</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <hr class="my-4 opacity-25">
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal: Add Top-Level -->
<div class="modal fade" id="modal-add" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-header"><h5>Add Navigation Item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <?php renderNavForm(); ?>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Create Item</button></div>
        </form>
    </div>
</div>

<!-- Dynamic Modals for Edit and Add Child -->
<?php foreach ($allItems as $item): ?>
    <!-- Edit Modal -->
    <div class="modal fade" id="modal-edit-<?= $item['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <form class="modal-content" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <div class="modal-header"><h5>Edit Item #<?= $item['id'] ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><?php renderNavForm($item); ?></div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="submit" name="action" value="delete" class="btn btn-danger" onclick="return confirm('Really delete?')">Delete</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Child Modal -->
    <div class="modal fade" id="modal-add-child-<?= $item['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <form class="modal-content" method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="parent_id" value="<?= $item['id'] ?>">
                <div class="modal-header"><h5>Add Sub-item to <?= htmlspecialchars($item['label']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><?php renderNavForm(['alignment' => $item['alignment'], 'permission' => $item['permission']]); ?></div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary">Create Sub-item</button></div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php
function renderNavForm($val = []) {
    ?>
    <div class="mb-3">
        <label class="form-label small fw-bold">Label</label>
        <input type="text" name="label" class="form-control" value="<?= htmlspecialchars($val['label'] ?? '') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label small fw-bold">URL</label>
        <input type="text" name="url" class="form-control" value="<?= htmlspecialchars($val['url'] ?? '') ?>" required>
    </div>
    <div class="row mb-3">
        <div class="col">
            <label class="form-label small fw-bold">Alignment</label>
            <select name="alignment" class="form-select">
                <option value="left" <?= ($val['alignment'] ?? '') === 'left' ? 'selected' : '' ?>>Left</option>
                <option value="right" <?= ($val['alignment'] ?? '') === 'right' ? 'selected' : '' ?>>Right</option>
            </select>
        </div>
        <div class="col">
            <label class="form-label small fw-bold">Weight</label>
            <input type="number" name="weight" class="form-control" value="<?= $val['weight'] ?? 0 ?>">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label small fw-bold">Permission Required (Optional)</label>
        <input type="text" name="permission" class="form-control" value="<?= htmlspecialchars($val['permission'] ?? '') ?>" placeholder="e.g. admin.panel">
    </div>
    <div class="form-check mb-3">
        <input type="hidden" name="is_external" value="0">
        <input type="checkbox" name="is_external" value="1" class="form-check-input" id="ext-<?= rand() ?>" <?= ($val['is_external'] ?? 0) ? 'checked' : '' ?>>
        <label class="form-check-label small" for="ext">Is External Link (Opens in new tab)</label>
    </div>
    <?php
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
