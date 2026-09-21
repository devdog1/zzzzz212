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

// --- 48-Hour Hourly Outage Impact Trend Calculation ---
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

    $history = $em->getStateHistory($ev['id']);
    if (empty($history)) {
        $history = [[
            'state_name' => $ev['state_name'] ?? 'Detected',
            'enter_time' => $ev['create_time'],
            'exit_time'  => (strtolower($ev['state_name'] ?? '') === 'closed') ? $ev['update_time'] : null
        ]];
    }

    foreach ($history as $h) {
        $stateName = strtolower($h['state_name'] ?? '');
        if ($stateName === 'closed') continue;

        $enterTs = strtotime($h['enter_time']);
        $exitTs = !empty($h['exit_time']) ? strtotime($h['exit_time']) : $nowTs;

        for ($i = 47; $i >= 0; $i--) {
            $windowStart = $currentHourStart - ($i * 3600);
            $windowEnd = $windowStart + 3600;

            $overlapStart = max($enterTs, $windowStart);
            $overlapEnd = min($exitTs, $windowEnd);

            if ($overlapEnd > $overlapStart) {
                $overlapMins = ($overlapEnd - $overlapStart) / 60;
                $hourlyImpact[47 - $i] += round($overlapMins * $cust);
            }
        }
    }
}

