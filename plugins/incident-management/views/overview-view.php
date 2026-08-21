<?php
// Network Overview View for Incident Management Plugin

if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_view_events</code></div>';
    return;
}

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'User');
$em = new EventManager($currentUser);

$currentTime = time();
$problems = [];
$maint = [];
$changes = [];
$otrsError = null;

try {
    $config = [];
    $configPath = __DIR__ . '/../../../config.php';
    if (file_exists($configPath)) {
        $config = require $configPath;
    }

    if (isset($config['db']['otrs'])) {
        $otrsDB = new OTRSDB($config);
        if ($otrsDB->isConnected()) {
            $problems = $otrsDB->getProblemTickets();
            $maint = $otrsDB->getMaintTickets();
            $changes = $otrsDB->getChangeOverview();
        }
    }
} catch (Exception $e) {
    $otrsError = $e->getMessage();
}

$OTRSTicketLink = $config['otrs']['ticket_link'] ?? '#';
$OTRSChangeLink = $config['otrs']['change_link'] ?? '#';

function badgeStatus(string $value): string
{
    $v = strtolower($value);
    if (str_contains($v, 'open')) return 'bg-danger';
    if (str_contains($v, 'progress')) return 'bg-warning text-dark';
    if (str_contains($v, 'closed') || str_contains($v, 'successful')) return 'bg-success';
    if (str_contains($v, 'approved')) return 'bg-primary';
    return 'bg-secondary';
}
?>

<div class="row mb-4 text-start">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-network-wired text-primary me-2"></i>Network & OTRS Operational Overview</h1>
        <p class="text-muted">Live operational status dashboard, problem tickets, and scheduled maintenance windows.</p>
    </div>
    <div class="col-md-4 text-end align-self-center">
        <small class="text-muted d-block">Last Status Refresh</small>
        <span class="fw-bold text-dark"><i class="fa-solid fa-clock me-1"></i><?= date('Y-m-d H:i:s') ?></span>
    </div>
</div>

<?php if ($otrsError): ?>
    <div class="alert alert-warning shadow-sm text-start mb-4">
        <i class="fa-solid fa-circle-info me-2"></i><strong>OTRS Database Note:</strong> <?= htmlspecialchars($otrsError) ?>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-4 mb-4 text-start">
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-5 border-danger bg-light">
            <div class="card-body">
                <div class="small text-muted mb-1 fw-bold">Open OTRS Problems</div>
                <div class="fs-2 fw-bold text-danger"><?= count($problems) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-5 border-primary bg-light">
            <div class="card-body">
                <div class="small text-muted mb-1 fw-bold">Upcoming Changes</div>
                <div class="fs-2 fw-bold text-primary"><?= count($changes) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-5 border-secondary bg-light">
            <div class="card-body">
                <div class="small text-muted mb-1 fw-bold">Maintenance Tickets</div>
                <div class="fs-2 fw-bold text-secondary"><?= count($maint) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Problem Tickets Table -->
<div class="card shadow-sm border mb-4 text-start">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
        <span class="fw-bold"><i class="fa-solid fa-triangle-exclamation me-2 text-warning"></i>Active OTRS Problem Tickets</span>
        <span class="badge bg-light text-dark"><?= count($problems) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ticket Title</th>
                        <th>Number</th>
                        <th>Updated</th>
                        <th>Queue</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($problems)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4 small">No active OTRS problem tickets currently open.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($problems as $ticket): ?>
                        <tr>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($ticket['tickettitle']) ?></td>
                            <td><code><?= htmlspecialchars($ticket['ticketnumber']) ?></code></td>
                            <td><small class="text-muted"><?= humanTime(strtotime($ticket['changetime'])) ?></small></td>
                            <td><?= htmlspecialchars($ticket['queuename']) ?></td>
                            <td><span class="badge <?= badgeStatus($ticket['statetype']) ?>"><?= htmlspecialchars($ticket['statetype']) ?></span></td>
                            <td class="text-end">
                                <a class="btn btn-xs btn-outline-primary btn-sm" target="_blank" href="<?= $OTRSTicketLink . $ticket['ticketid'] ?>">
                                    <i class="fa-solid fa-external-link me-1"></i>Open
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row text-start g-4">
    <!-- Changes Table -->
    <div class="col-lg-8">
        <div class="card shadow-sm border h-100">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold"><i class="fa-solid fa-calendar-check me-2"></i>Upcoming Scheduled Changes</span>
                <span class="badge bg-light text-primary"><?= count($changes) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>#</th>
                                <th>Status</th>
                                <th>Start</th>
                                <th>End</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($changes)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4 small">No upcoming changes scheduled.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($changes as $ticket):
                                $active = (strtotime($ticket['plannedStartTime']) <= $currentTime && strtotime($ticket['plannedEndTime']) >= $currentTime) || strtolower($ticket['changeStatus']) === 'in progress';
                            ?>
                                <tr class="<?= $active ? 'table-warning' : '' ?>">
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($ticket['changeTitle']) ?></td>
                                    <td><code><?= htmlspecialchars($ticket['changeId']) ?></code></td>
                                    <td><span class="badge <?= badgeStatus($ticket['changeStatus']) ?>"><?= ucwords($ticket['changeStatus']) ?></span></td>
                                    <td><small><?= htmlspecialchars($ticket['plannedStartTime']) ?></small></td>
                                    <td><small><?= htmlspecialchars($ticket['plannedEndTime']) ?></small></td>
                                    <td class="text-end">
                                        <a class="btn btn-xs btn-outline-primary btn-sm" target="_blank" href="<?= $OTRSChangeLink . $ticket['changeId'] ?>">
                                            <i class="fa-solid fa-external-link me-1"></i>Open
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Maintenance Table -->
    <div class="col-lg-4">
        <div class="card shadow-sm border h-100">
            <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold"><i class="fa-solid fa-wrench me-2"></i>Maintenance Tickets</span>
                <span class="badge bg-light text-secondary"><?= count($maint) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($maint)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4 small">No maintenance tickets found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($maint as $ticket): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($ticket['tickettitle']) ?></div>
                                        <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($ticket['ticketnumber']) ?></div>
                                    </td>
                                    <td><span class="badge <?= badgeStatus($ticket['statetype']) ?>"><?= htmlspecialchars($ticket['statetype']) ?></span></td>
                                    <td class="text-end">
                                        <a class="btn btn-xs btn-outline-primary btn-sm" target="_blank" href="<?= $OTRSTicketLink . $ticket['ticketid'] ?>">
                                            Open
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
