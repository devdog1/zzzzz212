<?php
// Public NOC TV Wallboard View Handler for Incident Management Plugin

if (!class_exists('EventManager')) {
    require_once __DIR__ . '/../models/helper_functions.php';
    require_once __DIR__ . '/../models/EventManager.php';
    require_once __DIR__ . '/../models/OTRSClient.php';
    require_once __DIR__ . '/../models/OTRSDB.php';
    require_once __DIR__ . '/../models/NetBoxClient.php';
}

$em = new EventManager('NOC Wallboard Display');

$activeEvents = $em->listEvents(false);
$activeCount = count($activeEvents);
$stats = $em->getStatistics();
$totalImpact = $stats['total_impact'] ?? 0;

$problems = [];
$maint = [];
$changes = [];

try {
    $otrsDB = $em->getOTRSDB();
    if ($otrsDB && $otrsDB->isConnected()) {
        $problems = $otrsDB->getProblemTickets();
        $maint    = $otrsDB->getMaintTickets();
        $changes  = $otrsDB->getChangeOverview();
    }
} catch (Throwable $e) {}

$slaThresholdMinutes = (int)($em->getDefault('sla_threshold_minutes') ?: 30);

function badgeStatusNocView(string $value): string
{
    $v = strtolower($value);
    if (str_contains($v, 'open') || str_contains($v, 'detected')) return 'bg-danger text-white';
    if (str_contains($v, 'progress') || str_contains($v, 'investigat')) return 'bg-warning text-dark';
    if (str_contains($v, 'closed') || str_contains($v, 'successful')) return 'bg-success text-white';
    if (str_contains($v, 'approved')) return 'bg-primary text-white';
    return 'bg-secondary text-white';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOC Operational Status Wallboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            background-color: #0b0f19;
            color: #f1f5f9;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            overflow-x: hidden;
            margin: 0;
            padding: 20px;
        }
        .wallboard-header {
            border-bottom: 2px solid #1e293b;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 18px;
            text-align: center;
        }
        .stat-card .number {
            font-size: 2.8rem;
            font-weight: 800;
            line-height: 1;
        }
        .stat-card .label {
            font-size: 0.85rem;
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 5px;
        }
        .noc-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .noc-card-header {
            background: #0f172a;
            border-bottom: 1px solid #334155;
            padding: 12px 18px;
            font-weight: 700;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
        }
        .noc-table {
            color: #f1f5f9;
            margin: 0;
        }
        .noc-table thead {
            background: #0f172a;
            color: #94a3b8;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .noc-table td, .noc-table th {
            border-color: #334155;
            padding: 10px 14px;
            font-size: 0.9rem;
        }
        .progress-bar-container {
            height: 4px;
            background: #1e293b;
            width: 100%;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 9999;
        }
        .progress-bar-fill {
            height: 100%;
            background: #38bdf8;
            width: 100%;
            transition: width 1s linear;
        }
        .pulse-active {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
    </style>
</head>
<body>

    <div class="progress-bar-container">
        <div class="progress-bar-fill" id="refreshProgressBar"></div>
    </div>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center wallboard-header">
        <div class="d-flex align-items-center">
            <i class="fa-solid fa-desktop text-info fs-2 me-3"></i>
            <div>
                <h2 class="mb-0 fw-bold">NOC Operational Status Display</h2>
                <small class="text-secondary">24/7 Live Monitoring Wallboard | Incident & Network Overview</small>
            </div>
        </div>
        <div class="text-end">
            <span class="badge bg-dark border border-secondary text-info p-2 font-monospace fs-6">
                <i class="fa-solid fa-clock me-1"></i><span id="wallboardClock"><?= date('Y-m-d H:i:s') ?></span>
            </span>
            <button class="btn btn-sm btn-outline-secondary ms-2" onclick="toggleFullScreen()"><i class="fa-solid fa-expand"></i></button>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card border-danger <?= $activeCount > 0 ? 'pulse-active' : '' ?>">
                <div class="number text-danger"><?= $activeCount ?></div>
                <div class="label"><i class="fa-solid fa-triangle-exclamation me-1"></i>Active Incidents</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card border-warning">
                <div class="number text-warning"><?= number_format($totalImpact) ?></div>
                <div class="label"><i class="fa-solid fa-chart-line me-1"></i>Total Outage Impact</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card border-info">
                <div class="number text-info"><?= count($problems) ?></div>
                <div class="label"><i class="fa-solid fa-ticket me-1"></i>Open OTRS Problems</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card border-primary">
                <div class="number text-primary"><?= count($changes) ?></div>
                <div class="label"><i class="fa-solid fa-calendar-check me-1"></i>Upcoming Changes</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Column: Active Incidents -->
        <div class="col-lg-7">
            <div class="noc-card">
                <div class="noc-card-header text-danger d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-fire me-2"></i>Active Incident Queue</span>
                    <span class="badge bg-danger text-white"><?= $activeCount ?></span>
                </div>
                <div class="p-3">
                    <?php if (empty($activeEvents)): ?>
                        <div class="text-center py-5 text-success">
                            <i class="fa-solid fa-circle-check fs-1 mb-2 d-block"></i>
                            <h4 class="fw-bold">All Systems Operational</h4>
                            <p class="text-secondary small mb-0">No active incidents currently reported.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activeEvents as $e):
                            $history = $em->getStateHistory($e['id']);
                            $lastState = end($history);
                            $stateEnterTime = $lastState ? $lastState['enter_time'] : $e['create_time'];
                            $updates = $em->getEventUpdates($e['id']);
                            $lastUpdate = end($updates);
                            $lastUpdateTime = $lastUpdate ? strtotime($lastUpdate['create_time']) : strtotime($stateEnterTime);
                            $minutesSinceUpdate = floor((time() - $lastUpdateTime) / 60);
                            $isStale = $minutesSinceUpdate >= $slaThresholdMinutes;
                        ?>
                            <div class="p-3 mb-3 border <?= $isStale ? 'border-danger' : 'border-secondary' ?> rounded bg-dark">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="badge bg-secondary me-2">#<?= $e['id'] ?></span>
                                        <span class="fw-bold fs-5 text-white me-2"><?= htmlspecialchars($e['title'] ?: 'Incident #' . $e['id']) ?></span>
                                        <span class="badge <?= badgeStatusNocView($e['state_name'] ?? 'Detected') ?>"><?= htmlspecialchars($e['state_name'] ?? 'Detected') ?></span>
                                        <?php if ($isStale): ?>
                                            <span class="badge bg-danger ms-1"><i class="fa-solid fa-bell me-1"></i>SLA Stale (<?= $minutesSinceUpdate ?>m)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end text-secondary small">
                                        <div>Age: <strong class="text-info"><?= humanTime(strtotime($e['create_time'])) ?></strong></div>
                                    </div>
                                </div>

                                <p class="text-slate-300 small mb-2 text-secondary"><?= htmlspecialchars($e['description'] ?? '') ?></p>

                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="small text-secondary">Department: <strong class="text-white"><?= htmlspecialchars($e['department_name'] ?: 'General') ?></strong></span>
                                    <span class="small text-secondary">| Impact Score: <strong class="text-danger"><?= number_format((int)($e['impactScore'] ?? 0)) ?></strong></span>
                                    <span class="small text-secondary">| Affected: <strong class="text-warning"><?= number_format((int)($e['customers_affected'] ?? 0)) ?></strong> customers</span>
                                </div>

                                <?php if ($lastUpdate): ?>
                                    <div class="p-2 rounded bg-slate-900 border border-slate-700 small mt-2">
                                        <div class="text-info fw-bold mb-1"><i class="fa-solid fa-comment-dots me-1"></i>Latest Update (<?= $lastUpdate['create_time'] ?>):</div>
                                        <div class="text-light"><?= htmlspecialchars($lastUpdate['update_text']) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: OTRS Problems & Changes -->
        <div class="col-lg-5">
            <!-- Problem Tickets -->
            <div class="noc-card mb-4">
                <div class="noc-card-header text-warning d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-triangle-exclamation me-2"></i>Active OTRS Problem Tickets</span>
                    <span class="badge bg-warning text-dark"><?= count($problems) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table noc-table align-middle">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Queue</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($problems)): ?>
                                <tr><td colspan="3" class="text-center text-secondary py-3">No active OTRS problem tickets.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($problems, 0, 5) as $p): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-white small"><?= htmlspecialchars($p['tickettitle']) ?></div>
                                            <div class="text-secondary" style="font-size:0.75rem;"><?= htmlspecialchars($p['ticketnumber']) ?></div>
                                        </td>
                                        <td><span class="small text-slate-300"><?= htmlspecialchars($p['queuename']) ?></span></td>
                                        <td><span class="badge <?= badgeStatusNocView($p['statetype']) ?>"><?= htmlspecialchars($p['statetype']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Scheduled Changes -->
            <div class="noc-card">
                <div class="noc-card-header text-primary d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-calendar-days me-2"></i>Scheduled Maintenance Windows</span>
                    <span class="badge bg-primary text-white"><?= count($changes) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table noc-table align-middle">
                        <thead>
                            <tr>
                                <th>Change Title</th>
                                <th>Status</th>
                                <th>Planned Window</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($changes)): ?>
                                <tr><td colspan="3" class="text-center text-secondary py-3">No upcoming changes scheduled.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($changes, 0, 5) as $c): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-white small"><?= htmlspecialchars($c['changeTitle']) ?></div>
                                            <div class="text-secondary" style="font-size:0.75rem;">#<?= htmlspecialchars($c['changeId']) ?></div>
                                        </td>
                                        <td><span class="badge <?= badgeStatusNocView($c['changeStatus']) ?>"><?= htmlspecialchars($c['changeStatus']) ?></span></td>
                                        <td><small class="text-secondary"><?= htmlspecialchars($c['plannedStartTime']) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<script>
    // Live Wallboard Clock
    setInterval(() => {
        const now = new Date();
        document.getElementById('wallboardClock').textContent = now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0') + ' ' +
            String(now.getHours()).padStart(2, '0') + ':' +
            String(now.getMinutes()).padStart(2, '0') + ':' +
            String(now.getSeconds()).padStart(2, '0');
    }, 1000);

    // 30-Second Refresh Countdown Progress Bar
    const REFRESH_SECONDS = 30;
    let secondsLeft = REFRESH_SECONDS;
    const progressBar = document.getElementById('refreshProgressBar');

    setInterval(() => {
        secondsLeft--;
        const pct = (secondsLeft / REFRESH_SECONDS) * 100;
        progressBar.style.width = pct + '%';
        if (secondsLeft <= 0) {
            location.reload();
        }
    }, 1000);

    // Fullscreen Toggle Helper
    function toggleFullScreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    }
</script>
</body>
</html>