// 48-Hour Outage Impact Score Total
$totalImpact48h = array_sum($hourlyImpact);

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
    <title>NOC Operational Status Wallboard (4K Ultra-Widescreen)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        html, body {
            height: 100%;
            background-color: #080c14;
            color: #f1f5f9;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            overflow-x: hidden;
            margin: 0;
            padding: 0;
        }
        .wallboard-container {
            width: 100%;
            min-height: 100vh;
            padding: 24px 32px;
        }
        .wallboard-header {
            border-bottom: 2px solid #1e293b;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 14px 16px;
            text-align: center;
        }
        .stat-card .number {
            font-size: 2.5rem;
            font-weight: 900;
            line-height: 1;
        }
        .stat-card .label {
            font-size: 0.85rem;
            color: #94a3b8;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-top: 4px;
        }
        .noc-card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 12px;
            margin-bottom: 16px;
        }
        .noc-card-header {
            background: #1e293b;
            border-bottom: 1px solid #334155;
            padding: 16px 24px;
            font-weight: 800;
            font-size: 1.1rem;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        .table-responsive {
            background-color: #0f172a !important;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }
        .noc-table {
            --bs-table-bg: #0f172a !important;
            --bs-table-color: #f1f5f9 !important;
            --bs-table-border-color: #334155 !important;
            --bs-table-hover-bg: #1e293b !important;
            --bs-table-hover-color: #ffffff !important;
            background-color: #0f172a !important;
            color: #f1f5f9 !important;
            margin: 0;
        }
        .noc-table thead, .noc-table thead tr, .noc-table thead th {
            --bs-table-bg: #1e293b !important;
            --bs-table-color: #94a3b8 !important;
            background-color: #1e293b !important;
            color: #94a3b8 !important;
            font-size: 0.85rem;
            text-transform: uppercase;
            border-color: #334155 !important;
            padding: 12px 18px;
        }
        .noc-table tbody, .noc-table tbody tr, .noc-table tbody td {
            --bs-table-bg: #0f172a !important;
            --bs-table-color: #f1f5f9 !important;
            background-color: #0f172a !important;
            color: #f1f5f9 !important;
            border-color: #334155 !important;
            padding: 14px 18px;
            font-size: 0.95rem;
        }
        .progress-bar-container {
            height: 6px;
            background: #0f172a;
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
            70% { box-shadow: 0 0 0 12px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .noc-card-body-scroll {
            max-height: 250px;
            overflow-y: auto;
        }
        .noc-card-body-scroll::-webkit-scrollbar {
            width: 8px;
        }
        .noc-card-body-scroll::-webkit-scrollbar-track {
            background: #0f172a;
        }
        .noc-card-body-scroll::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }
        .noc-card-body-scroll::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }
    </style>
</head>
<body>

    <div class="progress-bar-container">
        <div class="progress-bar-fill" id="refreshProgressBar"></div>
    </div>

    <div class="wallboard-container">
        <!-- Compact Clock & Action Header -->
        <div class="d-flex justify-content-end align-items-center mb-3">
            <div>
                <span class="badge bg-dark border border-secondary text-info px-3 py-2 font-monospace fs-5">
                    <i class="fa-solid fa-clock me-2"></i><span id="wallboardClock"><?= date('Y-m-d H:i:s') ?></span>
                </span>
                <button class="btn btn-md btn-outline-secondary ms-2" onclick="toggleFullScreen()"><i class="fa-solid fa-expand fs-5"></i></button>
            </div>
        </div>

        <!-- 3-Card Widescreen KPI Row -->
        <div class="row g-4 mb-4">
            <div class="col-xl-4 col-md-4">
                <div class="stat-card border-danger <?= $activeCount > 0 ? 'pulse-active' : '' ?>">
                    <div class="number text-danger counter-animate" data-target="<?= $activeCount ?>">0</div>
                    <div class="label"><i class="fa-solid fa-triangle-exclamation me-2"></i>Active Incidents</div>
                </div>
            </div>
            <div class="col-xl-4 col-md-4">
                <div class="stat-card border-warning">
                    <div class="number text-warning counter-animate" data-target="<?= $totalImpact48h ?>">0</div>
                    <div class="label"><i class="fa-solid fa-chart-line me-2"></i>Total Outage Impact (48h)</div>
                </div>
            </div>
            <div class="col-xl-4 col-md-4">
                <div class="stat-card border-primary">
                    <div class="number text-primary counter-animate" data-target="<?= count($changes) ?>">0</div>
                    <div class="label"><i class="fa-solid fa-calendar-check me-2"></i>Scheduled Changes (48h)</div>
                </div>
            </div>
        </div>

        <!-- Full Widescreen 2-Column Middle Grid -->
        <div class="row g-4 mb-4">
            <!-- Column 1: Active Incidents (9/12 on Widescreen) -->
            <div class="col-xl-9 col-lg-8">
                <div class="noc-card h-100">
                    <div class="noc-card-header text-danger d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-fire me-2"></i>Active Incident Queue</span>
                        <span class="badge bg-danger text-white fs-6"><?= $activeCount ?></span>
                    </div>
                    <div class="p-3 noc-card-body-scroll">
                        <?php if (empty($activeEvents)): ?>
                            <div class="text-center py-5 text-success">
                                <i class="fa-solid fa-circle-check display-3 mb-3 d-block"></i>
                                <h3 class="fw-bold">No Active Incident</h3>
                                <p class="text-secondary fs-6 mb-0">No active incidents currently reported on network.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($activeEvents as $e):
                                $history = $em->getStateHistory($e['id']);
                                $lastState = end($history);
                                $stateEnterTime = $lastState ? $lastState['enter_time'] : $e['create_time'];
                                $updates = $em->getEventUpdates($e['id']);
                                $lastUpdate = !empty($updates) ? $updates[0] : null;
                                $lastUpdateTime = $lastUpdate ? strtotime($lastUpdate['create_time']) : strtotime($stateEnterTime);
                                $minutesSinceUpdate = floor((time() - $lastUpdateTime) / 60);
                                $isStale = $minutesSinceUpdate >= $slaThresholdMinutes;

                                $outageStates = ['Detected', 'Acknowledged', 'Investigating', 'Identified', 'Mitigating', 'Reopened'];
                                $isCurrentStateOutage = in_array(ucfirst(strtolower($e['state_name'] ?? '')), $outageStates);
                                $pastOutageSeconds = 0;
                                $currentStateEnterTime = null;

                                if (!empty($history)) {
                                    foreach ($history as $h) {
                                        if (in_array(ucfirst(strtolower($h['state_name'] ?? '')), $outageStates)) {
                                            $enter = strtotime($h['enter_time']);
                                            if (!empty($h['exit_time'])) {
                                                $exit = strtotime($h['exit_time']);
                                                if ($exit > $enter) {
                                                    $pastOutageSeconds += ($exit - $enter);
                                                }
                                            } else {
                                                $currentStateEnterTime = $enter;
                                            }
                                        }
                                    }
                                }

                                if ($isCurrentStateOutage && $currentStateEnterTime === null) {
                                    $currentStateEnterTime = strtotime($e['create_time']);
                                }
                            ?>
                                <div class="p-3 mb-3 border <?= $isStale ? 'border-danger border-2' : 'border-secondary' ?> rounded bg-dark">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span class="badge bg-secondary me-2 fs-6">#<?= $e['id'] ?></span>
                                            <span class="fw-bold fs-4 text-white me-2"><?= htmlspecialchars(($e['title'] ?? '') ?: 'Incident #' . $e['id']) ?></span>
                                            <span class="badge <?= badgeStatusNocView($e['state_name'] ?? 'Detected') ?> fs-6"><?= htmlspecialchars($e['state_name'] ?? 'Detected') ?></span>
                                            <?php if ($isStale): ?>
                                                <span class="badge bg-danger ms-1 fs-6"><i class="fa-solid fa-bell me-1"></i>SLA Stale (<?= $minutesSinceUpdate ?>m)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end text-secondary small">
                                            <div class="fs-6">Age: <strong class="text-info dynamic-age" data-create-time="<?= strtotime($e['create_time']) ?>"><?= humanTime(strtotime($e['create_time'])) ?></strong></div>
                                        </div>
                                    </div>

                                    <p class="fs-6 mb-2 text-light"><?= htmlspecialchars($e['description'] ?? '') ?></p>

                                    <div class="d-flex flex-wrap align-items-center gap-3 mb-2 fs-6">
                                        <span class="text-secondary">Department: <strong class="text-white"><?= htmlspecialchars($e['department_name'] ?: 'General') ?></strong></span>
                                        <span class="text-secondary">| Impact Score: <strong class="text-danger counter-animate dynamic-impact-score" data-past-seconds="<?= $pastOutageSeconds ?>" data-enter-time="<?= $currentStateEnterTime ?? 0 ?>" data-customers="<?= (int)($e['customers_affected'] ?? 0) ?>" data-is-outage="<?= $isCurrentStateOutage ? '1' : '0' ?>" data-target="<?= (int)($e['impactScore'] ?? 0) ?>"><?= number_format((int)($e['impactScore'] ?? 0)) ?></strong></span>
                                        <span class="text-secondary">| Affected: <strong class="text-warning"><?= number_format((int)($e['customers_affected'] ?? 0)) ?></strong> customers</span>
                                    </div>

                                    <?php if ($lastUpdate): ?>
                                        <div class="p-3 rounded bg-slate-900 border border-slate-700 small mt-2">
                                            <div class="text-info fw-bold mb-1 fs-6"><i class="fa-solid fa-comment-dots me-2"></i>Latest Update (<?= $lastUpdate['create_time'] ?>):</div>
                                            <div class="text-light fs-6"><?= htmlspecialchars($lastUpdate['update_text']) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Column 2: Scheduled Changes (3/12 on Widescreen) -->
            <div class="col-xl-3 col-lg-4">
                <div class="noc-card h-100">
                    <div class="noc-card-header text-primary d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-calendar-days me-2"></i>Changes (Next 48h)</span>
                        <span class="badge bg-primary text-white fs-6"><?= count($changes) ?></span>
                    </div>
                    <div class="table-responsive noc-card-body-scroll">
                        <table class="table table-dark noc-table align-middle">
                            <thead>
                                <tr>
                                    <th>Change Title</th>
                                    <th>Status</th>
                                    <th>Window</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($changes)): ?>
                                    <tr><td colspan="3" class="text-center text-secondary py-4">No maintenance windows starting in the next 48 hours.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($changes as $c):
                                        $title = !empty($c['changeTitle']) ? $c['changeTitle'] : (!empty($c['workOrderTitle']) ? $c['workOrderTitle'] : 'Change #' . $c['changeId']);
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-white fs-6"><?= htmlspecialchars($title) ?></div>
                                                <div class="text-secondary" style="font-size:0.8rem;">#<?= htmlspecialchars($c['changeId']) ?></div>
                                            </td>
                                            <td><span class="badge <?= badgeStatusNocView($c['changeStatus']) ?>"><?= htmlspecialchars($c['changeStatus']) ?></span></td>
                                            <td><small class="text-light"><?= htmlspecialchars($c['plannedStartTime']) ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 48-Hour Hourly Outage Impact Score Graph (Full Landscape Width) -->
        <div class="row">
            <div class="col-12">
                <div class="noc-card">
                    <div class="noc-card-header text-warning d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-chart-column me-2"></i>Last 48 Hours - Outage Impact Score per Hour</span>
                        <span class="badge bg-dark border border-secondary text-warning fs-6">48-Hour Hourly Outage Metric</span>
                    </div>
                    <div class="p-3" style="height: 210px; position: relative;">
                        <canvas id="impactTrendChart"></canvas>
                    </div>
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

    // 60-Second Refresh Countdown Progress Bar
    const REFRESH_SECONDS = 60;
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

    // Auto-scroll scrollable card bodies smoothly within the 60s refresh cycle
    function initAutoScroll() {
        const scrollContainers = document.querySelectorAll('.noc-card-body-scroll');
        const startTime = performance.now();
        const totalDuration = REFRESH_SECONDS * 1000; // 60000ms
        const pauseStart = 3000; // 3s pause at top
        const pauseEnd = 5000;   // 5s pause at bottom
        const scrollDuration = totalDuration - pauseStart - pauseEnd; // 52000ms

        function step(now) {
            const elapsed = now - startTime;
            scrollContainers.forEach(el => {
                const maxScroll = el.scrollHeight - el.clientHeight;
                if (maxScroll > 0) {
                    if (elapsed < pauseStart) {
                        el.scrollTop = 0;
                    } else if (elapsed < pauseStart + scrollDuration) {
                        const progress = (elapsed - pauseStart) / scrollDuration;
                        el.scrollTop = progress * maxScroll;
                    } else {
                        el.scrollTop = maxScroll;
                    }
                }
            });
            if (elapsed < totalDuration) {
                requestAnimationFrame(step);
            }
        }
        requestAnimationFrame(step);
    }

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

    function updateDynamicImpactScores() {
        const now = Math.floor(Date.now() / 1000);

        document.querySelectorAll('.dynamic-impact-score').forEach(el => {
            const pastSec = parseInt(el.getAttribute('data-past-seconds') || '0', 10);
            const enterTime = parseInt(el.getAttribute('data-enter-time') || '0', 10);
            const customers = parseInt(el.getAttribute('data-customers') || '0', 10);
            const isOutage = el.getAttribute('data-is-outage') === '1';

            let currentSec = 0;
            if (isOutage && enterTime > 0) {
                currentSec = Math.max(0, now - enterTime);
            }

            const totalSec = pastSec + currentSec;
            const totalMins = totalSec / 60;
            const score = Math.round(totalMins * customers);

            el.textContent = score.toLocaleString();
        });

        document.querySelectorAll('.dynamic-age').forEach(el => {
            const createTime = parseInt(el.getAttribute('data-create-time') || '0', 10);
            if (!createTime) return;
            const diff = Math.max(0, now - createTime);

            let text = '';
            if (diff < 60) {
                text = diff + 's';
            } else if (diff < 3600) {
                const m = Math.floor(diff / 60);
                const s = diff % 60;
                text = m + 'm ' + s + 's';
            } else if (diff < 86400) {
                const h = Math.floor(diff / 3600);
                const m = Math.floor((diff % 3600) / 60);
                text = h + 'h ' + m + 'm';
            } else {
                const d = Math.floor(diff / 86400);
                const h = Math.floor((diff % 86400) / 3600);
                text = d + 'd ' + h + 'h';
            }
            el.textContent = text;
        });
    }

    setInterval(updateDynamicImpactScores, 1000);

    // Dynamic Count-Up Animation for Impact Score & Metrics on Page Load
    document.addEventListener('DOMContentLoaded', () => {
        updateDynamicImpactScores();
        initAutoScroll();
        document.querySelectorAll('.counter-animate').forEach(el => {
            const target = parseInt(el.getAttribute('data-target') || '0', 10);
            if (isNaN(target) || target <= 0) {
                el.textContent = '0';
                return;
            }
            let current = 0;
            const duration = 1200; // ms
            const steps = 30;
            const increment = Math.ceil(target / steps);
            const stepTime = duration / steps;

            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                el.textContent = current.toLocaleString();
            }, stepTime);
        });

        // Chart.js 48-Hour Impact Trend Graph
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
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        bodyFont: { size: 14 },
                        titleFont: { size: 14 }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#94a3b8', font: { size: 12 } },
                        grid: { color: '#1e293b' }
                    },
                    y: {
                        ticks: { color: '#94a3b8', font: { size: 12 } },
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
