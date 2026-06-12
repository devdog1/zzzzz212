<?php
require_once __DIR__ . "/autoload.php";
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');
require_once $docRoot . '/inc/config.php';

// Logic to force SQLite ONLY if MySQL config is missing or invalid
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

require_once $docRoot . '/inc/functions.php';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$auth = null;


$user = ["name"=>"TestUser"];

/*
|--------------------------------------------------------------------------
| PAGE SETTINGS
|--------------------------------------------------------------------------
*/

$pageTitle = "Overview";

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$currentTime = time();

// For demo/sandbox purposes, we handle OTRSDB failure gracefully if DB doesn't exist
try {
    $otrsDB = new OTRSDB($config);
    $problems = $otrsDB->getProblemTickets();
    $maint = $otrsDB->getMaintTickets();
    $changes= $otrsDB->getChangeOverview();
} catch (Exception $e) {
    $problems = [];
    $maint = [];
    $changes = [];
    $otrsError = $e->getMessage();
}

$OTRSTicketLink = $config['otrs']['ticket_link'] ?? '#';
$OTRSChangeLink = $config['otrs']['change_link'] ?? '#';

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function badge(string $value): string
{
    $v = strtolower($value);

    return match (true) {

        str_contains($v, 'open')
            => 'bg-danger',

        str_contains($v, 'progress')
            => 'bg-warning text-dark',

        str_contains($v, 'in progress')
            => 'bg-warning text-dark',

        str_contains($v, 'closed')
            => 'bg-success',

        str_contains($v, 'successful')
            => 'bg-success',

        str_contains($v, 'approved')
            => 'bg-primary',

        default
            => 'bg-secondary'
    };
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?> - Incident Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }

        .dashboard-card {
            border: 0;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.35rem 1rem rgba(0,0,0,0.08);
        }

        .dashboard-card .card-header {
            padding: 0.9rem 1.1rem;
            font-weight: 600;
        }

        .table > :not(caption) > * > * {
            padding: 0.8rem 0.75rem;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(13,110,253,0.04);
        }

        .table-warning {
            --bs-table-bg: rgba(255,193,7,0.18);
        }

        .stat-box {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: #fff;
            border-radius: 1rem;
            padding: 1.25rem;
            box-shadow: 0 0.35rem 1rem rgba(13,110,253,0.25);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
        }
    </style>
</head>
<body>

<?php
// We need an EventManager instance for the navigation builder
$em = new EventManager($user['name'], $auth);
$nav = new NavigationBuilder($em->db, null);
echo $nav->render();
?>

