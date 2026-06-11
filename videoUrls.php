<?php

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');
require_once $docRoot . '/inc/config.php';

// Logic to force SQLite ONLY if MySQL config is missing or invalid
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

require_once 'Database.php';
require_once $docRoot . '/EventManager.php';
require_once $docRoot . '/classes/AzureADSSO.php';
require_once $docRoot . '/classes/Auth.php';
require_once $docRoot . '/classes/NavigationBuilder.php';

session_start();

$auth = new Auth($config);
$auth->requireLogin();

/*
|--------------------------------------------------------------------------
| RBAC PERMISSIONS
|--------------------------------------------------------------------------
*/

$canView = $auth->hasPermission('videoLinks.view');
$canEdit = $auth->hasPermission('videoLinks.edit');

if (!$canView) {
    http_response_code(401);
    die("Unauthorized: You do not have permission to view this page.");
}

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

$csvFile = "videoURLs.csv";

/*
|--------------------------------------------------------------------------
| LOAD DATA
|--------------------------------------------------------------------------
*/
function loadData($file) {
    $data = [];
    if (!file_exists($file)) {
        // Create it with header
        $h = fopen($file, "w");
        fputcsv($h, ["id","category","device","purpose","url"]);
        fclose($h);
        return $data;
    }

    $h = fopen($file, "r");
    fgetcsv($h); // Skip header

    while ($r = fgetcsv($h)) {
        if (!$r) continue;
        $data[] = [
            "id"       => $r[0] ?? '',
            "category" => $r[1] ?? '',
            "device"   => $r[2] ?? '',
            "purpose"  => $r[3] ?? '',
            "url"      => $r[4] ?? ''
        ];
    }

    fclose($h);
    return $data;
}

