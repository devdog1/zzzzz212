<?php
/*
Plugin Name: Incident Management
Description: Comprehensive Incident Management System with event tracking, updates, OTRS integration, NetBox circuit management, MS Teams notifications, analytics, reporting, and REST API.
Version: 1.0.0
Author: Incident Management Team
Permissions: manage_events, view_events, manage_settings, manage_departments
Roles: incident_manager:manage_events,view_events,manage_settings,manage_departments; incident_viewer:view_events
*/

if (!defined('APP_ROOT') && !class_exists('PluginManager')) {
    exit;
}

require_once __DIR__ . '/models/helper_functions.php';
require_once __DIR__ . '/models/EventManager.php';
require_once __DIR__ . '/models/OTRSClient.php';
require_once __DIR__ . '/models/OTRSDB.php';
require_once __DIR__ . '/models/NetBoxClient.php';

// 1. Navigation Menu Filter
PluginManager::getInstance()->addFilter('theme_nav_links', function ($links) {
    if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
        return $links;
    }

    $links[] = [
        'route' => 'incident_active',
        'label' => 'Incidents',
        'icon'  => 'fa-solid fa-triangle-exclamation',
        'permission' => 'incident_management_view_events',
        'children' => [
            ['label' => 'Active Incidents', 'icon' => 'fa-solid fa-fire', 'route' => 'incident_active', 'permission' => 'incident_management_view_events'],
            ['label' => 'Closed Archive', 'icon' => 'fa-solid fa-box-archive', 'route' => 'incident_closed', 'permission' => 'incident_management_view_events'],
            ['label' => 'Search Incidents', 'icon' => 'fa-solid fa-magnifying-glass', 'route' => 'incident_search', 'permission' => 'incident_management_view_events'],
            ['label' => 'Statistics', 'icon' => 'fa-solid fa-chart-line', 'route' => 'incident_statistics', 'permission' => 'incident_management_view_events'],
            ['label' => 'Reports', 'icon' => 'fa-solid fa-file-contract', 'route' => 'incident_reports', 'permission' => 'incident_management_view_events'],
            ['label' => 'Network Overview', 'icon' => 'fa-solid fa-network-wired', 'route' => 'incident_overview', 'permission' => 'incident_management_view_events'],
            ['label' => 'Change Calendar', 'icon' => 'fa-solid fa-calendar-days', 'route' => 'incident_calendar', 'permission' => 'incident_management_view_events'],
            ['label' => 'Reference Data', 'icon' => 'fa-solid fa-sitemap', 'route' => 'incident_departments', 'permission' => 'incident_management_manage_departments'],
            ['label' => 'Incident Settings', 'icon' => 'fa-solid fa-sliders', 'route' => 'incident_settings', 'permission' => 'incident_management_manage_settings'],
        ]
    ];
    return $links;
});

// 2. Route Registration
PluginManager::getInstance()->addAction('register_routes', function () {
    $routes = [
        'incident_active'      => 'index-view.php',
        'incident_closed'      => 'closed-view.php',
        'incident_search'      => 'search-view.php',
        'incident_statistics'  => 'statistics-view.php',
        'incident_reports'     => 'reports-view.php',
        'incident_departments' => 'departments-view.php',
        'incident_settings'    => 'settings-view.php',
        'incident_calendar'    => 'calendar-view.php',
        'incident_overview'    => 'overview-view.php',
        'incident_api'         => 'api-view.php',
    ];

    foreach ($routes as $route => $viewFile) {
        PluginManager::getInstance()->registerRoute($route, function () use ($viewFile) {
            require_once __DIR__ . '/views/' . $viewFile;
        });
    }
});

