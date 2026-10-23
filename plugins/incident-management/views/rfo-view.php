<?php
// RFO / Incident Report View for Incident Management Plugin

if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_view_events</code></div>';
    return;
}

require_once __DIR__ . '/../models/rfo_functions.php';

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('validate_csrf')) {
        validate_csrf();
    }
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_rfo') {
            $doc_id = incident_create_rfo_report($_POST);
            $message = "Reason for Outage (RFO) report created successfully.";
            $_GET['id'] = $doc_id;
        } elseif ($action === 'update_rfo') {
            $doc_id = (int)$_POST['document_id'];
            incident_save_rfo_details($doc_id, $_POST);
            $message = "RFO Incident Report details saved successfully.";
            $_GET['id'] = $doc_id;
        } elseif ($action === 'add_timeline') {
            $doc_id = (int)$_POST['document_id'];
            incident_add_rfo_timeline_entry(
                $doc_id,
                $_POST['timestamp'] ?? date('Y-m-d H:i:s'),
                $_POST['event'] ?? '',
                $_POST['person'] ?? '',
                $_POST['source'] ?? '',
                $_POST['notes'] ?? ''
            );
            $message = "Timeline entry added successfully.";
            $_GET['id'] = $doc_id;
        } elseif ($action === 'delete_rfo') {
            $doc_id = (int)$_POST['document_id'];
            incident_delete_rfo($doc_id);
            $message = "RFO report deleted successfully.";
            unset($_GET['id']);
        }
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'danger';
    }
}

$selected_id = (int)($_GET['id'] ?? 0);
$all_rfos = incident_search_rfos();

$selected_rfo = null;
if ($selected_id > 0) {
    $selected_rfo = incident_get_rfo_details($selected_id);
}
if (!$selected_rfo && !empty($all_rfos)) {
    $selected_rfo = $all_rfos[0];
    $selected_id = (int)$selected_rfo['document_id'];
}

$timelines = $selected_rfo ? incident_get_rfo_timelines($selected_rfo['document_id']) : [];

