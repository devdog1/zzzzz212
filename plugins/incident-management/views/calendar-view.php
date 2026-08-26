<?php
// Calendar View for Incident Management Plugin

if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_view_events</code></div>';
    return;
}

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'User');
$em = new EventManager($currentUser);

$OTRSChangeLink = $em->getDefault('otrs_change_link') ?: '#';
$events = [];
$otrsError = null;

try {
    $otrsDB = $em->getOTRSDB();
    if ($otrsDB && $otrsDB->isConnected()) {
        $results = $otrsDB->getChangeQuery();

        foreach ($results as $event) {
            if (in_array(strtolower($event['changeStatus']), ['rejected', 'retracted'])) {
                continue;
            }

            $status = strtolower($event['changeStatus']);
            $backgroundColor = '#0dcaf0';
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
    } else {
        $otrsError = "OTRS Database connection not configured or unreachable. Check OTRS DB host/credentials in Incident Settings.";
    }
} catch (Exception $e) {
    $otrsError = $e->getMessage();
}
?>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

<style>
    .fc-toolbar-title { font-size: 1.25rem !important; font-weight: 700; }
    .fc-event { border-radius: 0.4rem !important; font-size: 0.8rem; cursor: pointer; }
</style>

<div class="row mb-4 text-start">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-calendar-days text-primary me-2"></i>Change Calendar</h1>
        <p class="text-muted">Interactive schedule of planned system changes and maintenance windows from OTRS DB.</p>
    </div>
</div>

<?php if ($otrsError): ?>
    <div class="alert alert-warning shadow-sm text-start mb-4">
        <i class="fa-solid fa-circle-info me-2"></i><strong>OTRS Integration Note:</strong> <?= htmlspecialchars($otrsError) ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm border mb-4 text-start">
    <div class="card-body">
        <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-layer-group me-2 text-info"></i>Calendar Status Legend</h6>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge bg-info p-2">Requested</span>
            <span class="badge bg-primary p-2">Approved</span>
            <span class="badge bg-success p-2">Successful</span>
            <span class="badge bg-danger p-2">Failed</span>
            <span class="badge bg-warning text-dark p-2">Rejected / Retracted (Hidden)</span>
        </div>
    </div>
</div>

<div class="card shadow-sm border text-start">
    <div class="card-body p-3">
        <div id="calendar"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendarEl = document.getElementById('calendar');
    if (!calendarEl || typeof FullCalendar === 'undefined') return;

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
            const tooltipContent = info.event.title + ' | Status: ' + (info.event.extendedProps ? info.event.extendedProps.status : '');
            info.el.setAttribute('title', tooltipContent);
        }
    });
    calendar.render();
});
</script>
