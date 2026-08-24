<?php

$pdbPath = __DIR__ . '/../../../PluginDatabase.php';
if (file_exists($pdbPath)) {
    require_once $pdbPath;
} elseif (file_exists(__DIR__ . '/../../PluginDatabase.php')) {
    require_once __DIR__ . '/../../PluginDatabase.php';
}

class EventManager {
    public $pdb;
    private $db;
    private $currentUser;
    private $auth;
    private $otrs = null;
    private $netbox = null;
    private $lastError = null;

    private $allowedEventFields = [
        'title', 'type_id', 'ticket_id', 'ticket_nr', 'department_id', 'customers_affected',
        'description', 'state_id', 'teams_message_Id', 'impactScoreNotified', 'impactScore', 'teams_chat_id'
    ];

    private $outageStates = ['Detected', 'Acknowledged', 'Investigating', 'Identified', 'Mitigating', 'Reopened'];

    public function __construct($currentUser = 'system', $auth = null) {
        $this->pdb = new PluginDatabase('incident-management');
        $this->db = get_db_connection();
        $this->currentUser = $currentUser;
        $this->auth = $auth;

        $this->ensureSchemaColumns();

        try {
            $config = [];
            $configPath = __DIR__ . '/../../../config.php';
            if (file_exists($configPath)) {
                $config = require $configPath;
            }

            $otrsUrl = $this->getDefault('otrs_url') ?: ($config['api']['otrs']['url'] ?? '');
            $otrsKey = $this->getDefault('otrs_key') ?: ($config['api']['otrs']['key'] ?? '');

            if (!empty($otrsUrl)) {
                $this->otrs = new OTRSClient([
                    'api' => [
                        'otrs' => [
                            'url' => $otrsUrl,
                            'key' => $otrsKey,
                            'default' => [
                                'type' => $this->getDefault('otrs_type') ?: ($config['api']['otrs']['default']['type'] ?? 'Unclassified'),
                                'queue' => $this->getDefault('otrs_queue') ?: ($config['api']['otrs']['default']['queue'] ?? 'Raw'),
                                'priority' => $this->getDefault('otrs_priority') ?: ($config['api']['otrs']['default']['priority'] ?? '3 normal'),
                                'state' => $this->getDefault('otrs_state') ?: ($config['api']['otrs']['default']['state'] ?? 'new'),
                                'userID' => $this->getDefault('otrs_user_id') ?: ($config['api']['otrs']['default']['userID'] ?? '1')
                            ]
                        ]
                    ]
                ]);
            }

            $nbUrl = $this->getDefault('netbox_url') ?: ($config['api']['netbox']['url'] ?? '');
            $nbToken = $this->getDefault('netbox_token') ?: ($config['api']['netbox']['token'] ?? '');

            if (!empty($nbUrl) && !empty($nbToken)) {
                $this->netbox = new NetBoxClient([
                    'api' => [
                        'netbox' => [
                            'url' => $nbUrl,
                            'token' => $nbToken
                        ]
                    ]
                ]);
            }
        } catch (Throwable $e) {
            // Ignore config loading errors
        }
    }

    private function ensureSchemaColumns() {
        try {
            $stmt = $this->pdb->query("SHOW COLUMNS FROM plug_incident_management_wb_events LIKE 'title'");
            if (!$stmt->fetch()) {
                $this->pdb->query("ALTER TABLE plug_incident_management_wb_events ADD COLUMN title VARCHAR(255) DEFAULT NULL");
            }
        } catch (Throwable $t) {
            try {
                $this->pdb->query("ALTER TABLE plug_incident_management_wb_events ADD COLUMN title VARCHAR(255) DEFAULT NULL");
            } catch (Throwable $t2) {}
        }

        $refTables = [
            'plug_incident_management_type',
            'plug_incident_management_department',
            'plug_incident_management_state',
            'plug_incident_management_service',
            'plug_incident_management_tag',
            'plug_incident_management_area'
        ];

        foreach ($refTables as $tbl) {
            try {
                $stmt = $this->pdb->query("SHOW COLUMNS FROM `$tbl` LIKE 'is_disabled'");
                if (!$stmt->fetch()) {
                    $this->pdb->query("ALTER TABLE `$tbl` ADD COLUMN is_disabled TINYINT(1) DEFAULT 0");
                }
            } catch (Throwable $t) {
                try {
                    $this->pdb->query("ALTER TABLE `$tbl` ADD COLUMN is_disabled TINYINT(1) DEFAULT 0");
                } catch (Throwable $t2) {}
            }
        }

        $defaultSettings = [
            'otrs_queue' => ['Raw', 'OTRS Queue Name or Queue ID for new ticket creation'],
            'otrs_url' => ['', 'OTRS API Base URL'],
            'otrs_key' => ['', 'OTRS API Key'],
            'otrs_type' => ['Unclassified', 'OTRS Default Ticket Type'],
            'otrs_state' => ['new', 'OTRS Default Ticket State'],
            'otrs_priority' => ['3 normal', 'OTRS Default Ticket Priority'],
            'otrs_user_id' => ['1', 'OTRS Default User ID / Agent ID for ticket creation'],
            'teams_enabled' => ['1', 'Enable Microsoft Teams integration and chat creation (0 or 1)']
        ];

        foreach ($defaultSettings as $k => $v) {
            try {
                $stmt = $this->pdb->query("SELECT setting_key FROM plug_incident_management_defaults WHERE setting_key = ?", [$k]);
                if (!$stmt->fetch()) {
                    $this->pdb->query("INSERT INTO plug_incident_management_defaults (setting_key, setting_value, description) VALUES (?, ?, ?)", [$k, $v[0], $v[1]]);
                }
            } catch (Throwable $t) {}
        }
    }

    public function setCurrentUser($user) {
        $this->currentUser = $user;
    }

    public function getOTRSDB() {
        $host   = $this->getDefault('otrs_db_host');
        $dbname = $this->getDefault('otrs_db_name');
        $user   = $this->getDefault('otrs_db_user');
        $pass   = $this->getDefault('otrs_db_pass');

        if (!empty($host) && !empty($dbname)) {
            $otrsDB = new OTRSDB([
                'dbhost' => $host,
                'dbname' => $dbname,
                'dbuser' => $user,
                'dbpass' => $pass
            ]);
            if ($otrsDB->isConnected()) {
                return $otrsDB;
            }
        }
        return null;
    }

    // --- Audit Logging ---

    private function logAudit($tableName, $recordId, $action, $oldValues, $newValues) {
        $sql = "INSERT INTO plug_incident_management_audit_log (table_name, record_id, action, old_values, new_values, user)
                VALUES (?, ?, ?, ?, ?, ?)";
        $this->pdb->query($sql, [
            $tableName,
            $recordId,
            $action,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $this->currentUser
        ]);
    }

    // --- Event Management ---