/*
|--------------------------------------------------------------------------
| SAVE DATA
|--------------------------------------------------------------------------
*/
function saveData($file, $data) {
    $h = fopen($file, "w");
    fputcsv($h, ["id","category","device","purpose","url"]);

    foreach ($data as $r) {
        fputcsv($h, $r);
    }

    fclose($h);
}

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/
$data = loadData($csvFile);
$action = $_GET['action'] ?? "";

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/
if ($action === "delete" && isset($_GET['id'])) {

    if (!$canEdit) {
        http_response_code(403);
        die("Unauthorized");
    }

    $data = array_filter($data, fn($r) => $r["id"] != $_GET["id"]);
    saveData($csvFile, $data);

    header("Location: videoUrls.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SAVE (ADD / EDIT)
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save'])) {

    if (!$canEdit) {
        http_response_code(403);
        die("Unauthorized");
    }

    $id = $_POST['id'] ?: time();

    $row = [
        "id"       => $id,
        "category" => $_POST['category'],
        "device"   => $_POST['device'],
        "purpose"  => $_POST['purpose'],
        "url"      => $_POST['url']
    ];

    $found = false;

    foreach ($data as &$d) {
        if ($d["id"] == $id) {
            $d = $row;
            $found = true;
        }
    }

    if (!$found) {
        $data[] = $row;
    }

    saveData($csvFile, $data);

    header("Location: videoUrls.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| IMPORT CSV
|--------------------------------------------------------------------------
*/
if (isset($_POST['import'])) {

    if (!$canEdit) {
        http_response_code(403);
        die("Unauthorized");
    }

    if (!empty($_FILES['csv_file']['tmp_name'])) {

        $in = fopen($_FILES['csv_file']['tmp_name'], "r");
        $out = fopen($csvFile, "w");

        fputcsv($out, ["id","category","device","purpose","url"]);
        fgetcsv($in);

        while ($r = fgetcsv($in)) {
            fputcsv($out, $r);
        }

        fclose($in);
        fclose($out);
    }

    header("Location: videoUrls.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| EXPORT CSV
|--------------------------------------------------------------------------
*/
if (isset($_GET['export'])) {

    if (!$canEdit) {
        http_response_code(403);
        die("Unauthorized");
    }

    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=videoURLs_export.csv");

    $out = fopen("php://output", "w");
    fputcsv($out, ["id","category","device","purpose","url"]);

    foreach ($data as $r) {
        fputcsv($out, $r);
    }

    fclose($out);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video URL Manager - Incident Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        html { scroll-behavior: smooth; }
        body { background-color: #f8f9fa; }
        #sidebarNav { position: sticky; top: 10px; max-height: 80vh; overflow-y: auto; }
        .category-link { display:block; padding:6px 10px; margin-bottom:5px; background:#fff; border-radius:6px; text-decoration:none; color: #444; border: 1px solid #dee2e6; }
        .category-link:hover { background:#e9ecef; }
        .accordion-button:not(.collapsed) { background-color: #e7f1ff; color: #0c63e4; }
    </style>
</head>
<body>

<?php
$em = new EventManager($auth->user()['name'] ?? 'System', $auth);
$nav = new NavigationBuilder($em->db, $auth);
echo $nav->render();
?>

<div class="container-fluid mt-4 px-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0 fw-bold">Video URL Manager</h4>
            <div class="text-muted small">Manage operational video stream and dashboard URLs</div>
        </div>
        <div>
            <?php if ($canEdit): ?>
                <a href="?export=1" class="btn btn-outline-success btn-sm me-2">
                    <i class="bi bi-download"></i> Export CSV
                </a>
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="bi bi-upload"></i> Import CSV
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $grouped = [];
    foreach ($data as $d) {
        $grouped[$d['category']][] = $d;
    }
    ksort($grouped);
    ?>

    <!-- CONTROLS -->
    <div class="card mb-4 shadow-sm border-0">
        <div class="card-body d-flex justify-content-between align-items-center py-2">
            <div>
                <button class="btn btn-sm btn-primary px-3" onclick="expandAll()">Expand All</button>
                <button class="btn btn-sm btn-outline-secondary px-3" onclick="collapseAll()">Collapse All</button>
            </div>
            <div class="text-muted small">
                <strong>Categories:</strong> <?= count($grouped) ?> | <strong>Total URLs:</strong> <?= count($data) ?>
            </div>
        </div>
    </div>

    <div class="row">

        <!-- SIDEBAR -->
        <div class="col-md-3">
            <div id="sidebarNav" class="card shadow-sm border-0 p-3 mb-4">
                <h6 class="fw-bold mb-3">Categories</h6>
                <?php
                $i=0;
                foreach($grouped as $cat=>$items):
                    $target="cat_".$i++;
                ?>
                <a class="category-link" href="#<?= $target ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <span><?= htmlspecialchars($cat) ?></span>
                        <span class="badge bg-light text-dark border"><?= count($items) ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- MAIN -->
        <div class="col-md-9">

            <!-- ADD FORM -->
            <?php if ($canEdit): ?>
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold">Add New Entry</h6>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="save" value="1">
                        <div class="col-md-3">
                            <input class="form-control form-control-sm" name="category" placeholder="Category (e.g. Server Room)" required>
                        </div>
                        <div class="col-md-2">
                            <input class="form-control form-control-sm" name="device" placeholder="Device/Camera" required>
                        </div>
                        <div class="col-md-3">
                            <input class="form-control form-control-sm" name="purpose" placeholder="Purpose" required>
                        </div>
                        <div class="col-md-3">
                            <input class="form-control form-control-sm" name="url" placeholder="URL" required>
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-primary btn-sm w-100">Add</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- ACCORDION -->
            <div class="accordion mb-5" id="videoAccordion">
                <?php
                $i=0;
                foreach($grouped as $category=>$items):
                $collapseId="cat_".$i++;
                ?>
                <div class="accordion-item shadow-sm border-0 mb-3 overflow-hidden" id="<?= $collapseId ?>" style="border-radius: 0.5rem;">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-3 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#c<?= $collapseId ?>">
                            <?= htmlspecialchars($category) ?>
                            <span class="badge bg-secondary ms-2"><?= count($items) ?></span>
                        </button>
                    </h2>
                    <div id="c<?= $collapseId ?>" class="accordion-collapse collapse" data-bs-parent="#videoAccordion">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-4">Device</th>
                                            <th>Purpose</th>
                                            <th>URL</th>
                                            <?php if ($canEdit): ?><th class="text-end pe-4">Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($items as $d): ?>
                                        <tr>
                                            <td class="ps-4 fw-semibold"><?= htmlspecialchars($d["device"]) ?></td>
                                            <td><?= htmlspecialchars($d["purpose"]) ?></td>
                                            <td>
                                                <a href="<?= htmlspecialchars($d["url"]) ?>" target="_blank" class="text-truncate d-inline-block" style="max-width: 300px;">
                                                    <?= htmlspecialchars($d["url"]) ?>
                                                </a>
                                            </td>
                                            <?php if ($canEdit): ?>
                                            <td class="text-end pe-4">
                                                <a class="btn btn-sm btn-outline-danger" href="?action=delete&id=<?= $d["id"] ?>" onclick="return confirm('Are you sure you want to delete this record?')">
                                                   <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Import Video URLs</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Select a CSV file to import. The first row should be headers: id,category,device,purpose,url</p>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="import" class="btn btn-primary">Import CSV</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function expandAll(){
    document.querySelectorAll('.accordion-collapse').forEach(el=>{
        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(el);
        bsCollapse.show();
    });
}

function collapseAll(){
    document.querySelectorAll('.accordion-collapse.show').forEach(el=>{
        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(el);
        bsCollapse.hide();
    });
}
</script>
</body>
</html>