// Print / PDF View Mode
if (isset($_GET['pdf']) || isset($_GET['print'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>RFO Report - <?= htmlspecialchars($selected_rfo['incident_number'] ?? 'N/A') ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fff; color: #2A3239; }
            .header-banner { border-bottom: 3px solid #0065A4; padding-bottom: 15px; margin-bottom: 20px; }
            .section-title { background: #EFF6FB; border-left: 4px solid #0065A4; padding: 6px 12px; font-weight: bold; margin-top: 20px; margin-bottom: 12px; }
            @media print { .no-print { display: none !important; } }
        </style>
    </head>
    <body class="p-4" onload="if(window.location.search.includes('print=1')) window.print();">
        <div class="no-print mb-3 text-end">
            <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fa-solid fa-print me-1"></i>Print / Save PDF</button>
            <a href="index.php?route=incident_rfo&id=<?= $selected_id ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to RFO Manager</a>
        </div>

        <div class="header-banner d-flex justify-content-between align-items-center">
            <div>
                <h3 class="fw-bold text-primary mb-1"><i class="fa-solid fa-triangle-exclamation text-warning me-2"></i>Reason For Outage (RFO) Incident Analysis</h3>
                <div class="text-muted small">Generated on <?= date('Y-m-d H:i:s') ?> | Executive Operational Outage Report</div>
            </div>
            <div>
                <span class="badge bg-<?= ($selected_rfo['incident_severity'] ?? 'SEV-2') === 'SEV-1' ? 'danger' : 'warning text-dark' ?> fs-6">
                    <?= htmlspecialchars($selected_rfo['incident_severity'] ?? 'SEV-2') ?>
                </span>
            </div>
        </div>

        <div class="row g-3 mb-3 small border p-3 rounded bg-light">
            <div class="col-6"><strong>Incident Number:</strong> <?= htmlspecialchars($selected_rfo['incident_number'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Total Duration:</strong> <?= htmlspecialchars($selected_rfo['total_duration'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Services Affected:</strong> <?= htmlspecialchars($selected_rfo['service_affected'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Systems Affected:</strong> <?= htmlspecialchars($selected_rfo['systems_affected'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Customers Affected:</strong> <?= htmlspecialchars($selected_rfo['customers_affected'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Geographic Areas:</strong> <?= htmlspecialchars($selected_rfo['geographic_areas_affected'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Start Time:</strong> <?= htmlspecialchars($selected_rfo['start_datetime'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Detection Time:</strong> <?= htmlspecialchars($selected_rfo['detection_datetime'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Escalation Time:</strong> <?= htmlspecialchars($selected_rfo['escalation_datetime'] ?? 'N/A') ?></div>
            <div class="col-6"><strong>Restoration Time:</strong> <?= htmlspecialchars($selected_rfo['service_restoration_datetime'] ?? 'N/A') ?></div>
        </div>

        <div class="section-title text-primary"><i class="fa-solid fa-bullhorn me-2"></i>Executive Summary & Impact</div>
        <p class="small mb-2"><strong>Impact Description:</strong> <?= nl2br(htmlspecialchars($selected_rfo['impact_description'] ?? 'None recorded.')) ?></p>
        <p class="small mb-2"><strong>Initial Symptoms:</strong> <?= nl2br(htmlspecialchars($selected_rfo['initial_symptoms'] ?? 'None recorded.')) ?></p>
        <p class="small mb-0"><strong>Detection Method:</strong> <?= htmlspecialchars($selected_rfo['detection_method'] ?? 'N/A') ?></p>

        <div class="section-title text-primary"><i class="fa-solid fa-magnifying-glass me-2"></i>Root Cause & Contributing Factors</div>
        <p class="small mb-2"><strong>Root Cause Analysis:</strong> <?= nl2br(htmlspecialchars($selected_rfo['root_cause'] ?? 'Analysis pending.')) ?></p>
        <p class="small mb-0"><strong>Contributing Factors:</strong> <?= nl2br(htmlspecialchars($selected_rfo['contributing_factors'] ?? 'None specified.')) ?></p>

        <div class="section-title text-primary"><i class="fa-solid fa-wrench me-2"></i>Resolution & Remediation Actions</div>
        <p class="small mb-2"><strong>Resolution Summary:</strong> <?= nl2br(htmlspecialchars($selected_rfo['resolution'] ?? 'N/A')) ?></p>
        <p class="small mb-2"><strong>Recovery Actions:</strong> <?= nl2br(htmlspecialchars($selected_rfo['recovery_actions'] ?? 'N/A')) ?></p>
        <p class="small mb-2"><strong>Corrective Actions:</strong> <?= nl2br(htmlspecialchars($selected_rfo['corrective_actions'] ?? 'N/A')) ?></p>
        <p class="small mb-2"><strong>Preventative Actions:</strong> <?= nl2br(htmlspecialchars($selected_rfo['preventative_actions'] ?? 'N/A')) ?></p>
        <p class="small mb-0"><strong>Lessons Learned:</strong> <?= nl2br(htmlspecialchars($selected_rfo['lessons_learned'] ?? 'N/A')) ?></p>

        <div class="section-title text-primary"><i class="fa-solid fa-timeline me-2"></i>Chronological Event Timeline</div>
        <?php if (empty($timelines)): ?>
            <p class="small text-muted">No timeline items recorded.</p>
        <?php else: ?>
            <table class="table table-sm table-bordered small">
                <thead class="table-light">
                    <tr>
                        <th style="width:160px;">Timestamp</th>
                        <th>Event Description</th>
                        <th>Person / Source</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timelines as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['timestamp']) ?></td>
                            <td><?= htmlspecialchars($t['event']) ?></td>
                            <td><?= htmlspecialchars(($t['person'] ?? '') . ($t['source'] ? ' (' . $t['source'] . ')' : '')) ?></td>
                            <td><?= htmlspecialchars($t['notes'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}
?>

<div class="container-fluid py-2 text-start">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="fa-solid fa-triangle-exclamation text-warning me-2"></i>RFO / Incident Report Module</h1>
            <p class="text-muted small mb-0">Root Cause Analysis, Outage Timelines, Impact Assessment & Executive Remediation Reports.</p>
        </div>
        <div>
            <button type="button" class="btn btn-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#createRfoModal">
                <i class="fa-solid fa-plus me-1"></i> New Incident Report (RFO)
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm text-start" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Sidebar RFO Picker -->
        <div class="col-lg-4">
            <div class="card shadow-sm mb-4 border">
                <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-list me-2"></i>Archived RFO Reports</span>
                    <span class="badge bg-secondary"><?= count($all_rfos) ?></span>
                </div>
                <div class="list-group list-group-flush overflow-auto" style="max-height: 650px;">
                    <?php if (empty($all_rfos)): ?>
                        <div class="p-4 text-center text-muted small"><i class="fa-solid fa-file-circle-xmark me-2"></i>No RFO reports generated yet.</div>
                    <?php else: ?>
                        <?php foreach ($all_rfos as $r):
                            $isActive = ($selected_rfo && $selected_rfo['document_id'] == $r['document_id']);
                        ?>
                            <a href="index.php?route=incident_rfo&id=<?= $r['document_id'] ?>" class="list-group-item list-group-item-action <?= $isActive ? 'active' : '' ?> py-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="font-monospace"><?= htmlspecialchars($r['incident_number'] ?: ('RFO #' . $r['document_id'])) ?></strong>
                                    <span class="badge bg-<?= ($r['incident_severity'] ?? 'SEV-2') === 'SEV-1' ? 'danger' : 'warning text-dark' ?>">
                                        <?= htmlspecialchars($r['incident_severity'] ?? 'SEV-2') ?>
                                    </span>
                                </div>
                                <h6 class="mb-1 fw-bold text-truncate"><?= htmlspecialchars($r['service_affected'] ?: 'General Service Outage') ?></h6>
                                <small class="text-muted d-block" style="font-size:0.75rem;">Start: <?= htmlspecialchars($r['start_datetime'] ?: 'N/A') ?></small>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main RFO Form & Timeline Panel -->
        <div class="col-lg-8">
            <?php if (!$selected_rfo): ?>
                <div class="card shadow-sm border p-5 text-center text-muted">
                    <i class="fa-solid fa-file-invoice fs-1 mb-3 text-secondary"></i>
                    <h5>No RFO Incident Report Selected</h5>
                    <p class="small">Select an RFO from the list on the left or create a new report to begin analysis.</p>
                </div>
            <?php else: ?>
                <div class="card shadow-sm border mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                        <h5 class="fw-bold mb-0 text-primary">
                            <i class="fa-solid fa-file-contract me-2"></i>RFO Details: <code><?= htmlspecialchars($selected_rfo['incident_number'] ?: ('RFO #' . $selected_rfo['document_id'])) ?></code>
                        </h5>
                        <div class="btn-group btn-group-sm">
                            <a href="index.php?route=incident_rfo&id=<?= $selected_rfo['document_id'] ?>&print=1" target="_blank" class="btn btn-outline-primary fw-bold">
                                <i class="fa-solid fa-file-pdf me-1"></i> Print / Export RFO PDF
                            </a>
                            <form method="POST" action="index.php?route=incident_rfo" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this RFO report?');">
                                <?php if (function_exists('csrf_field')) csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_rfo">
                                <input type="hidden" name="document_id" value="<?= $selected_rfo['document_id'] ?>">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="fa-solid fa-trash me-1"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="index.php?route=incident_rfo&id=<?= $selected_rfo['document_id'] ?>">
                            <?php if (function_exists('csrf_field')) csrf_field(); ?>
                            <input type="hidden" name="action" value="update_rfo">
                            <input type="hidden" name="document_id" value="<?= $selected_rfo['document_id'] ?>">

                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Incident Number</label>
                                    <input type="text" name="incident_number" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['incident_number'] ?? '') ?>" placeholder="INC-000101">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Service Affected</label>
                                    <input type="text" name="service_affected" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['service_affected'] ?? '') ?>" placeholder="e.g. Fiber Core Transport">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Incident Severity</label>
                                    <select name="incident_severity" class="form-select form-select-sm">
                                        <option value="SEV-1" <?= ($selected_rfo['incident_severity'] ?? '') === 'SEV-1' ? 'selected' : '' ?>>SEV-1 (Critical Outage)</option>
                                        <option value="SEV-2" <?= ($selected_rfo['incident_severity'] ?? '') === 'SEV-2' ? 'selected' : '' ?>>SEV-2 (Major Impact)</option>
                                        <option value="SEV-3" <?= ($selected_rfo['incident_severity'] ?? '') === 'SEV-3' ? 'selected' : '' ?>>SEV-3 (Minor Degradation)</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Systems Affected</label>
                                    <input type="text" name="systems_affected" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['systems_affected'] ?? '') ?>" placeholder="Core Routers, GPON OLT">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Customers Affected</label>
                                    <input type="text" name="customers_affected" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['customers_affected'] ?? '') ?>" placeholder="e.g. 1,250 subscribers">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Geographic Areas</label>
                                    <input type="text" name="geographic_areas_affected" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['geographic_areas_affected'] ?? '') ?>" placeholder="e.g. Brandon, Winnipeg">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Start Time</label>
                                    <input type="text" name="start_datetime" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['start_datetime'] ?? '') ?>" placeholder="YYYY-MM-DD HH:MM:SS">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Detection Time</label>
                                    <input type="text" name="detection_datetime" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['detection_datetime'] ?? '') ?>" placeholder="YYYY-MM-DD HH:MM:SS">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Escalation Time</label>
                                    <input type="text" name="escalation_datetime" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['escalation_datetime'] ?? '') ?>" placeholder="YYYY-MM-DD HH:MM:SS">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Restoration Time</label>
                                    <input type="text" name="service_restoration_datetime" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['service_restoration_datetime'] ?? '') ?>" placeholder="YYYY-MM-DD HH:MM:SS">
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold small">Impact Description</label>
                                    <textarea name="impact_description" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($selected_rfo['impact_description'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Initial Symptoms</label>
                                    <textarea name="initial_symptoms" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($selected_rfo['initial_symptoms'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Detection Method</label>
                                    <input type="text" name="detection_method" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['detection_method'] ?? '') ?>" placeholder="NMS Alert, Syslog Trap, Customer Call">
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold small">Root Cause Analysis</label>
                                    <textarea name="root_cause" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($selected_rfo['root_cause'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Contributing Factors</label>
                                    <textarea name="contributing_factors" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($selected_rfo['contributing_factors'] ?? '') ?></textarea>
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold small">Resolution Summary</label>
                                    <textarea name="resolution" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($selected_rfo['resolution'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Recovery Actions</label>
                                    <textarea name="recovery_actions" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($selected_rfo['recovery_actions'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Corrective Actions</label>
                                    <textarea name="corrective_actions" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($selected_rfo['corrective_actions'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Preventative Actions</label>
                                    <textarea name="preventative_actions" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($selected_rfo['preventative_actions'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Lessons Learned</label>
                                    <textarea name="lessons_learned" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($selected_rfo['lessons_learned'] ?? '') ?></textarea>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Related Tickets</label>
                                    <input type="text" name="related_tickets" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['related_tickets'] ?? '') ?>" placeholder="e.g. OTRS-90812">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Related Changes</label>
                                    <input type="text" name="related_changes" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['related_changes'] ?? '') ?>" placeholder="e.g. CHG-5501">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Related RFOs</label>
                                    <input type="text" name="related_rfos" class="form-control form-control-sm" value="<?= htmlspecialchars($selected_rfo['related_rfos'] ?? '') ?>" placeholder="e.g. RFO-2025-0040">
                                </div>
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-floppy-disk me-1"></i> Save RFO Incident Details</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Event Timelines -->
                <div class="card shadow-sm border">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                        <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-timeline me-2 text-warning"></i>Incident Event Timeline</h5>
                        <button type="button" class="btn btn-sm btn-outline-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#addTimelineModal">
                            <i class="fa-solid fa-plus me-1"></i> Add Timeline Entry
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($timelines)): ?>
                            <p class="text-muted small mb-0">No timeline entries recorded for this incident report yet.</p>
                        <?php else: ?>
                            <div class="timeline position-relative ps-4 border-start border-3 border-warning">
                                <?php foreach ($timelines as $tl): ?>
                                    <div class="mb-3 position-relative">
                                        <div class="fw-bold text-dark">
                                            <span class="badge bg-warning text-dark me-2"><?= htmlspecialchars($tl['timestamp']) ?></span>
                                            <?= htmlspecialchars($tl['event']) ?>
                                        </div>
                                        <small class="text-muted d-block" style="font-size:0.75rem;">Person: <?= htmlspecialchars($tl['person'] ?: 'N/A') ?> | Source: <?= htmlspecialchars($tl['source'] ?: 'N/A') ?></small>
                                        <?php if (!empty($tl['notes'])): ?>
                                            <p class="small text-secondary mb-0 mt-1"><?= nl2br(htmlspecialchars($tl['notes'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Timeline Modal -->
<?php if ($selected_rfo): ?>
<div class="modal fade text-start" id="addTimelineModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?route=incident_rfo&id=<?= $selected_rfo['document_id'] ?>">
                <?php if (function_exists('csrf_field')) csrf_field(); ?>
                <input type="hidden" name="action" value="add_timeline">
                <input type="hidden" name="document_id" value="<?= $selected_rfo['document_id'] ?>">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle me-2"></i>Add Timeline Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Timestamp</label>
                        <input type="text" name="timestamp" class="form-control form-control-sm" value="<?= date('Y-m-d H:i:s') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Event Description</label>
                        <input type="text" name="event" class="form-control form-control-sm" placeholder="e.g. Core router interface flapping detected" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Person / Handler</label>
                            <input type="text" name="person" class="form-control form-control-sm" placeholder="Engineer Name">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Source</label>
                            <input type="text" name="source" class="form-control form-control-sm" placeholder="Zabbix, Syslog, NOC">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Additional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning text-dark fw-bold">Add Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Create RFO Modal -->
<div class="modal fade text-start" id="createRfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="index.php?route=incident_rfo">
                <?php if (function_exists('csrf_field')) csrf_field(); ?>
                <input type="hidden" name="action" value="create_rfo">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-triangle-exclamation me-2"></i>New RFO / Incident Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold small">Service Affected <span class="text-danger">*</span></label>
                            <input type="text" name="service_affected" class="form-control form-control-sm" required placeholder="e.g. Core IP Backhaul">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Incident Number</label>
                            <input type="text" name="incident_number" class="form-control form-control-sm" placeholder="INC-2026-9081">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Incident Severity</label>
                            <select name="incident_severity" class="form-select form-select-sm">
                                <option value="SEV-1">SEV-1 (Critical)</option>
                                <option value="SEV-2" selected>SEV-2 (Major)</option>
                                <option value="SEV-3">SEV-3 (Minor)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Systems Affected</label>
                            <input type="text" name="systems_affected" class="form-control form-control-sm" placeholder="e.g. Edge Routers, OLT 03">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Impact Description</label>
                            <textarea name="impact_description" class="form-control form-control-sm" rows="3" placeholder="Describe customer and operational impact..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning text-dark fw-bold">Create Report</button>
                </div>
            </form>
        </div>
    </div>
</div>