    public function createEvent($data) {
        $filteredData = array_intersect_key($data, array_flip($this->allowedEventFields));
        $filteredData['create_user'] = $this->currentUser;

        // Auto-generate title if not explicitly provided
        if (empty($filteredData['title'])) {
            $areaParts = [];
            if (!empty($data['areas'])) {
                $areaParts = is_array($data['areas']) ? $data['areas'] : explode(',', $data['areas']);
            }
            $areaParts = array_filter(array_map('trim', $areaParts));

            $serviceParts = [];
            if (!empty($data['service_ids']) && is_array($data['service_ids'])) {
                foreach ($data['service_ids'] as $sid) {
                    if (empty($sid)) continue;
                    $stmt = $this->pdb->query("SELECT name FROM plug_incident_management_service WHERE id = ?", [$sid]);
                    $row = $stmt->fetch();
                    if ($row) $serviceParts[] = $row['name'];
                }
            }

            $areaStr = !empty($areaParts) ? implode(', ', $areaParts) : '';
            $serviceStr = !empty($serviceParts) ? implode(', ', $serviceParts) : '';

            if ($areaStr && $serviceStr) {
                $filteredData['title'] = "$areaStr - $serviceStr";
            } elseif ($areaStr) {
                $filteredData['title'] = $areaStr;
            } elseif ($serviceStr) {
                $filteredData['title'] = $serviceStr;
            } else {
                $filteredData['title'] = "Incident - " . date('Y-m-d H:i');
            }
        }

        // Sanitize foreign key references (state_id, type_id, department_id)
        if (!empty($filteredData['state_id'])) {
            $check = $this->pdb->query("SELECT id FROM plug_incident_management_state WHERE id = ?", [$filteredData['state_id']]);
            if (!$check->fetch()) unset($filteredData['state_id']);
        }
        if (empty($filteredData['state_id'])) {
            $stmt = $this->pdb->query("SELECT id FROM plug_incident_management_state WHERE name = 'Identified'");
            $state = $stmt->fetch();
            if ($state) {
                $filteredData['state_id'] = $state['id'];
            } else {
                $stmt = $this->pdb->query("SELECT id FROM plug_incident_management_state ORDER BY id ASC LIMIT 1");
                $state = $stmt->fetch();
                if ($state) $filteredData['state_id'] = $state['id'];
            }
        }

        if (!empty($filteredData['type_id'])) {
            $check = $this->pdb->query("SELECT id FROM plug_incident_management_type WHERE id = ?", [$filteredData['type_id']]);
            if (!$check->fetch()) unset($filteredData['type_id']);
        }

        if (!empty($filteredData['department_id'])) {
            $check = $this->pdb->query("SELECT id FROM plug_incident_management_department WHERE id = ?", [$filteredData['department_id']]);
            if (!$check->fetch()) unset($filteredData['department_id']);
        }

        $fields = array_keys($filteredData);
        $placeholders = array_fill(0, count($fields), '?');

        $sql = "INSERT INTO plug_incident_management_wb_events (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $this->pdb->query($sql, array_values($filteredData));

        $eventId = (int)$this->db->lastInsertId();
        if ($eventId <= 0) {
            $stmt = $this->pdb->query("SELECT MAX(id) as max_id FROM plug_incident_management_wb_events");
            $eventId = (int)($stmt->fetch()['max_id'] ?? 0);
        }

        if ($eventId <= 0) {
            $this->lastError = "Failed to obtain valid event ID post insertion.";
            return false;
        }

        if (isset($data['service_ids']) && is_array($data['service_ids'])) {
            $this->updateEventServices($eventId, $data['service_ids']);
        }

        if (isset($data['tags']) && !empty($data['tags'])) {
            $tagsArray = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
            $this->updateEventTags($eventId, $tagsArray);
        }

        if (isset($data['areas']) && !empty($data['areas'])) {
            $areasArray = is_array($data['areas']) ? $data['areas'] : explode(',', $data['areas']);
            $this->updateEventAreas($eventId, $areasArray);
        }

        if (isset($filteredData['state_id'])) {
            $this->logStateTransition($eventId, $filteredData['state_id']);
        }

        $this->logAudit('plug_incident_management_wb_events', $eventId, 'CREATE', null, $filteredData);

        if (isset($filteredData['department_id'])) {
            $this->initTeamsChat($eventId, $filteredData['department_id'], $filteredData['description'] ?? '');
        }

        $this->initOTRSTicket($eventId, $filteredData['description'] ?? '');

        return $eventId;
    }

    private function isEventClosed($eventId) {
        $stmt = $this->pdb->query("SELECT s.name FROM plug_incident_management_wb_events e JOIN plug_incident_management_state s ON e.state_id = s.id WHERE e.id = ?", [$eventId]);
        $res = $stmt->fetch();
        return ($res && strtolower($res['name']) === 'closed');
    }

    public function updateEvent($eventId, $data) {
        $isClosed = $this->isEventClosed($eventId);

        if ($isClosed && !isset($data['state_id'])) {
            return false;
        }

        $oldEvent = $this->getEvent($eventId);
        if (!$oldEvent) return false;

        $filteredData = array_intersect_key($data, array_flip($this->allowedEventFields));
        $filteredData['update_user'] = $this->currentUser;

        if (isset($filteredData['state_id'])) {
            $check = $this->pdb->query("SELECT id FROM plug_incident_management_state WHERE id = ?", [$filteredData['state_id']]);
            if (!$check->fetch()) unset($filteredData['state_id']);
        }

        if (isset($filteredData['type_id'])) {
            $check = $this->pdb->query("SELECT id FROM plug_incident_management_type WHERE id = ?", [$filteredData['type_id']]);
            if (!$check->fetch()) unset($filteredData['type_id']);
        }

        if (isset($filteredData['department_id'])) {
            $check = $this->pdb->query("SELECT id FROM plug_incident_management_department WHERE id = ?", [$filteredData['department_id']]);
            if (!$check->fetch()) unset($filteredData['department_id']);
        }

        if (isset($filteredData['state_id']) && $filteredData['state_id'] != $oldEvent['state_id']) {
            $this->closeLastStateHistory($eventId);
            $this->logStateTransition($eventId, $filteredData['state_id']);
            $filteredData['impactScore'] = $this->calculateImpactScore($eventId, $filteredData['customers_affected'] ?? $oldEvent['customers_affected']);
        }

        if (isset($filteredData['customers_affected']) && $filteredData['customers_affected'] != $oldEvent['customers_affected']) {
            $filteredData['impactScore'] = $this->calculateImpactScore($eventId, $filteredData['customers_affected']);
        }

        if (!empty($filteredData)) {
            $fields = [];
            $values = [];
            foreach ($filteredData as $key => $value) {
                $fields[] = "$key = ?";
                $values[] = $value;
            }
            $values[] = $eventId;

            $sql = "UPDATE plug_incident_management_wb_events SET " . implode(', ', $fields) . " WHERE id = ?";
            $this->pdb->query($sql, $values);
        }

        if (!$isClosed && isset($data['service_ids'])) {
            $this->updateEventServices($eventId, (array)$data['service_ids']);
        }

        if (!$isClosed && isset($data['tags'])) {
            $tagsArray = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
            $this->updateEventTags($eventId, $tagsArray);
        }

        if (!$isClosed && isset($data['areas'])) {
            $areasArray = is_array($data['areas']) ? $data['areas'] : explode(',', $data['areas']);
            $this->updateEventAreas($eventId, $areasArray);
        }

        if (isset($filteredData['department_id']) && $filteredData['department_id'] != $oldEvent['department_id']) {
            $this->syncTeamsChatMembers($eventId, $filteredData['department_id']);
        }

        $newEvent = $this->getEvent($eventId);
        $this->logAudit('plug_incident_management_wb_events', $eventId, 'UPDATE', $oldEvent, $newEvent);

        $this->notifyTeamsOfMetadataChange($oldEvent, $newEvent);
        $this->notifyOTRSOfMetadataChange($oldEvent, $newEvent);

        if (strtolower($newEvent['state_name'] ?? '') === 'closed' && strtolower($oldEvent['state_name'] ?? '') !== 'closed') {
            $this->sendClosureSummary($eventId);
        }

        return true;
    }

    private function notifyTeamsOfMetadataChange($old, $new) {
        if ($this->getDefault('teams_enabled') === '0') return;

        $changes = [];
        $fields = [
            'title' => 'Subject/Title',
            'state_name' => 'Status',
            'type_name' => 'Type',
            'department_name' => 'Department',
            'customers_affected' => 'Customers Affected',
            'ticket_nr' => 'Ticket #'
        ];

        foreach ($fields as $key => $label) {
            if (($old[$key] ?? '') != ($new[$key] ?? '')) {
                $changes[] = ['title' => $label, 'value' => ($old[$key] ?? '') . " -> " . ($new[$key] ?? '')];
            }
        }

        $arrayFields = ['services', 'tags', 'areas'];
        foreach ($arrayFields as $key) {
            $oldNames = array_column($old[$key] ?? [], 'name');
            $newNames = array_column($new[$key] ?? [], 'name');
            sort($oldNames);
            sort($newNames);
            if ($oldNames !== $newNames) {
                $added = array_diff($newNames, $oldNames);
                $removed = array_diff($oldNames, $newNames);
                $delta = [];
                if (!empty($added)) $delta[] = "Added: " . implode(', ', $added);
                if (!empty($removed)) $delta[] = "Removed: " . implode(', ', $removed);

                $label = ucfirst($key);
                $changes[] = ['title' => $label, 'value' => implode('; ', $delta)];
            }
        }

        if (!empty($changes)) {
            $card = $this->getAdaptiveCardBase("Incident Metadata Updated (#" . $new['id'] . ")", 'accent');
            $card['body'][] = [
                'type' => 'FactSet',
                'facts' => $changes
            ];
            $card['body'][] = [
                'type' => 'TextBlock',
                'text' => "Updated by " . $this->currentUser . " at " . date('H:i:s'),
                'size' => 'Small',
                'isSubtle' => true,
                'horizontalAlignment' => 'Right'
            ];
            $this->postCardToTeamsChat($new['id'], $card);
        }
    }

