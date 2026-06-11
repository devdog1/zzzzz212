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
require_once $docRoot . '/classes/OTRSDB.php';
require_once $docRoot . '/classes/NavigationBuilder.php';
require_once $docRoot . '/inc/functions.php';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$auth = new Auth($config);
$auth->requireLogin();

$user = $auth->user();

/*
|--------------------------------------------------------------------------
| PAGE SETTINGS
|--------------------------------------------------------------------------
*/

$pageTitle = "Change Calendar";
$OTRSChangeLink = $config['otrs']['change_link'] ?? '#';

/*
|--------------------------------------------------------------------------
| BUILD EVENT ARRAY
|--------------------------------------------------------------------------
*/

$events = [];
try {
    $otrsDB = new OTRSDB($config);
    $results = $otrsDB->getChangeQuery();

    foreach ($results as $event) {

        // Skip hidden statuses
        if (in_array(strtolower($event['changeStatus']), ['rejected', 'retracted'])) {
            continue;
        }

        $status = strtolower($event['changeStatus']);

        /*
        |--------------------------------------------------------------------------
        | STATUS COLORS
        |--------------------------------------------------------------------------
        */

        $backgroundColor = '#0dcaf0'; // Requested
        $borderColor     = '#0dcaf0';

        switch ($status) {

            case 'approved':
                $backgroundColor = '#0d6efd';
                $borderColor     = '#0d6efd';
                break;

            case 'successful':
                $backgroundColor = '#198754';
                $borderColor     = '#198754';
                break;

            case 'failed':
                $backgroundColor = '#dc3545';
                $borderColor     = '#dc3545';
                break;
        }

        $events[] = [
            'id'              => $event['changeId'],
            'title'           => html_entity_decode($event['changeTitle']),
            'start'           => $event['plannedStartTime'],
            'end'             => $event['plannedEndTime'],
            'url'             => $OTRSChangeLink . $event['changeId'],
            'backgroundColor' => $backgroundColor,
            'borderColor'     => $borderColor,
            'textColor'       => '#ffffff',
            'extendedProps'   => [
                'status'       => ucfirst($status),
                'changeNumber' => $event['changeNumber']
            ]
        ];
    }
} catch (Exception $e) {
    $otrsError = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> - Incident Manager</title>

    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

    <style>
        body { background: #f4f6f9; }
        .modern-card { border: 0; border-radius: 1rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.08); }
        #calendar { padding: 1rem; }
        .fc { --fc-border-color: #e9ecef; --fc-page-bg-color: transparent; --fc-neutral-bg-color: #f8f9fa; --fc-list-event-hover-bg-color: #f1f3f5; }
        .fc-toolbar-title { font-size: 1.5rem !important; font-weight: 700; }
        .fc-button { border-radius: 0.6rem !important; padding: 0.45rem 0.85rem !important; font-weight: 600 !important; border: 0 !important; box-shadow: none !important; }
        .fc-event { border-radius: 0.6rem !important; border: 0 !important; padding: 0.15rem; font-size: 0.85rem; font-weight: 600; cursor: pointer; }
        .fc-event:hover { transform: translateY(-1px); transition: all 0.15s ease; box-shadow: 0 0.35rem 0.75rem rgba(0,0,0,0.18); }
        .legend-badge { padding: 0.6rem 0.9rem; font-size: 0.85rem; }
        .fc-daygrid-event-dot { display: none; }
        .fc-scrollgrid { border-radius: 0.75rem; overflow: hidden; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: #edf0f2; }
        .fc-col-header-cell { background: #f8f9fa; padding: 0.5rem 0; }
        .fc-timegrid-slot { height: 2.5rem !important; }
    </style>
</head>
<body>

<?php
// We need an EventManager instance for the navigation builder
$em = new EventManager($user['name'], $auth);
$nav = new NavigationBuilder($em->db, $auth);
echo $nav->render();
?>

<div class="container-fluid py-4">

    <?php if (isset($otrsError)): ?>
        <div class="alert alert-warning mb-4 shadow-sm">
            <strong>OTRS Integration Note:</strong> <?= htmlspecialchars($otrsError) ?>
        </div>
    <?php endif; ?>

    <!-- LEGEND -->
    <div class="card modern-card mb-4">
        <div class="card-body">
            <h5 class="mb-3"><i class="bi bi-info-circle"></i> Legend</h5>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-info legend-badge">Requested</span>
                <span class="badge bg-primary legend-badge">Approved</span>
                <span class="badge bg-success legend-badge">Successful</span>
                <span class="badge bg-danger legend-badge">Failed</span>
                <span class="badge bg-warning text-dark legend-badge">Rejected / Retracted (Hidden)</span>
            </div>
        </div>
    </div>

    <!-- CALENDAR -->
    <div class="card modern-card">
        <div class="card-body">
            <div id="calendar"></div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendarEl = document.getElementById('calendar');
    const calendar = new FullCalendar.Calendar(calendarEl, {
        themeSystem: 'bootstrap5',
        initialView: 'timeGridWeek',
        height: 'auto',
        expandRows: true,
        stickyHeaderDates: true,
        nowIndicator: true,
        editable: false,
        selectable: false,
        navLinks: true,
        dayMaxEvents: true,
        weekNumbers: true,
        slotMinTime: '00:00:00',
        slotMaxTime: '24:00:00',
        firstDay: 1,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        buttonText: {
            today: 'Today',
            month: 'Month',
            week: 'Week',
            day: 'Day',
            list: 'Agenda'
        },
        events: <?= json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            if (info.event.url && info.event.url !== '#') {
                window.open(info.event.url, '_blank');
            }
        },
        eventDidMount: function(info) {
            const tooltipContent = info.event.title + ' | Status: ' + info.event.extendedProps.status;
            info.el.setAttribute('title', tooltipContent);
        }
    });
    calendar.render();
});
</script>

</body>
</html>
