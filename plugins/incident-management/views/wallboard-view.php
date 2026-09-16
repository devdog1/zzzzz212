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

$nowTs = time();
$max48hTs = $nowTs + (48 * 3600);

try {
    $otrsDB = $em->getOTRSDB();
    if ($otrsDB && $otrsDB->isConnected()) {
        $problems = $otrsDB->getProblemTickets();
        $maint    = $otrsDB->getMaintTickets();
        $rawChanges = $otrsDB->getChangeOverview();

        // Filter changes: currently in window OR starting within the next 48 hours
        foreach ($rawChanges as $c) {
            $startTs = strtotime($c['plannedStartTime'] ?? '');
            $endTs   = strtotime($c['plannedEndTime'] ?? '');
            if (($nowTs >= $startTs && $nowTs <= $endTs) || ($startTs >= $nowTs && $startTs <= $max48hTs)) {
                $changes[] = $c;
            }
        }
    }
} catch (Throwable $e) {}

$slaThresholdMinutes = (int)($em->getDefault('sla_threshold_minutes') ?: 30);

// --- 48-Hour Hourly Outage Impact Score Calculation ---
$hourlyImpact = array_fill(0, 48, 0);
$hourlyLabels = [];
$currentHourStart = strtotime(date('Y-m-d H:00:00', $nowTs));

for ($i = 47; $i >= 0; $i--) {
    $hourStart = $currentHourStart - ($i * 3600);
    $hourlyLabels[] = date('m/d H:00', $hourStart);
}

$allEventsForTrend = $em->listEvents(true);
foreach ($allEventsForTrend as $ev) {
    $cust = (int)($ev['customers_affected'] ?? 0);
    if ($cust <= 0) continue;

    $createTs = strtotime($ev['create_time']);
    $history = $em->getStateHistory($ev['id']);
    $lastState = end($history);
    $closeTs = (strtolower($ev['state_name'] ?? '') === 'closed') ? strtotime($lastState['enter_time'] ?? $ev['update_time']) : $nowTs;

    for ($i = 47; $i >= 0; $i--) {
        $windowStart = $currentHourStart - ($i * 3600);
        $windowEnd = $windowStart + 3600;

        $overlapStart = max($createTs, $windowStart);
        $overlapEnd = min($closeTs, $windowEnd);

        if ($overlapEnd > $overlapStart) {
            $overlapMins = ($overlapEnd - $overlapStart) / 60;
            $hourlyImpact[47 - $i] += round($overlapMins * $cust);
        }
    }
}

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
            background-color: #1e293b;
            margin: 0;
        }
        .noc-table thead {
            background-color: #0f172a !important;
            color: #94a3b8;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .noc-table tbody tr {
            background-color: #1e293b !important;
        }
        .noc-table tbody tr:hover {
            background-color: #334155 !important;
        }
        .noc-table td, .noc-table th {
            border-color: #334155;
            padding: 10px 14px;
            font-size: 0.9rem;
            color: #f1f5f9 !important;
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
                <div class="label"><i class="fa-solid fa-calendar-check me-1"></i>Scheduled Changes (48h)</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Left Column: Active Incidents -->
        <div class="col-lg-7">
            <div class="noc-card h-100">
                <div class="noc-card-header text-danger d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-fire me-2"></i>Active Incident Queue</span>
                    <span class="badge bg-danger text-white"><?= $activeCount ?></span>
                </div>
                <div class="p-3">
                    <?php if (empty($activeEvents)): ?>
                        <div class="text-center py-5 text-success">
                            <i class="fa-solid fa-circle-check fs-1 mb-2 d-block"></i>
                            <h4 class="fw-bold">No Active Incident</h4>
                            <p class="text-secondary small mb-0">No active incidents currently reported on network.</p>
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

                                <p class="small mb-2 text-light"><?= htmlspecialchars($e['description'] ?? '') ?></p>

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
                                        <td><span class="small text-light"><?= htmlspecialchars($p['queuename']) ?></span></td>
                                        <td><span class="badge <?= badgeStatusNocView($p['statetype']) ?>"><?= htmlspecialchars($p['statetype']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Scheduled Maintenance Windows (Next 48 Hours) -->
            <div class="noc-card">
                <div class="noc-card-header text-primary d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-calendar-days me-2"></i>Scheduled Maintenance Windows (Next 48h)</span>
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
                                <tr><td colspan="3" class="text-center text-secondary py-3">No maintenance windows starting in the next 48 hours.</td></tr>
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

    <!-- 48-Hour Hourly Outage Impact Score Graph (Bottom Card) -->
    <div class="row">
        <div class="col-12">
            <div class="noc-card">
                <div class="noc-card-header text-warning d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-chart-column me-2"></i>Last 48 Hours - Outage Impact Score per Hour</span>
                    <span class="badge bg-dark border border-secondary text-warning">48-Hour Hourly Metric</span>
                </div>
                <div class="p-3" style="height: 220px; position: relative;">
                    <canvas id="impactTrendChart"></canvas>
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

    // Chart.js 48-Hour Impact Trend Graph
    document.addEventListener('DOMContentLoaded', () => {
        const ctx = document.getElementById('impactTrendChart').getContext('2d');
        const labels = <?= json_encode($hourlyLabels) ?>;
        const data = <?= json_encode($hourlyImpact) ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Impact Score (Customer-Mins)',
                    data: data,
                    backgroundColor: '#f59e0b',
                    borderColor: '#fbbf24',
                    borderWidth: 1,
                    borderRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#94a3b8', font: { size: 10 } },
                        grid: { color: '#1e293b' }
                    },
                    y: {
                        ticks: { color: '#94a3b8', font: { size: 10 } },
                        grid: { color: '#334155' },
                        beginAtZero: true
                    }
                }
            }
        });
    });
</script>
</body>
</html>