    public function calculateImpactScore($eventId, $customers) {
        $history = $this->getStateHistory($eventId);
        $totalOutageSeconds = 0;

        foreach ($history as $h) {
            if (in_array(ucfirst(strtolower($h['state_name'] ?? '')), $this->outageStates)) {
                $enter = strtotime($h['enter_time']);
                $exit = $h['exit_time'] ? strtotime($h['exit_time']) : time();
                $totalOutageSeconds += ($exit - $enter);
            }
        }

        $outageMinutes = $totalOutageSeconds / 60;
        return (int)round($outageMinutes * (int)$customers);
    }

    public function getEvent($eventId) {
        $sql = "SELECT e.*, t.name as type_name, d.name as department_name, s.name as state_name
                FROM plug_incident_management_wb_events e
                LEFT JOIN plug_incident_management_type t ON e.type_id = t.id
                LEFT JOIN plug_incident_management_department d ON e.department_id = d.id
                LEFT JOIN plug_incident_management_state s ON e.state_id = s.id
                WHERE e.id = ?";
        $stmt = $this->pdb->query($sql, [$eventId]);
        $event = $stmt->fetch();
        if ($event) {
            $event['services'] = $this->getEventServices($eventId);
            $event['tags'] = $this->getEventTags($eventId);
            $event['areas'] = $this->getEventAreas($eventId);
            $event['state_history'] = $this->getStateHistory($eventId);
            $event['circuits'] = $this->getEventCircuits($eventId);
        }
        return $event;
    }

    public function listEvents($includeClosed = false) {
        $where = $includeClosed ? "" : "WHERE s.name != 'Closed' OR s.name IS NULL";
        $sql = "SELECT e.*, t.name as type_name, d.name as department_name, s.name as state_name
                FROM plug_incident_management_wb_events e
                LEFT JOIN plug_incident_management_type t ON e.type_id = t.id
                LEFT JOIN plug_incident_management_department d ON e.department_id = d.id
                LEFT JOIN plug_incident_management_state s ON e.state_id = s.id
                $where
                ORDER BY e.create_time DESC";

        $events = $this->pdb->query($sql)->fetchAll();
        foreach ($events as &$e) {
            $e['services'] = $this->getEventServices($e['id']);
            $e['tags'] = $this->getEventTags($e['id']);
            $e['areas'] = $this->getEventAreas($e['id']);
            $e['circuits'] = $this->getEventCircuits($e['id']);
        }
        return $events;
    }

    // --- State History ---

    private function logStateTransition($eventId, $stateId) {
        if (!$eventId || !$stateId) return;
        $sql = "INSERT INTO plug_incident_management_event_state_history (event_id, state_id, user) VALUES (?, ?, ?)";
        $this->pdb->query($sql, [$eventId, $stateId, $this->currentUser]);
    }

    private function closeLastStateHistory($eventId) {
        $sql = "UPDATE plug_incident_management_event_state_history SET exit_time = CURRENT_TIMESTAMP
                WHERE event_id = ? AND exit_time IS NULL";
        $this->pdb->query($sql, [$eventId]);
    }

    public function getStateHistory($eventId) {
        return $this->pdb->query("SELECT h.*, s.name as state_name
                                 FROM plug_incident_management_event_state_history h
                                 JOIN plug_incident_management_state s ON h.state_id = s.id
                                 WHERE h.event_id = ?
                                 ORDER BY h.enter_time ASC", [$eventId])->fetchAll();
    }

    // --- Services ---

    public function updateEventServices($eventId, $serviceIds) {
        if (!$eventId) return;
        $this->pdb->query("DELETE FROM plug_incident_management_event_services WHERE event_id = ?", [$eventId]);
        $serviceIds = array_unique(array_filter((array)$serviceIds));
        foreach ($serviceIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0) continue;
            $check = $this->pdb->query("SELECT id FROM plug_incident_management_service WHERE id = ?", [$sid]);
            if ($check->fetch()) {
                $this->pdb->query("INSERT INTO plug_incident_management_event_services (event_id, service_id) VALUES (?, ?)", [$eventId, $sid]);
            }
        }
    }