// 3. Home Dashboard Widget
PluginManager::getInstance()->addAction('index_dashboard_widgets', function ($userContext) {
    if (!has_permission('incident_management_view_events') && !has_permission('events.manage') && !has_permission('admin.panel')) {
        return;
    }

    $activeCount = 0;
    $totalImpact = 0;
    $assignedTickets = [];
    $OTRSTicketLink = '#';

    try {
        $em = new EventManager($userContext['display_name'] ?? 'system');
        $activeEvents = $em->listEvents(false);
        $activeCount = count($activeEvents);
        $stats = $em->getStatistics();
        $totalImpact = $stats['total_impact'] ?? 0;

        $OTRSTicketLink = $em->getDefault('otrs_ticket_link') ?: '#';
        $userEmail = $_SESSION['user']['email'] ?? ($userContext['username'] ?? '');

        $otrsDB = $em->getOTRSDB();
        if ($otrsDB && $otrsDB->isConnected() && !empty($userEmail)) {
            $assignedTickets = $otrsDB->getUserAssignedTickets($userEmail);
        }
    } catch (Throwable $e) {
        // Fallback for errors
    }

    $badgeStatus = function(string $value): string {
        $v = strtolower($value);
        if (str_contains($v, 'open')) return 'bg-danger';
        if (str_contains($v, 'progress')) return 'bg-warning text-dark';
        if (str_contains($v, 'closed') || str_contains($v, 'successful')) return 'bg-success';
        if (str_contains($v, 'approved')) return 'bg-primary';
        return 'bg-secondary';
    };
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm border-start border-5 border-danger text-start h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div>
                            <h6 class="card-title fw-bold mb-0 text-dark">Active Incidents</h6>
                            <small class="text-muted">Incident Management System</small>
                        </div>
                        <div class="bg-danger-subtle rounded-circle p-2 text-center" style="width: 45px; height: 45px;">
                            <i class="fa-solid fa-triangle-exclamation text-danger fs-4"></i>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fs-3 fw-bold text-danger"><?= $activeCount ?></span>
                        <span class="badge bg-secondary">Impact Score: <?= number_format($totalImpact) ?></span>
                    </div>
                </div>
                <div class="text-end mt-2">
                    <a href="<?= url_for('incident_active') ?>" class="btn btn-sm btn-outline-danger">
                        <i class="fa-solid fa-fire me-1"></i>View Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- My Assigned Open Tickets (OTRS) Widget -->
    <div class="col-md-6 col-lg-8">
        <div class="card shadow-sm border text-start h-100">
            <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold"><i class="fa-solid fa-user-check me-2"></i>My Assigned Open Tickets (OTRS)</span>
                <span class="badge bg-dark text-white"><?= count($assignedTickets) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 250px;">
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
                            <?php if (empty($assignedTickets)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4 small">No open tickets currently assigned to you.</td></tr>
                            <?php else: ?>
                                <?php foreach ($assignedTickets as $ticket): ?>
                                    <tr>
                                        <td class="fw-bold text-dark small"><?= htmlspecialchars($ticket['tickettitle']) ?></td>
                                        <td><code><?= htmlspecialchars($ticket['ticketnumber']) ?></code></td>
                                        <td><small class="text-muted"><?= humanTime(strtotime($ticket['changetime'])) ?></small></td>
                                        <td><small><?= htmlspecialchars($ticket['queuename']) ?></small></td>
                                        <td><span class="badge <?= $badgeStatus($ticket['statetype']) ?>"><?= htmlspecialchars($ticket['statetype']) ?></span></td>
                                        <td class="text-end">
                                            <a class="btn btn-xs btn-outline-primary btn-sm" target="_blank" href="<?= $OTRSTicketLink . $ticket['ticketid'] ?>">
                                                <i class="fa-solid fa-external-link me-1"></i>Open
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light p-2 text-end">
                <a href="<?= url_for('incident_overview') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-network-wired me-1"></i>Network Overview
                </a>
            </div>
        </div>
    </div>
    <?php
});

// 4. Background Task Registration
PluginManager::getInstance()->addAction('init_scheduler', function ($scheduler) {
    $scheduler->registerTask(
        'incident_sync_task',
        'incident_management_run_sync',
        300,
        'incident-management'
    );
});

function incident_management_run_sync() {
    log_action('INCIDENT_SYNC_TASK_RUN', ['status' => 'completed']);
}

// 5. Inter-Plugin Service Registry
PluginManager::getInstance()->registerService(
    'get_active_incidents',
    function() {
        $em = new EventManager();
        return $em->listEvents(false);
    },
    'incident-management'
);

PluginManager::getInstance()->registerService(
    'get_incident_stats',
    function() {
        $em = new EventManager();
        return $em->getStatistics();
    },
    'incident-management'
);

PluginManager::getInstance()->registerService(
    'create_incident',
    function($data) {
        $user = $_SESSION['user']['name'] ?? 'system';
        $em = new EventManager($user);
        return $em->createEvent($data);
    },
    'incident-management'
);

// 6. Activation / Deactivation Hooks
PluginManager::getInstance()->addAction('plugin_activate_incident-management', function() {
    require_once __DIR__ . '/../../PluginDatabase.php';
    $sqlFile = __DIR__ . '/sql/install.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $db = get_db_connection();
        if (method_exists($db, 'getAttribute') && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql);
            $sql = str_replace('ENGINE=InnoDB;', ';', $sql);
            $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            $sql = str_replace('ON UPDATE CURRENT_TIMESTAMP', '', $sql);
        }
        $db->exec($sql);
    }
    log_action('INCIDENT_PLUGIN_ACTIVATE_SUCCESS', []);
});

PluginManager::getInstance()->addAction('plugin_deactivate_incident-management', function($purge_tables = false) {
    if (!$purge_tables) {
        return;
    }
    require_once __DIR__ . '/../../PluginDatabase.php';
    $sqlFile = __DIR__ . '/sql/uninstall.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $db = get_db_connection();
        $db->exec($sql);
    }
    log_action('INCIDENT_PLUGIN_DEACTIVATE_SUCCESS', ['purged' => true]);
});
