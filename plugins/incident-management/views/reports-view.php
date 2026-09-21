<?php
// Reports View for Incident Management Plugin

if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_view_events</code></div>';
    return;
}

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'User');
$em = new EventManager($currentUser);

$reportType = $_GET['type'] ?? 'dept_impact';
$dateFrom   = $_GET['date_from'] ?? null;
$dateTo     = $_GET['date_to'] ?? null;

$reportData = $em->getReportData($reportType, $dateFrom, $dateTo);

$reportNames = [
    'dept_impact'    => 'Impact Score by Department',
    'type_freq'      => 'Incident Frequency by Type',
    'service_impact' => 'Impact Score by Affected Service',
    'location'       => 'Incidents by Location (Area)',
    'tag_usage'      => 'Tag Popularity'
];

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=incident_report_' . $reportType . '_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Category / Label', 'Value']);
    foreach ($reportData as $row) {
        fputcsv($output, [$row['label'], $row['value']]);
    }
    fclose($output);
    exit;
}
?>

<div class="row mb-4 text-start">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-file-contract text-primary me-2"></i>Incident Reporting</h1>
        <p class="text-muted">Analyze incident distribution across departments, services, geographical areas, and tags over custom date ranges.</p>
    </div>
</div>

<div class="card shadow-sm border mb-4 text-start">
    <div class="card-body">
        <form method="GET" action="index.php" class="row g-3 align-items-end">
            <input type="hidden" name="route" value="incident_reports">
            <input type="hidden" name="type" value="<?= htmlspecialchars($reportType) ?>">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo ?? '') ?>">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-dark btn-sm flex-grow-1 fw-bold"><i class="fa-solid fa-filter me-1"></i>Filter Report</button>
                <a href="<?= url_for('incident_reports') ?>&type=<?= urlencode($reportType) ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="row text-start">
    <div class="col-md-3 mb-4">
        <div class="list-group shadow-sm border mb-3">
            <div class="list-group-item list-group-item-dark fw-bold"><i class="fa-solid fa-chart-column me-2"></i>Available Reports</div>
            <?php foreach($reportNames as $key => $name): ?>
                <a href="<?= url_for('incident_reports') ?>&type=<?= $key ?><?= $dateFrom ? '&date_from='.urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to='.urlencode($dateTo) : '' ?>"
                   class="list-group-item list-group-item-action <?= $reportType === $key ? 'active fw-bold' : '' ?>">
                    <?= $name ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-md-9 mb-4">
        <div class="card shadow-sm border">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                <h5 class="mb-0 fs-6 fw-bold text-dark"><i class="fa-solid fa-table me-2 text-primary"></i><?= $reportNames[$reportType] ?? 'Report' ?></h5>
                <div>
                    <?php
                    $csvParams = $_GET;
                    $csvParams['export'] = 'csv';
                    $csvUrl = 'index.php?' . http_build_query($csvParams);
                    ?>
                    <a href="<?= $csvUrl ?>" class="btn btn-xs btn-success me-2 fw-bold"><i class="fa-solid fa-file-csv me-1"></i>CSV Export</a>
                    <span class="badge bg-secondary"><?= count($reportData) ?> Rows</span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($reportData)): ?>
                    <div class="p-4 text-center text-muted small"><i class="fa-solid fa-circle-info me-1"></i>No data available for the selected filters.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Category</th>
                                    <th class="text-end">Value</th>
                                    <th style="width: 40%">Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $max = 0;
                                foreach($reportData as $row) { if ($row['value'] > $max) $max = $row['value']; }
                                foreach($reportData as $row):
                                    $pct = $max > 0 ? ($row['value'] / $max) * 100 : 0;
                                ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($row['label']) ?></td>
                                        <td class="text-end fw-bold text-primary"><?= number_format($row['value']) ?></td>
                                        <td>
                                            <div class="progress" style="height: 12px;">
                                                <div class="progress-bar bg-primary" style="width: <?= $pct ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