    public function getEventServices($eventId) {
        return $this->pdb->query("SELECT s.* FROM plug_incident_management_service s
                                 JOIN plug_incident_management_event_services es ON s.id = es.service_id
                                 WHERE es.event_id = ?", [$eventId])->fetchAll();
    }

    // --- Tags ---

    public function updateEventTags($eventId, $tags) {
        if (!$eventId) return;
        $this->pdb->query("DELETE FROM plug_incident_management_event_tags WHERE event_id = ?", [$eventId]);
        $uniqueTags = array_unique(array_filter(array_map('trim', (array)$tags)));
        foreach ($uniqueTags as $tagName) {
            if (empty($tagName)) continue;

            $tagId = $this->ensureRefExists('plug_incident_management_tag', $tagName);
            if ($tagId) {
                $this->pdb->query("INSERT INTO plug_incident_management_event_tags (event_id, tag_id) VALUES (?, ?)", [$eventId, $tagId]);
            }
        }
    }

    public function getEventTags($eventId) {
        return $this->pdb->query("SELECT t.* FROM plug_incident_management_tag t
                                 JOIN plug_incident_management_event_tags et ON t.id = et.tag_id
                                 WHERE et.event_id = ?", [$eventId])->fetchAll();
    }

    // --- Circuits (NetBox) ---

    public function addCircuit($eventId, $circuitId, $cid, $provider) {
        if (!$eventId || !$circuitId) return false;
        $check = $this->pdb->query("SELECT circuit_id FROM plug_incident_management_event_circuits WHERE event_id = ? AND circuit_id = ?", [$eventId, $circuitId]);
        if ($check->fetch()) {
            return true;
        }
        $sql = "INSERT INTO plug_incident_management_event_circuits (event_id, circuit_id, circuit_cid, provider) VALUES (?, ?, ?, ?)";
        $this->pdb->query($sql, [$eventId, $circuitId, $cid, $provider]);
        $this->logAudit('plug_incident_management_event_circuits', $eventId, 'ADD_CIRCUIT', null, ['circuit_id' => $circuitId, 'cid' => $cid]);
        return true;
    }

    public function removeCircuit($eventId, $circuitId) {
        $sql = "DELETE FROM plug_incident_management_event_circuits WHERE event_id = ? AND circuit_id = ?";
        $this->pdb->query($sql, [$eventId, $circuitId]);
        $this->logAudit('plug_incident_management_event_circuits', $eventId, 'REMOVE_CIRCUIT', ['circuit_id' => $circuitId], null);
        return true;
    }

    public function getEventCircuits($eventId) {
        return $this->pdb->query("SELECT * FROM plug_incident_management_event_circuits WHERE event_id = ?", [$eventId])->fetchAll();
    }

    // --- Areas ---

    public function updateEventAreas($eventId, $areas) {
        if (!$eventId) return;
        $this->pdb->query("DELETE FROM plug_incident_management_event_areas WHERE event_id = ?", [$eventId]);
        $uniqueAreas = array_unique(array_filter(array_map('trim', (array)$areas)));
        foreach ($uniqueAreas as $areaName) {
            if (empty($areaName)) continue;

            $areaId = $this->ensureRefExists('plug_incident_management_area', $areaName);
            if ($areaId) {
                $this->pdb->query("INSERT INTO plug_incident_management_event_areas (event_id, area_id) VALUES (?, ?)", [$eventId, $areaId]);
            }
        }
    }

    public function getEventAreas($eventId) {
        return $this->pdb->query("SELECT a.* FROM plug_incident_management_area a
                                 JOIN plug_incident_management_event_areas ea ON a.id = ea.area_id
                                 WHERE ea.event_id = ?", [$eventId])->fetchAll();
    }

    // --- Generic Reference Data Helpers ---

    private function ensureRefExists($table, $name) {
        $stmt = $this->pdb->query("SELECT id FROM `$table` WHERE name = ?", [$name]);
        $row = $stmt->fetch();
        if (!$row) {
            return $this->createRef($table, $name);
        }
        return $row['id'];
    }

    private function createRef($table, $name) {
        $sql = "INSERT INTO `$table` (name) VALUES (?)";
        $this->pdb->query($sql, [$name]);
        $id = $this->db->lastInsertId();
        $this->logAudit($table, $id, 'CREATE', null, ['name' => $name]);
        return $id;
    }

    private function updateRef($table, $id, $name) {
        $stmt = $this->pdb->query("SELECT * FROM `$table` WHERE id = ?", [$id]);
        $oldRow = $stmt->fetch();
        $sql = "UPDATE `$table` SET name = ? WHERE id = ?";
        $this->pdb->query($sql, [$name, $id]);
        $this->logAudit($table, $id, 'UPDATE', $oldRow, ['name' => $name]);
        return true;
    }

    private function toggleDisabledRef($table, $id, $isDisabled) {
        $val = $isDisabled ? 1 : 0;
        $stmt = $this->pdb->query("SELECT * FROM `$table` WHERE id = ?", [$id]);
        $oldRow = $stmt->fetch();
        $sql = "UPDATE `$table` SET is_disabled = ? WHERE id = ?";
        $this->pdb->query($sql, [$val, $id]);
        $this->logAudit($table, $id, $isDisabled ? 'DISABLE' : 'ENABLE', $oldRow, ['is_disabled' => $val]);
        return true;
    }

    private function listRef($table, $includeDisabled = false) {
        $where = $includeDisabled ? "" : "WHERE is_disabled = 0 OR is_disabled IS NULL";
        return $this->pdb->query("SELECT * FROM `$table` $where ORDER BY name ASC")->fetchAll();
    }

    public function listDepartments($fetchMembers = false, $includeDisabled = false) {
        $depts = $this->listRef('plug_incident_management_department', $includeDisabled);
        if ($fetchMembers && $this->auth && method_exists($this->auth, 'getAccessToken')) {
            $accessToken = $this->auth->getAccessToken();
            if ($accessToken && method_exists($this->auth, 'getSSO')) {
                $sso = $this->auth->getSSO();
                foreach ($depts as &$d) {
                    if (!empty($d['azure_group_id'])) {
                        $d['members'] = $sso->getGroupMembers($accessToken, $d['azure_group_id']) ?? [];
                    } else {
                        $d['members'] = [];
                    }
                }
            }
        }
        return $depts;
    }

    public function createDepartment($name, $azureGroupId = null) {
        $sql = "INSERT INTO plug_incident_management_department (name, azure_group_id) VALUES (?, ?)";
        $this->pdb->query($sql, [$name, $azureGroupId]);
        $id = $this->db->lastInsertId();
        $this->logAudit('plug_incident_management_department', $id, 'CREATE', null, ['name' => $name, 'azure_group_id' => $azureGroupId]);
        return $id;
    }

    public function updateDepartment($id, $data) {
        $stmt = $this->pdb->query("SELECT * FROM plug_incident_management_department WHERE id = ?", [$id]);
        $oldRow = $stmt->fetch();

        $fields = [];
        $values = [];
        foreach (['name', 'azure_group_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $values[] = $data[$f];
            }
        }
        $values[] = $id;

        $sql = "UPDATE plug_incident_management_department SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->pdb->query($sql, $values);
        $this->logAudit('plug_incident_management_department', $id, 'UPDATE', $oldRow, $data);
        return true;
    }

    public function toggleDepartment($id, $isDisabled) { return $this->toggleDisabledRef('plug_incident_management_department', $id, $isDisabled); }

    public function listTypes($includeDisabled = false) { return $this->listRef('plug_incident_management_type', $includeDisabled); }
    public function createType($name) { return $this->createRef('plug_incident_management_type', $name); }
    public function updateType($id, $name) { return $this->updateRef('plug_incident_management_type', $id, $name); }
    public function toggleType($id, $isDisabled) { return $this->toggleDisabledRef('plug_incident_management_type', $id, $isDisabled); }

    public function listStates($includeDisabled = false) { return $this->listRef('plug_incident_management_state', $includeDisabled); }
    public function createState($name) { return $this->createRef('plug_incident_management_state', $name); }
    public function updateState($id, $name) { return $this->updateRef('plug_incident_management_state', $id, $name); }
    public function toggleState($id, $isDisabled) { return $this->toggleDisabledRef('plug_incident_management_state', $id, $isDisabled); }

    public function listServices($includeDisabled = false) { return $this->listRef('plug_incident_management_service', $includeDisabled); }
    public function createService($name) { return $this->createRef('plug_incident_management_service', $name); }
    public function updateService($id, $name) { return $this->updateRef('plug_incident_management_service', $id, $name); }
    public function toggleService($id, $isDisabled) { return $this->toggleDisabledRef('plug_incident_management_service', $id, $isDisabled); }

    public function listAllTags($includeDisabled = false) { return $this->listRef('plug_incident_management_tag', $includeDisabled); }
    public function createTag($name) { return $this->createRef('plug_incident_management_tag', $name); }
    public function updateTag($id, $name) { return $this->updateRef('plug_incident_management_tag', $id, $name); }
    public function toggleTag($id, $isDisabled) { return $this->toggleDisabledRef('plug_incident_management_tag', $id, $isDisabled); }

    public function listAllAreas($includeDisabled = false) { return $this->listRef('plug_incident_management_area', $includeDisabled); }
    public function createArea($name) { return $this->createRef('plug_incident_management_area', $name); }
    public function updateArea($id, $name) { return $this->updateRef('plug_incident_management_area', $id, $name); }
    public function toggleArea($id, $isDisabled) { return $this->toggleDisabledRef('plug_incident_management_area', $id, $isDisabled); }

    // --- System Defaults ---

    public function getDefaults() {
        return $this->pdb->query("SELECT * FROM plug_incident_management_defaults")->fetchAll();
    }

    public function getDefault($key) {
        $stmt = $this->pdb->query("SELECT setting_value FROM plug_incident_management_defaults WHERE setting_key = ?", [$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : null;
    }

    public function updateDefault($key, $value) {
        $old = $this->pdb->query("SELECT * FROM plug_incident_management_defaults WHERE setting_key = ?", [$key])->fetch();
        $this->pdb->query("UPDATE plug_incident_management_defaults SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
        $this->logAudit('plug_incident_management_defaults', 0, 'UPDATE', $old, ['setting_key' => $key, 'setting_value' => $value]);
        return true;
    }

    // --- Event Updates ---

    public function addEventUpdate($eventId, $updateText, $messageExternal = false) {
        if ($this->isEventClosed($eventId)) {
            return false;
        }

        $data = [
            'event_id' => $eventId,
            'update_text' => $updateText,
            'create_user' => $this->currentUser,
            'message_external' => $messageExternal
        ];

        $sql = "INSERT INTO plug_incident_management_event_updates (event_id, update_text, create_user) VALUES (?, ?, ?)";
        $this->pdb->query($sql, [$eventId, $updateText, $this->currentUser]);

        $updateId = $this->db->lastInsertId();
        $this->logAudit('plug_incident_management_event_updates', $updateId, 'CREATE', null, $data);

        if ($messageExternal) {
            $this->sendExternalMessages($eventId, $updateText);
        }

        // Post to Teams Chat
        $card = $this->getAdaptiveCardBase("Incident Update Posted", 'good');
        $card['body'][] = [
            'type' => 'Container',
            'style' => 'emphasis',
            'items' => [
                [
                    'type' => 'TextBlock',
                    'text' => $updateText,
                    'wrap' => true
                ]
            ]
        ];
        $card['body'][] = [
            'type' => 'TextBlock',
            'text' => "Posted by " . $this->currentUser . " at " . date('H:i:s'),
            'size' => 'Small',
            'isSubtle' => true,
            'horizontalAlignment' => 'Right'
        ];
        $this->postCardToTeamsChat($eventId, $card);

        // Add OTRS Article
        $body = "<div style='font-family:sans-serif; border:1px solid #198754; border-radius:5px; padding:15px;'>\r\n";
        $body .= "<h3 style='color:#198754; margin-top:0; border-bottom:1px solid #198754; padding-bottom:5px;'>Incident Update</h3>\r\n";
        $body .= "<div style='padding:10px; background:#f9fff9; border:1px solid #e0eee0; white-space:pre-wrap;'>" . nl2br(htmlspecialchars($updateText)) . "</div>\r\n";
        $body .= "<p style='font-size:0.8rem; color:#666; margin-top:15px;'>\r\n";
        $body .= "Posted by: <b>" . htmlspecialchars($this->currentUser) . "</b><br>\r\n";
        $body .= "Timestamp: " . date('Y-m-d H:i:s') . "\r\n";
        $body .= "</p></div>";

        $this->addOTRSArticle($eventId, "Incident Update", $body);

        return $updateId;
    }

    public function getEventUpdates($eventId) {
        $stmt = $this->pdb->query("SELECT * FROM plug_incident_management_event_updates WHERE event_id = ? ORDER BY create_time DESC", [$eventId]);
        return $stmt->fetchAll();
    }

    // --- Audit Log Retrieval ---

    public function getAuditTrail($tableName, $recordId = null) {
        if ($recordId) {
            $stmt = $this->pdb->query("SELECT * FROM plug_incident_management_audit_log WHERE table_name = ? AND record_id = ? ORDER BY timestamp ASC", [$tableName, $recordId]);
        } else {
            $stmt = $this->pdb->query("SELECT * FROM plug_incident_management_audit_log WHERE table_name = ? ORDER BY timestamp DESC", [$tableName]);
        }
        return $stmt->fetchAll();
    }

    public function searchEvents($criteria, $operator = 'AND') {
        $sql = "SELECT e.*, t.name as type_name, d.name as department_name, s.name as state_name
                FROM plug_incident_management_wb_events e
                LEFT JOIN plug_incident_management_type t ON e.type_id = t.id
                LEFT JOIN plug_incident_management_department d ON e.department_id = d.id
                LEFT JOIN plug_incident_management_state s ON e.state_id = s.id";

        $where = [];
        $params = [];
        $operatorStr = (strtoupper($operator) === 'OR') ? ' OR ' : ' AND ';

        foreach ($criteria as $field => $value) {
            if ($value === '' || $value === null) continue;

            if ($field === 'date_from') {
                $where[] = "e.create_time >= ?";
                $params[] = $value . " 00:00:00";
            } elseif ($field === 'date_to') {
                $where[] = "e.create_time <= ?";
                $params[] = $value . " 23:59:59";
            } elseif (in_array($field, ['title', 'description', 'ticket_nr'])) {
                $where[] = "e.$field LIKE ?";
                $params[] = "%$value%";
            } elseif (in_array($field, ['type_id', 'department_id', 'state_id', 'id'])) {
                $where[] = "e.$field = ?";
                $params[] = $value;
            }
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode($operatorStr, $where);
        }

        $sql .= " ORDER BY e.create_time DESC";

        $events = $this->pdb->query($sql, $params)->fetchAll();
        foreach ($events as &$e) {
            $e['services'] = $this->getEventServices($e['id']);
            $e['tags'] = $this->getEventTags($e['id']);
            $e['areas'] = $this->getEventAreas($e['id']);
            $e['circuits'] = $this->getEventCircuits($e['id']);
        }
        return $events;
    }

    public function getStatistics() {
        $stats = [];

        $stats['counts'] = $this->pdb->query("
            SELECT s.name, COUNT(e.id) as count
            FROM plug_incident_management_state s
            LEFT JOIN plug_incident_management_wb_events e ON s.id = e.state_id
            GROUP BY s.id
        ")->fetchAll();

        $stats['total_impact'] = $this->pdb->query("SELECT SUM(impactScore) as total FROM plug_incident_management_wb_events")->fetch()['total'] ?? 0;

        $sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, enter_time, exit_time)) as avg_seconds
                FROM plug_incident_management_event_state_history h
                JOIN plug_incident_management_state s ON h.state_id = s.id
                WHERE s.name != 'Closed' AND h.exit_time IS NOT NULL";

        try {
            $val = $this->pdb->query($sql)->fetch()['avg_seconds'];
        } catch (Throwable $t) {
            $val = 0;
        }
        $stats['avg_active_time'] = ($val === null) ? 0 : (float)$val;

        $stats['tag_cloud'] = $this->pdb->query("
            SELECT t.name as text, COUNT(et.event_id) as size
            FROM plug_incident_management_tag t
            JOIN plug_incident_management_event_tags et ON t.id = et.tag_id
            GROUP BY t.id ORDER BY size DESC LIMIT 50
        ")->fetchAll();

        return $stats;
    }

    public function getReportData($reportType, $dateFrom = null, $dateTo = null) {
        $where = [];
        $params = [];
        if ($dateFrom) { $where[] = "e.create_time >= ?"; $params[] = $dateFrom . " 00:00:00"; }
        if ($dateTo)   { $where[] = "e.create_time <= ?"; $params[] = $dateTo . " 23:59:59"; }
        $whereStr = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        switch ($reportType) {
            case 'dept_impact':
                return $this->pdb->query("
                    SELECT d.name as label, SUM(e.impactScore) as value
                    FROM plug_incident_management_department d JOIN plug_incident_management_wb_events e ON d.id = e.department_id
                    $whereStr
                    GROUP BY d.id ORDER BY value DESC
                ", $params)->fetchAll();
            case 'type_freq':
                return $this->pdb->query("
                    SELECT t.name as label, COUNT(e.id) as value
                    FROM plug_incident_management_type t JOIN plug_incident_management_wb_events e ON t.id = e.type_id
                    $whereStr
                    GROUP BY t.id ORDER BY value DESC
                ", $params)->fetchAll();
            case 'service_impact':
                return $this->pdb->query("
                    SELECT s.name as label, SUM(e.impactScore) as value
                    FROM plug_incident_management_service s JOIN plug_incident_management_event_services es ON s.id = es.service_id
                    JOIN plug_incident_management_wb_events e ON es.event_id = e.id
                    $whereStr
                    GROUP BY s.id ORDER BY value DESC
                ", $params)->fetchAll();
            case 'location':
                return $this->pdb->query("
                    SELECT a.name as label, COUNT(ea.event_id) as value
                    FROM plug_incident_management_area a JOIN plug_incident_management_event_areas ea ON a.id = ea.area_id
                    JOIN plug_incident_management_wb_events e ON ea.event_id = e.id
                    $whereStr
                    GROUP BY a.id ORDER BY value DESC
                ", $params)->fetchAll();
            case 'tag_usage':
                return $this->pdb->query("
                    SELECT t.name as label, COUNT(et.event_id) as value
                    FROM plug_incident_management_tag t JOIN plug_incident_management_event_tags et ON t.id = et.tag_id
                    JOIN plug_incident_management_wb_events e ON et.event_id = e.id
                    $whereStr
                    GROUP BY t.id ORDER BY value DESC
                ", $params)->fetchAll();
            default: return [];
        }
    }

    private function sendClosureSummary($eventId) {
        $event = $this->getEvent($eventId);
        if (!$event) return;

        $history = $this->getStateHistory($eventId);
        $updates = $this->getEventUpdates($eventId);
        $audit   = $this->getAuditTrail('plug_incident_management_wb_events', $eventId);

        $deptMap = array_column($this->listRef('plug_incident_management_department', true), 'name', 'id');
        $typeMap = array_column($this->listRef('plug_incident_management_type', true), 'name', 'id');

        $timeline = [];

        foreach ($history as $h) {
            $enter = strtotime($h['enter_time']);
            $exit  = $h['exit_time'] ? strtotime($h['exit_time']) : time();
            $duration = $exit - $enter;

            $text = "Status: " . ($h['state_name'] ?? '');
            if (strtolower($h['state_name'] ?? '') !== 'closed') {
                $text .= " (Duration: " . $this->formatDuration($duration) . ")";
            }

            $timeline[$h['enter_time'] . "_status"] = [
                'time' => $h['enter_time'],
                'user' => $h['user'],
                'text' => $text,
                'type' => 'status'
            ];
        }

        foreach ($updates as $u) {
            $timeline[$u['create_time'] . "_update"] = [
                'time' => $u['create_time'],
                'user' => $u['create_user'],
                'text' => "Update: " . $u['update_text'],
                'type' => 'update'
            ];
        }

        foreach ($audit as $a) {
            if ($a['action'] !== 'UPDATE') continue;
            $newVals = json_decode($a['new_values'] ?: '{}', true);
            $oldVals = json_decode($a['old_values'] ?: '{}', true);

            if (!is_array($newVals)) $newVals = [];
            if (!is_array($oldVals)) $oldVals = [];

            $changes = [];
            foreach ($newVals as $k => $v) {
                if (in_array($k, ['update_time', 'update_user', 'state_id'])) continue;

                $oldV = $oldVals[$k] ?? null;
                if ($oldV === $v) continue;

                $label = $k;
                $v_old = $oldV;
                $v_new = $v;

                if (is_array($v)) {
                    $namesOld = array_column($oldV ?: [], 'name');
                    $namesNew = array_column($v ?: [], 'name');
                    sort($namesOld); sort($namesNew);
                    if ($namesOld === $namesNew) continue;

                    $added = array_diff($namesNew, $namesOld);
                    $removed = array_diff($namesOld, $namesNew);
                    $delta = [];
                    if (!empty($added)) $delta[] = "+" . implode(', ', $added);
                    if (!empty($removed)) $delta[] = "-" . implode(', ', $removed);

                    $v_new = implode('; ', $delta);
                    $v_old = "";
                }

                if ($k === 'department_id') { $label = 'Dept'; $v_old = $deptMap[$oldV] ?? $oldV; $v_new = $deptMap[$v] ?? $v; }
                if ($k === 'type_id')       { $label = 'Type'; $v_old = $typeMap[$oldV] ?? $oldV; $v_new = $typeMap[$v] ?? $v; }

                if ($v_old === "") {
                    $changes[] = "$label: $v_new";
                } else {
                    $changes[] = "$label: " . (is_array($v_old) ? json_encode($v_old) : $v_old) . " → " . (is_array($v_new) ? json_encode($v_new) : $v_new);
                }
            }
            if (!empty($changes)) {
                $timeline[$a['timestamp'] . "_audit"] = [
                    'time' => $a['timestamp'],
                    'user' => $a['user'],
                    'text' => "Metadata: " . implode(', ', $changes),
                    'type' => 'audit'
                ];
            }
        }

        ksort($timeline);

        $card = $this->getAdaptiveCardBase("Incident Closed - Final Summary (#$eventId)", 'good');

        $facts = [
            ['title' => 'Subject/Title', 'value' => $event['title'] ?? 'Incident #' . $eventId],
            ['title' => 'Total Impact Score', 'value' => number_format($event['impactScore'])],
            ['title' => 'Final Status', 'value' => $event['state_name']],
            ['title' => 'Duration', 'value' => $this->formatDuration(time() - strtotime($event['create_time']))]
        ];
        $card['body'][] = ['type' => 'FactSet', 'facts' => $facts];
        $card['body'][] = ['type' => 'TextBlock', 'text' => 'Timeline Summary', 'weight' => 'Bolder', 'spacing' => 'Medium'];

        $timelineFacts = [];
        foreach ($timeline as $item) {
            $timelineFacts[] = [
                'title' => date('H:i', strtotime($item['time'])),
                'value' => $item['text'] . " (by " . $item['user'] . ")"
            ];
        }
        $card['body'][] = ['type' => 'FactSet', 'facts' => array_slice($timelineFacts, -15)];

        $this->postCardToTeamsChat($eventId, $card);

        $body = "<div style='font-family:sans-serif; border:2px solid #198754; border-radius:8px; padding:20px;'>\r\n";
        $body .= "<h2 style='color:#198754; margin-top:0; border-bottom:3px solid #198754; padding-bottom:10px;'>Incident Closure Summary</h2>\r\n";
        $body .= "<p><b>Subject/Title:</b> " . htmlspecialchars($event['title'] ?? '') . "<br><b>Final Impact Score:</b> " . number_format($event['impactScore']) . "</p>\r\n";
        $body .= "<h3 style='color:#333; border-bottom:1px solid #ddd;'>Full Incident Timeline</h3>\r\n";
        $body .= "<table style='width:100%; border-collapse:collapse;' cellpadding='5'>\r\n";
        $body .= "<tr style='background:#f4f4f4;'><th style='text-align:left;'>Time</th><th style='text-align:left;'>User</th><th style='text-align:left;'>Event</th></tr>\r\n";

        foreach ($timeline as $item) {
            $body .= "<tr>";
            $body .= "<td style='border-bottom:1px solid #eee; font-size:0.9rem;'>" . $item['time'] . "</td>";
            $body .= "<td style='border-bottom:1px solid #eee; font-size:0.9rem;'>" . htmlspecialchars($item['user']) . "</td>";
            $body .= "<td style='border-bottom:1px solid #eee; font-size:0.9rem;'>" . htmlspecialchars($item['text']) . "</td>";
            $body .= "</tr>\r\n";
        }
        $body .= "</table>\r\n";
        $body .= "</div>";

        $this->addOTRSArticle($eventId, "Closure Summary & Timeline", $body);
    }

    private function formatDuration($seconds) {
        if ($seconds < 60) return $seconds . "s";
        if ($seconds < 3600) return floor($seconds / 60) . "m";
        return floor($seconds / 3600) . "h " . floor(($seconds % 3600) / 60) . "m";
    }

    public function getLastError() {
        return $this->lastError;
    }

    // --- Teams Integration ---

    private function getAdaptiveCardBase($title, $style = 'default') {
        $colorMap = [
            'attention' => 'attention',
            'accent'    => 'accent',
            'good'      => 'good',
            'warning'   => 'warning',
            'default'   => 'default'
        ];
        $color = $colorMap[$style] ?? 'default';

        return [
            'type' => 'AdaptiveCard',
            'version' => '1.2',
            'body' => [
                [
                    'type' => 'Container',
                    'style' => $color,
                    'bleed' => true,
                    'items' => [
                        [
                            'type' => 'TextBlock',
                            'text' => $title,
                            'weight' => 'Bolder',
                            'size' => 'Large',
                            'color' => 'default'
                        ]
                    ]
                ]
            ],
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json'
        ];
    }

    private function formatEventMetadataCard($eventId) {
        $event = $this->getEvent($eventId);
        if (!$event) return null;

        $card = $this->getAdaptiveCardBase("New Incident Reported (#" . $event['id'] . ")", 'attention');

        $facts = [
            ['title' => 'Subject', 'value' => $event['title'] ?? 'Incident #' . $event['id']],
            ['title' => 'Type', 'value' => $event['type_name'] ?? 'N/A'],
            ['title' => 'Status', 'value' => $event['state_name'] ?? 'N/A'],
            ['title' => 'Department', 'value' => $event['department_name'] ?? 'N/A'],
            ['title' => 'Customers', 'value' => number_format($event['customers_affected'])],
            ['title' => 'Impact Score', 'value' => number_format($event['impactScore'])]
        ];

        if (!empty($event['areas']))    $facts[] = ['title' => 'Areas', 'value' => implode(', ', array_column($event['areas'], 'name'))];
        if (!empty($event['services'])) $facts[] = ['title' => 'Services', 'value' => implode(', ', array_column($event['services'], 'name'))];
        if (!empty($event['tags']))     $facts[] = ['title' => 'Tags', 'value' => implode(', ', array_column($event['tags'], 'name'))];
        if (!empty($event['ticket_nr']) && $event['ticket_nr'] !== '0') {
            $facts[] = ['title' => 'OTRS Ticket', 'value' => $event['ticket_nr']];
        }

        $card['body'][] = [
            'type' => 'FactSet',
            'facts' => $facts
        ];

        $card['body'][] = [
            'type' => 'TextBlock',
            'text' => 'Description',
            'weight' => 'Bolder',
            'spacing' => 'Medium'
        ];

        $card['body'][] = [
            'type' => 'Container',
            'style' => 'emphasis',
            'items' => [
                [
                    'type' => 'TextBlock',
                    'text' => $event['description'] ?? '',
                    'wrap' => true,
                    'fontType' => 'Monospace'
                ]
            ]
        ];

        $card['body'][] = [
            'type' => 'TextBlock',
            'text' => "Reported by " . $this->currentUser . " at " . date('H:i:s'),
            'size' => 'Small',
            'isSubtle' => true,
            'horizontalAlignment' => 'Right'
        ];

        return $card;
    }

    private function initTeamsChat($eventId, $departmentId, $description) {
        if ($this->getDefault('teams_enabled') === '0') {
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'TEAMS_CHAT_DISABLED', null, ['reason' => 'Teams integration is disabled in system settings']);
            return;
        }

        if (!$this->auth || !method_exists($this->auth, 'getAccessToken')) {
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'TEAMS_CHAT_FAILED', null, ['error' => 'Auth provider or getAccessToken method is not available']);
            return;
        }

        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) {
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'TEAMS_CHAT_FAILED', null, ['error' => 'No active Azure AD Graph API access token in user session']);
            return;
        }

        $stmt = $this->pdb->query("SELECT name, azure_group_id FROM plug_incident_management_department WHERE id = ?", [$departmentId]);
        $dept = $stmt->fetch();
        $deptGroupId = $dept['azure_group_id'] ?? null;
        $deptName = $dept['name'] ?? 'Unknown Dept';

        $sso = $this->auth->getSSO();
        $members = [];

        if ($deptGroupId) {
            $memberData = $sso->getGroupMembers($accessToken, $deptGroupId);
            if ($memberData !== null) {
                $members = array_column($memberData, 'id');
            }
        }

        $alwaysIncludeGroupId = $this->getDefault('always_include_azure_group_id');
        if ($alwaysIncludeGroupId && $alwaysIncludeGroupId !== $deptGroupId) {
            $extraMembers = $sso->getGroupMembers($accessToken, $alwaysIncludeGroupId);
            if ($extraMembers) {
                $extraIds = array_column($extraMembers, 'id');
                $members = array_unique(array_merge($members, $extraIds));
            }
        }

        $currentUserOid = $_SESSION['user']['azure_oid'] ?? null;
        if ($currentUserOid && !in_array($currentUserOid, $members)) {
            $members[] = $currentUserOid;
        }

        $members = array_unique(array_filter($members));

        $event = $this->getEvent($eventId);
        $titleStr = !empty($event['title']) ? $event['title'] : substr($description, 0, 50);
        $topic = "Incident #$eventId - " . $titleStr;
        $topic = str_replace([':', '"', "'"], ' ', $topic);

        if (count($members) < 2) {
            $errMsg = "Graph API requires at least 2 member OIDs to create a group chat. Only " . count($members) . " member OID(s) resolved.";
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'TEAMS_CHAT_FAILED', null, [
                'error' => $errMsg,
                'topic' => $topic,
                'resolved_members_count' => count($members),
                'department' => $deptName,
                'dept_azure_group_id' => $deptGroupId,
                'always_include_azure_group_id' => $alwaysIncludeGroupId,
                'current_user_oid' => $currentUserOid
            ]);
            return;
        }

        $chat = $sso->createChat($accessToken, $topic, array_values($members), $currentUserOid);

        if ($chat && isset($chat['id'])) {
            $this->pdb->query("UPDATE plug_incident_management_wb_events SET teams_chat_id = ? WHERE id = ?", [$chat['id'], $eventId]);
            $card = $this->formatEventMetadataCard($eventId);
            $sso->sendAdaptiveCardToChat($accessToken, $chat['id'], $card);
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'TEAMS_CHAT_CREATED', null, [
                'teams_chat_id' => $chat['id'],
                'topic' => $topic,
                'members_count' => count($members)
            ]);
        } else {
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'TEAMS_CHAT_FAILED', null, [
                'error' => 'Microsoft Graph API createChat request failed or returned invalid response',
                'topic' => $topic,
                'members_count' => count($members),
                'raw_response' => $chat
            ]);
        }
    }

    private function syncTeamsChatMembers($eventId, $departmentId) {
        if ($this->getDefault('teams_enabled') === '0') return;
        if (!$this->auth || !method_exists($this->auth, 'getAccessToken')) return;

        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) return;

        $event = $this->getEvent($eventId);
        if (!$event || empty($event['teams_chat_id'])) return;

        $stmt = $this->pdb->query("SELECT azure_group_id FROM plug_incident_management_department WHERE id = ?", [$departmentId]);
        $dept = $stmt->fetch();
        $deptGroupId = $dept['azure_group_id'] ?? null;

        $sso = $this->auth->getSSO();
        $members = [];

        if ($deptGroupId) {
            $memberData = $sso->getGroupMembers($accessToken, $deptGroupId);
            if ($memberData !== null) {
                $members = array_column($memberData, 'id');
            }
        }

        if (empty($members)) return;

        $res = $sso->addMembersToChat($accessToken, $event['teams_chat_id'], $members);
        if ($res) {
            $sso->sendMessageToChat($accessToken, $event['teams_chat_id'], "Members from new department added to chat.");
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'TEAMS_MEMBERS_SYNCED', null, ['count' => count($members)]);
        }
    }

    private function postCardToTeamsChat($eventId, $card) {
        if ($this->getDefault('teams_enabled') === '0') return;
        if (!$this->auth || !method_exists($this->auth, 'getAccessToken')) return;
        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) return;

        $stmt = $this->pdb->query("SELECT teams_chat_id FROM plug_incident_management_wb_events WHERE id = ?", [$eventId]);
        $event = $stmt->fetch();
        if (!$event || empty($event['teams_chat_id'])) return;

        $this->auth->getSSO()->sendAdaptiveCardToChat($accessToken, $event['teams_chat_id'], $card);
    }

    // --- OTRS Integration ---

    private function getOTRSUserId() {
        if (!$this->otrs) return null;
        $username = $this->currentUser;
        if (strpos($username, '@') !== false) {
            $username = explode('@', $username)[0];
        }
        $otrsId = $this->otrs->getUserId($username);
        return $otrsId ?: null;
    }

    private function notifyOTRSOfMetadataChange($old, $new) {
        $changes = [];
        $fields = [
            'title' => 'Subject/Title',
            'state_name' => 'Status',
            'type_name' => 'Type',
            'department_name' => 'Department',
            'customers_affected' => 'Customers Affected',
            'ticket_nr' => 'Ticket #'
        ];

        foreach ($fields as $key => $label) {
            if (($old[$key] ?? '') != ($new[$key] ?? '')) {
                $changes[] = "<tr><th style='text-align:left; border-bottom:1px solid #eee;'>$label</th><td style='border-bottom:1px solid #eee;'><strike style='color:#999;'>" . htmlspecialchars($old[$key] ?? '') . "</strike> &rarr; <b>" . htmlspecialchars($new[$key] ?? '') . "</b></td></tr>";
            }
        }

        $arrayFields = ['services', 'tags', 'areas'];
        foreach ($arrayFields as $key) {
            $oldNames = array_column($old[$key] ?? [], 'name');
            $newNames = array_column($new[$key] ?? [], 'name');
            sort($oldNames);
            sort($newNames);
            if ($oldNames !== $newNames) {
                $added = array_diff($newNames, $oldNames);
                $removed = array_diff($oldNames, $newNames);
                $delta = [];
                if (!empty($added)) $delta[] = "<b style='color:#198754;'>+</b> " . htmlspecialchars(implode(', ', $added));
                if (!empty($removed)) $delta[] = "<b style='color:#dc3545;'>-</b> " . htmlspecialchars(implode(', ', $removed));

                $label = ucfirst($key);
                $changes[] = "<tr><th style='text-align:left; border-bottom:1px solid #eee;'>$label</th><td style='border-bottom:1px solid #eee;'>" . implode('; ', $delta) . "</td></tr>";
            }
        }

        if (!empty($changes)) {
            $body = "<div style='font-family:sans-serif; border:1px solid #0d6efd; border-radius:5px; padding:15px;'>\r\n";
            $body .= "<h3 style='color:#0d6efd; margin-top:0; border-bottom:2px solid #0d6efd; padding-bottom:5px;'>Incident Metadata Updated</h3>\r\n";
            $body .= "<table style='width:100%; border-collapse:collapse;' cellpadding='5'>\r\n";
            $body .= implode("\r\n", $changes);
            $body .= "</table>\r\n";
            $body .= "<p style='font-size:0.8rem; color:#666; margin-top:20px; border-top:1px solid #ddd; padding-top:10px;'>\r\n";
            $body .= "Action by: <b>" . htmlspecialchars($this->currentUser) . "</b><br>\r\n";
            $body .= "Timestamp: " . date('Y-m-d H:i:s') . "\r\n";
            $body .= "</p></div>";

            $this->addOTRSArticle($new['id'], "Incident Metadata Updated", $body);
        }
    }

    private function initOTRSTicket($eventId, $description) {
        if (!$this->otrs || $this->getDefault('otrs_enabled') !== '1') return;

        $event = $this->getEvent($eventId);
        $otrsUserId = $this->getOTRSUserId();
        $customerUser = $this->getDefault('otrs_customer_user') ?: 'customer@example.com';
        $queue = $this->getDefault('otrs_queue') ?: 'Raw';
        $title = "Incident #$eventId: " . ($event['title'] ?? substr($description, 0, 100));

        try {
            $params = [
                'title' => $title,
                'customer' => $customerUser,
                'queue' => $queue,
                'body' => $description
            ];
            if ($otrsUserId) $params['userID'] = $otrsUserId;

            $res = $this->otrs->CreateTicket($params);
            if ($res && isset($res['ticketid'])) {
                $this->pdb->query("UPDATE plug_incident_management_wb_events SET ticket_id = ?, ticket_nr = ? WHERE id = ?", [
                    $res['ticketid'],
                    $res['ticketnr'],
                    $eventId
                ]);
                $this->logAudit('plug_incident_management_wb_events', $eventId, 'OTRS_TICKET_CREATED', null, array_merge(['url' => $this->getDefault('otrs_url')], (array)$res));

                $body = "<div style='font-family:sans-serif; border:2px solid #dc3545; border-radius:8px; padding:20px;'>\r\n";
                $body .= "<h2 style='color:#dc3545; margin-top:0; border-bottom:3px solid #dc3545; padding-bottom:10px;'>New Incident Reported</h2>\r\n";

                $body .= "<table style='width:100%; border-collapse:collapse;' cellpadding='8'>\r\n";
                $rows = [
                    "Incident ID" => $event['id'],
                    "Subject/Title" => ($event['title'] ?: 'N/A'),
                    "Type" => ($event['type_name'] ?: 'N/A'),
                    "Current Status" => ($event['state_name'] ?: 'N/A'),
                    "Department" => ($event['department_name'] ?: 'N/A'),
                    "Customers Affected" => number_format($event['customers_affected']),
                    "Impact Score" => number_format($event['impactScore'])
                ];
                if (!empty($event['areas']))    $rows["Affected Areas"] = implode(', ', array_column($event['areas'], 'name'));
                if (!empty($event['services'])) $rows["Impacted Services"] = implode(', ', array_column($event['services'], 'name'));
                if (!empty($event['tags']))     $rows["Incident Tags"] = implode(', ', array_column($event['tags'], 'name'));

                foreach ($rows as $label => $val) {
                    $body .= "<tr><th style='text-align:left; width:180px; border-bottom:1px solid #eee; background:#f9f9f9;'>$label</th><td style='border-bottom:1px solid #eee;'><b>" . htmlspecialchars($val) . "</b></td></tr>\r\n";
                }
                $body .= "</table>\r\n";

                $body .= "<h4 style='margin-bottom:5px; color:#333;'>Description</h4>\r\n";
                $body .= "<div style='background:#fcfcfc; border-left:4px solid #ddd; padding:10px; font-style:italic; white-space:pre-wrap;'>" . nl2br(htmlspecialchars($event['description'] ?? '')) . "</div>\r\n";

                $body .= "<p style='font-size:0.85rem; color:#555; margin-top:25px; border-top:1px solid #ccc; padding-top:15px;'>\r\n";
                $body .= "Reported by: <b>" . htmlspecialchars($this->currentUser) . "</b><br>\r\n";
                $body .= "Timestamp:   " . date('Y-m-d H:i:s') . "\r\n";
                $body .= "</p></div>";

                $this->addOTRSArticle($eventId, "Initial Incident Details", $body);
            } else {
                $errDetails = is_array($res) ? $res : ['error' => 'Unknown OTRS API Error'];
                $errDetails['url'] = $this->getDefault('otrs_url');
                $this->lastError = "OTRS Ticket Creation Failed: " . ($errDetails['error'] ?? 'API Error');
                $this->logAudit('plug_incident_management_wb_events', $eventId, 'OTRS_TICKET_FAILED', null, $errDetails);
            }
        } catch (Exception $e) {
            $errDetails = ['error' => $e->getMessage(), 'url' => $this->getDefault('otrs_url')];
            $this->lastError = "OTRS integration exception: " . $e->getMessage();
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'OTRS_TICKET_FAILED', null, $errDetails);
        }
    }