<div class="container py-4">

    <?php if (isset($otrsError)): ?>
        <div class="alert alert-warning mb-4">
            <strong>OTRS Integration Note:</strong> <?= htmlspecialchars($otrsError) ?> (Using empty dataset for display)
        </div>
    <?php endif; ?>

    <!-- =========================================================
         TOP SUMMARY
    ========================================================= -->

    <div class="row g-4 mb-4">

        <div class="col-12 col-md-4">

            <div class="stat-box h-100">

                <div class="small text-white-50 mb-2">
                    Open Problems
                </div>

                <div class="stat-number">
                    <?= count($problems) ?>
                </div>

            </div>

        </div>

        <div class="col-12 col-md-4">

            <div class="stat-box h-100">

                <div class="small text-white-50 mb-2">
                    Upcoming Changes
                </div>

                <div class="stat-number">
                    <?= count($changes) ?>
                </div>

            </div>

        </div>

        <div class="col-12 col-md-4">

            <div class="stat-box h-100">

                <div class="small text-white-50 mb-2">
                    Maintenance Tickets
                </div>

                <div class="stat-number">
                    <?= count($maint) ?>
                </div>

            </div>

        </div>

    </div>

    <!-- =========================================================
         PAGE HEADER
    ========================================================= -->

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div>

            <h4 class="mb-1 fw-bold">
                Network Overview
            </h4>

            <div class="text-muted">
                Live operational status dashboard from OTRS
            </div>

        </div>

        <div class="text-end">

            <div class="small text-muted">
                Last Refresh
            </div>

            <div class="fw-semibold">
                <?= date('Y-m-d H:i') ?>
            </div>

        </div>

    </div>

    <!-- =========================================================
         PROBLEMS
    ========================================================= -->

    <div class="card dashboard-card mb-4">

        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">

            <span>
                Current Problems
            </span>

            <span class="badge bg-light text-dark">
                <?= count($problems) ?>
            </span>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">

                        <tr>
                            <th>Ticket</th>
                            <th>#</th>
                            <th>Updated</th>
                            <th>Queue</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($problems)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No active problems reported.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($problems as $ticket): ?>

                        <tr>

                            <td class="fw-semibold">
                                <?= htmlspecialchars($ticket['tickettitle']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($ticket['ticketnumber']) ?>
                            </td>

                            <td>
                                <?= humanTime(strtotime($ticket['changetime'])) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($ticket['queuename']) ?>
                            </td>

                            <td>

                                <span class="badge <?= badge($ticket['statetype']) ?>">

                                    <?= htmlspecialchars($ticket['statetype']) ?>

                                </span>

                            </td>

                            <td class="text-end">

                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    target="_blank"
                                    href="<?= $OTRSTicketLink . $ticket['ticketid'] ?>"
                                >
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

    <!-- =========================================================
         LOWER GRID
    ========================================================= -->

    <div class="row g-4">

        <!-- =====================================================
             CHANGES
        ====================================================== -->

        <div class="col-12 col-xl-8">

            <div class="card dashboard-card h-100">

                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">

                    <span>
                        Upcoming Changes
                    </span>

                    <span class="badge bg-light text-dark">
                        <?= count($changes) ?>
                    </span>

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
                                <tr><td colspan="6" class="text-center text-muted py-4">No upcoming changes scheduled.</td></tr>
                            <?php endif; ?>

                            <?php foreach ($changes as $ticket): ?>

                                <?php

                                $active =
                                    (
                                        strtotime($ticket['plannedStartTime']) <= $currentTime &&
                                        strtotime($ticket['plannedEndTime']) >= $currentTime
                                    )
                                    ||
                                    strtolower($ticket['changeStatus']) === 'in progress';

                                ?>

                                <tr class="<?= $active ? 'table-warning' : '' ?>">

                                    <td class="fw-semibold">
                                        <?= htmlspecialchars($ticket['changeTitle']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($ticket['changeId']) ?>
                                    </td>

                                    <td>

                                        <span class="badge <?= badge($ticket['changeStatus']) ?>">

                                            <?= ucwords($ticket['changeStatus']) ?>

                                        </span>

                                    </td>

                                    <td>
                                        <?= htmlspecialchars($ticket['plannedStartTime']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($ticket['plannedEndTime']) ?>
                                    </td>

                                    <td class="text-end">

                                        <a
                                            class="btn btn-sm btn-outline-primary"
                                            target="_blank"
                                            href="<?= $OTRSChangeLink . $ticket['changeId'] ?>"
                                        >
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

        <!-- =====================================================
             MAINTENANCE
        ====================================================== -->

        <div class="col-12 col-xl-4">

            <div class="card dashboard-card h-100">

                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">

                    <span>
                        Maintenance
                    </span>

                    <span class="badge bg-light text-dark">
                        <?= count($maint) ?>
                    </span>

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
                                <tr><td colspan="3" class="text-center text-muted py-4">No maintenance tickets found.</td></tr>
                            <?php endif; ?>

                            <?php foreach ($maint as $ticket): ?>

                                <tr>

                                    <td class="fw-semibold">

                                        <?= htmlspecialchars($ticket['tickettitle']) ?>

                                        <div class="small text-muted">

                                            <?= htmlspecialchars($ticket['ticketnumber']) ?>

                                        </div>

                                    </td>

                                    <td>

                                        <span class="badge <?= badge($ticket['statetype']) ?>">

                                            <?= htmlspecialchars($ticket['statetype']) ?>

                                        </span>

                                    </td>

                                    <td class="text-end">

                                        <a
                                            class="btn btn-sm btn-outline-primary"
                                            target="_blank"
                                            href="<?= $OTRSTicketLink . $ticket['ticketid'] ?>"
                                        >
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

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