    private function sendExternalMessages($eventId, $updateText) {
        if (!$this->netbox || $this->getDefault('netbox_enabled') !== '1') return;

        $event = $this->getEvent($eventId);
        $circuits = $event['circuits'] ?? [];
        if (empty($circuits)) return;

        $template = $this->getDefault('external_email_template');
        $subject = "Update regarding incident #" . $eventId;

        foreach ($circuits as $circuit) {
            $details = $this->netbox->getCircuitDetails($circuit['circuit_id']);
            if (!$details || empty($details['tenant'])) continue;

            $tenantId = $details['tenant']['id'];
            $tenant = $this->netbox->getTenantDetails($tenantId);
            if (!$tenant) continue;

            $emailString = $tenant['custom_fields']['Contact Email'] ?? '';
            if (empty($emailString)) continue;

            $rawRecipients = preg_split('/[,;:]/', $emailString);
            $recipients = [];
            foreach ($rawRecipients as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                }
            }

            if (empty($recipients)) continue;

            $message = str_replace(
                ['{circuit_cid}', '{description}', '{update_text}'],
                [$circuit['circuit_cid'], $event['description'] ?? '', $updateText],
                $template
            );

            foreach (array_unique($recipients) as $to) {
                @mail($to, $subject, $message, "From: noreply@example.com");

                $this->pdb->query(
                    "INSERT INTO plug_incident_management_external_message_log (event_id, recipient, subject, message) VALUES (?, ?, ?, ?)",
                    [$eventId, $to, $subject, $message]
                );
            }
        }
    }

    private function addOTRSArticle($eventId, $subject, $body) {
        if (!$this->otrs || $this->getDefault('otrs_enabled') !== '1') return;

        $stmt = $this->pdb->query("SELECT ticket_id FROM plug_incident_management_wb_events WHERE id = ?", [$eventId]);
        $event = $stmt->fetch();
        if (!$event || empty($event['ticket_id']) || $event['ticket_id'] === '0') return;

        $otrsUserId = $this->getOTRSUserId();

        try {
            $params = [
                'Ticketid' => (int)$event['ticket_id'],
                'subject' => $subject,
                'body' => $body
            ];
            if ($otrsUserId) $params['userID'] = $otrsUserId;

            $res = $this->otrs->createArticle($params);
            if ($res && isset($res['articleid'])) {
                $this->logAudit('plug_incident_management_wb_events', $eventId, 'OTRS_ARTICLE_CREATED', null, array_merge(['url' => $this->getDefault('otrs_url')], (array)$res));
            } else {
                $errDetails = is_array($res) ? $res : ['error' => 'Unknown OTRS Article API Error'];
                $errDetails['url'] = $this->getDefault('otrs_url');
                $this->logAudit('plug_incident_management_wb_events', $eventId, 'OTRS_ARTICLE_FAILED', null, $errDetails);
            }
        } catch (Exception $e) {
            $this->logAudit('plug_incident_management_wb_events', $eventId, 'OTRS_ARTICLE_FAILED', null, ['error' => $e->getMessage(), 'url' => $this->getDefault('otrs_url')]);
        }
    }
}
