<?php

require_once 'Database.php';

class EventManager {
    public $db;
    private $currentUser;
    private $auth;
    private $otrs = null;
    private $lastError = null;

    private $allowedEventFields = [
        'type_id', 'ticket_id', 'ticket_nr', 'department_id', 'customers_affected',
        'description', 'state_id', 'teams_message_Id', 'impactScoreNotified', 'impactScore', 'teams_chat_id'
    ];

    private $outageStates = ['Detected', 'Acknowledged', 'Investigating', 'Identified', 'Mitigating', 'Reopened'];

    public function __construct($currentUser = 'system', $auth = null) {
        $this->db = new Database();
        $this->currentUser = $currentUser;
        $this->auth = $auth;

        // Try to init OTRS if config exists
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
        $configPath = rtrim($docRoot, '/\\') . '/inc/config.php';
        if (file_exists($configPath)) {
            include $configPath;
            if (isset($config['api']['otrs'])) {
                $this->otrs = new OTRSClient($config);
            }
        }
    }

    public function setCurrentUser($user) {
        $this->currentUser = $user;
    }

    // --- Audit Logging ---

    private function logAudit($tableName, $recordId, $action, $oldValues, $newValues) {
        $sql = "INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, user)
                VALUES (?, ?, ?, ?, ?, ?)";
        $this->db->query($sql, [
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

        // Default to 'Identified' state if not provided
        if (!isset($filteredData['state_id']) || empty($filteredData['state_id'])) {
            $state = $this->db->query("SELECT id FROM state WHERE name = 'Identified'")->fetch();
            if ($state) $filteredData['state_id'] = $state['id'];
        }

        $fields = array_keys($filteredData);
        $placeholders = array_fill(0, count($fields), '?');

        $sql = "INSERT INTO wb_events (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $this->db->query($sql, array_values($filteredData));

        $eventId = $this->db->lastInsertId();

        // Handle Services
        if (isset($data['service_ids']) && is_array($data['service_ids'])) {
            $this->updateEventServices($eventId, $data['service_ids']);
        }

        // Handle Tags
        if (isset($data['tags']) && !empty($data['tags'])) {
            $tagsArray = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
            $this->updateEventTags($eventId, $tagsArray);
        }

        // Handle Areas
        if (isset($data['areas']) && !empty($data['areas'])) {
            $areasArray = is_array($data['areas']) ? $data['areas'] : explode(',', $data['areas']);
            $this->updateEventAreas($eventId, $areasArray);
        }

        // Log initial state in history
        if (isset($filteredData['state_id'])) {
            $this->logStateTransition($eventId, $filteredData['state_id']);
        }

        $this->logAudit('wb_events', $eventId, 'CREATE', null, $filteredData);

        // Handle Teams Chat Creation
        if (isset($filteredData['department_id'])) {
            $this->initTeamsChat($eventId, $filteredData['department_id'], $filteredData['description']);
        }

        // Handle OTRS Ticket Creation
        $this->initOTRSTicket($eventId, $filteredData['description']);

        return $eventId;
    }

    private function isEventClosed($eventId) {
        $stmt = $this->db->query("SELECT s.name FROM wb_events e JOIN state s ON e.state_id = s.id WHERE e.id = ?", [$eventId]);
        $res = $stmt->fetch();
        return ($res && strtolower($res['name']) === 'closed');
    }

    public function updateEvent($eventId, $data) {
        $isClosed = $this->isEventClosed($eventId);

        // If closed, only allow state transition (to re-open)
        if ($isClosed && !isset($data['state_id'])) {
            return false;
        }

        $oldEvent = $this->getEvent($eventId);
        if (!$oldEvent) return false;

        $filteredData = array_intersect_key($data, array_flip($this->allowedEventFields));
        $filteredData['update_user'] = $this->currentUser;

        // Handle State Transition
        if (isset($filteredData['state_id']) && $filteredData['state_id'] != $oldEvent['state_id']) {
            $this->closeLastStateHistory($eventId);
            $this->logStateTransition($eventId, $filteredData['state_id']);

            // Recalculate impact score on every state transition
            $filteredData['impactScore'] = $this->calculateImpactScore($eventId, $filteredData['customers_affected'] ?? $oldEvent['customers_affected']);
        }

        // Recalculate impact if customers affected changed
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

            $sql = "UPDATE wb_events SET " . implode(', ', $fields) . " WHERE id = ?";
            $this->db->query($sql, $values);
        }

        // Handle Services
        if (!$isClosed && isset($data['service_ids'])) {
            $this->updateEventServices($eventId, (array)$data['service_ids']);
        }

        // Handle Tags
        if (!$isClosed && isset($data['tags'])) {
            $tagsArray = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
            $this->updateEventTags($eventId, $tagsArray);
        }

        // Handle Areas
        if (!$isClosed && isset($data['areas'])) {
            $areasArray = is_array($data['areas']) ? $data['areas'] : explode(',', $data['areas']);
            $this->updateEventAreas($eventId, $areasArray);
        }

        // Handle Department Change for Teams Chat
        if (isset($filteredData['department_id']) && $filteredData['department_id'] != $oldEvent['department_id']) {
            $this->syncTeamsChatMembers($eventId, $filteredData['department_id']);
        }

        $newEvent = $this->getEvent($eventId);
        $this->logAudit('wb_events', $eventId, 'UPDATE', $oldEvent, $newEvent);

        $this->notifyTeamsOfMetadataChange($oldEvent, $newEvent);
        $this->notifyOTRSOfMetadataChange($oldEvent, $newEvent);

        if (strtolower($newEvent['state_name']) === 'closed' && strtolower($oldEvent['state_name']) !== 'closed') {
            $this->sendClosureSummary($eventId);
        }

        return true;
    }

    private function notifyTeamsOfMetadataChange($old, $new) {
        $changes = [];
        $fields = [
            'state_name' => 'Status',
            'type_name' => 'Type',
            'department_name' => 'Department',
            'customers_affected' => 'Customers Affected',
            'ticket_nr' => 'Ticket #'
        ];

        foreach ($fields as $key => $label) {
            if ($old[$key] != $new[$key]) {
                $changes[] = ['title' => $label, 'value' => $old[$key] . " -> " . $new[$key]];
            }
        }

        // Check Services, Tags, Areas (Arrays)
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
            if (in_array(ucfirst(strtolower($h['state_name'])), $this->outageStates)) {
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
                FROM wb_events e
                LEFT JOIN type t ON e.type_id = t.id
                LEFT JOIN department d ON e.department_id = d.id
                LEFT JOIN state s ON e.state_id = s.id
                WHERE e.id = ?";
        $stmt = $this->db->query($sql, [$eventId]);
        $event = $stmt->fetch();
        if ($event) {
            $event['services'] = $this->getEventServices($eventId);
            $event['tags'] = $this->getEventTags($eventId);
            $event['areas'] = $this->getEventAreas($eventId);
            $event['state_history'] = $this->getStateHistory($eventId);
        }
        return $event;
    }

    public function listEvents($includeClosed = false) {
        $where = $includeClosed ? "" : "WHERE s.name != 'Closed'";
        $sql = "SELECT e.*, t.name as type_name, d.name as department_name, s.name as state_name
                FROM wb_events e
                LEFT JOIN type t ON e.type_id = t.id
                LEFT JOIN department d ON e.department_id = d.id
                LEFT JOIN state s ON e.state_id = s.id
                $where
                ORDER BY e.create_time DESC";

        $events = $this->db->query($sql)->fetchAll();
        foreach ($events as &$e) {
            $e['services'] = $this->getEventServices($e['id']);
            $e['tags'] = $this->getEventTags($e['id']);
            $e['areas'] = $this->getEventAreas($e['id']);
        }
        return $events;
    }

    // --- State History ---

    private function logStateTransition($eventId, $stateId) {
        $sql = "INSERT INTO event_state_history (event_id, state_id, user) VALUES (?, ?, ?)";
        $this->db->query($sql, [$eventId, $stateId, $this->currentUser]);
    }

    private function closeLastStateHistory($eventId) {
        $sql = "UPDATE event_state_history SET exit_time = CURRENT_TIMESTAMP
                WHERE event_id = ? AND exit_time IS NULL";
        $this->db->query($sql, [$eventId]);
    }

    public function getStateHistory($eventId) {
        return $this->db->query("SELECT h.*, s.name as state_name
                                 FROM event_state_history h
                                 JOIN state s ON h.state_id = s.id
                                 WHERE h.event_id = ?
                                 ORDER BY h.enter_time ASC", [$eventId])->fetchAll();
    }

    // --- Services ---

    public function updateEventServices($eventId, $serviceIds) {
        $this->db->query("DELETE FROM event_services WHERE event_id = ?", [$eventId]);
        foreach ($serviceIds as $sid) {
            if (empty($sid)) continue;
            $this->db->query("INSERT INTO event_services (event_id, service_id) VALUES (?, ?)", [$eventId, $sid]);
        }
    }

    public function getEventServices($eventId) {
        return $this->db->query("SELECT s.* FROM service s
                                 JOIN event_services es ON s.id = es.service_id
                                 WHERE es.event_id = ?", [$eventId])->fetchAll();
    }

    // --- Tags ---

    public function updateEventTags($eventId, $tags) {
        $this->db->query("DELETE FROM event_tags WHERE event_id = ?", [$eventId]);
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;

            $tagId = $this->ensureRefExists('tag', $tagName);

            $sql = (getenv('USE_SQLITE') === 'true')
                ? "INSERT OR IGNORE INTO event_tags (event_id, tag_id) VALUES (?, ?)"
                : "INSERT IGNORE INTO event_tags (event_id, tag_id) VALUES (?, ?)";
            $this->db->query($sql, [$eventId, $tagId]);
        }
    }

    public function getEventTags($eventId) {
        return $this->db->query("SELECT t.* FROM tag t
                                 JOIN event_tags et ON t.id = et.tag_id
                                 WHERE et.event_id = ?", [$eventId])->fetchAll();
    }

    // --- Areas ---

    public function updateEventAreas($eventId, $areas) {
        $this->db->query("DELETE FROM event_areas WHERE event_id = ?", [$eventId]);
        foreach ($areas as $areaName) {
            $areaName = trim($areaName);
            if (empty($areaName)) continue;

            $areaId = $this->ensureRefExists('area', $areaName);

            $sql = (getenv('USE_SQLITE') === 'true')
                ? "INSERT OR IGNORE INTO event_areas (event_id, area_id) VALUES (?, ?)"
                : "INSERT IGNORE INTO event_areas (event_id, area_id) VALUES (?, ?)";
            $this->db->query($sql, [$eventId, $areaId]);
        }
    }

    public function getEventAreas($eventId) {
        return $this->db->query("SELECT a.* FROM area a
                                 JOIN event_areas ea ON a.id = ea.area_id
                                 WHERE ea.event_id = ?", [$eventId])->fetchAll();
    }

    // --- Generic Helpers ---

    private function ensureRefExists($table, $name) {
        $stmt = $this->db->query("SELECT id FROM `$table` WHERE name = ?", [$name]);
        $row = $stmt->fetch();
        if (!$row) {
            return $this->createRef($table, $name);
        }
        return $row['id'];
    }

    private function createRef($table, $name) {
        $sql = "INSERT INTO `$table` (name) VALUES (?)";
        $this->db->query($sql, [$name]);
        $id = $this->db->lastInsertId();
        $this->logAudit($table, $id, 'CREATE', null, ['name' => $name]);
        return $id;
    }

    private function updateRef($table, $id, $name) {
        $stmt = $this->db->query("SELECT * FROM `$table` WHERE id = ?", [$id]);
        $oldRow = $stmt->fetch();
        $sql = "UPDATE `$table` SET name = ? WHERE id = ?";
        $this->db->query($sql, [$name, $id]);
        $this->logAudit($table, $id, 'UPDATE', $oldRow, ['name' => $name]);
        return true;
    }

    private function deleteRef($table, $id) {
        $stmt = $this->db->query("SELECT * FROM `$table` WHERE id = ?", [$id]);
        $oldRow = $stmt->fetch();
        $sql = "DELETE FROM `$table` WHERE id = ?";
        $this->db->query($sql, [$id]);
        $this->logAudit($table, $id, 'DELETE', $oldRow, null);
        return true;
    }

    private function listRef($table) {
        return $this->db->query("SELECT * FROM `$table` ORDER BY name ASC")->fetchAll();
    }

    public function listDepartments($fetchMembers = false) {
        $depts = $this->listRef('department');
        if ($fetchMembers && $this->auth) {
            $this->auth->requireValidToken();
            $accessToken = $this->auth->getAccessToken();
            if ($accessToken) {
                $sso = $this->auth->getSSO();
                foreach ($depts as &$d) {
                    if ($d['azure_group_id']) {
                        $d['members'] = $sso->getGroupMembers($accessToken, $d['azure_group_id']);
                    } else {
                        $d['members'] = [];
                    }
                }
            }
        }
        return $depts;
    }
    public function createDepartment($name, $azureGroupId = null) {
        $sql = "INSERT INTO department (name, azure_group_id) VALUES (?, ?)";
        $this->db->query($sql, [$name, $azureGroupId]);
        $id = $this->db->lastInsertId();
        $this->logAudit('department', $id, 'CREATE', null, ['name' => $name, 'azure_group_id' => $azureGroupId]);
        return $id;
    }
    public function updateDepartment($id, $data) {
        $stmt = $this->db->query("SELECT * FROM department WHERE id = ?", [$id]);
        $oldRow = $stmt->fetch();

        $fields = [];
        $values = [];
        foreach (['name', 'azure_group_id'] as $f) {
            if (isset($data[$f])) {
                $fields[] = "$f = ?";
                $values[] = $data[$f];
            }
        }
        $values[] = $id;

        $sql = "UPDATE department SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->query($sql, $values);
        $this->logAudit('department', $id, 'UPDATE', $oldRow, $data);
        return true;
    }
    public function deleteDepartment($id) { return $this->deleteRef('department', $id); }

    public function listTypes() { return $this->listRef('type'); }
    public function createType($name) { return $this->createRef('type', $name); }
    public function updateType($id, $name) { return $this->updateRef('type', $id, $name); }
    public function deleteType($id) { return $this->deleteRef('type', $id); }

    public function listStates() { return $this->listRef('state'); }
    public function createState($name) { return $this->createRef('state', $name); }
    public function updateState($id, $name) { return $this->updateRef('state', $id, $name); }
    public function deleteState($id) { return $this->deleteRef('state', $id); }

    public function listServices() { return $this->listRef('service'); }
    public function createService($name) { return $this->createRef('service', $name); }
    public function updateService($id, $name) { return $this->updateRef('service', $id, $name); }
    public function deleteService($id) { return $this->deleteRef('service', $id); }

    public function listAllTags() { return $this->listRef('tag'); }
    public function listAllAreas() { return $this->listRef('area'); }

    // --- System Defaults ---

    public function getDefaults() {
        return $this->db->query("SELECT * FROM defaults")->fetchAll();
    }

    public function getDefault($key) {
        $stmt = $this->db->query("SELECT setting_value FROM defaults WHERE setting_key = ?", [$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : null;
    }

    public function updateDefault($key, $value) {
        $old = $this->db->query("SELECT * FROM defaults WHERE setting_key = ?", [$key])->fetch();
        $this->db->query("UPDATE defaults SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
        $this->logAudit('defaults', 0, 'UPDATE', $old, ['setting_key' => $key, 'setting_value' => $value]);
        return true;
    }

    // --- Event Updates ---

    public function addEventUpdate($eventId, $updateText) {
        if ($this->isEventClosed($eventId)) {
            return false;
        }

        $data = [
            'event_id' => $eventId,
            'update_text' => $updateText,
            'create_user' => $this->currentUser
        ];

        $sql = "INSERT INTO event_updates (event_id, update_text, create_user) VALUES (?, ?, ?)";
        $this->db->query($sql, [$eventId, $updateText, $this->currentUser]);

        $updateId = $this->db->lastInsertId();
        $this->logAudit('event_updates', $updateId, 'CREATE', null, $data);

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
        $body .= "<h3 style='color:#198754; margin-top:0; border-bottom:2px solid #198754; padding-bottom:5px;'>Incident Update</h3>\r\n";
        $body .= "<div style='padding:10px; background:#f9fff9; border:1px solid #e0eee0; white-space:pre-wrap;'>" . nl2br(htmlspecialchars($updateText)) . "</div>\r\n";
        $body .= "<p style='font-size:0.8rem; color:#666; margin-top:15px;'>\r\n";
        $body .= "Posted by: <b>" . htmlspecialchars($this->currentUser) . "</b><br>\r\n";
        $body .= "Timestamp: " . date('Y-m-d H:i:s') . "\r\n";
        $body .= "</p></div>";

        $this->addOTRSArticle($eventId, "Incident Update", $body);

        return $updateId;
    }

    public function getEventUpdates($eventId) {
        $stmt = $this->db->query("SELECT * FROM event_updates WHERE event_id = ? ORDER BY create_time DESC", [$eventId]);
        return $stmt->fetchAll();
    }

    // --- Audit Log Retrieval ---

    public function getAuditTrail($tableName, $recordId = null) {
        if ($recordId) {
            $stmt = $this->db->query("SELECT * FROM audit_log WHERE table_name = ? AND record_id = ? ORDER BY timestamp ASC", [$tableName, $recordId]);
        } else {
            $stmt = $this->db->query("SELECT * FROM audit_log WHERE table_name = ? ORDER BY timestamp DESC", [$tableName]);
        }
        return $stmt->fetchAll();
    }

    public function searchEvents($criteria, $operator = 'AND') {
        $sql = "SELECT e.*, t.name as type_name, d.name as department_name, s.name as state_name
                FROM wb_events e
                LEFT JOIN type t ON e.type_id = t.id
                LEFT JOIN department d ON e.department_id = d.id
                LEFT JOIN state s ON e.state_id = s.id";

        $where = [];
        $params = [];
        $operator = (strtoupper($operator) === 'OR') ? ' OR ' : ' AND ';

        foreach ($criteria as $field => $value) {
            if ($value === '' || $value === null) continue;

            if ($field === 'date_from') {
                $where[] = "e.create_time >= ?";
                $params[] = $value . " 00:00:00";
            } elseif ($field === 'date_to') {
                $where[] = "e.create_time <= ?";
                $params[] = $value . " 23:59:59";
            } elseif (in_array($field, ['description', 'ticket_nr'])) {
                $where[] = "e.$field LIKE ?";
                $params[] = "%$value%";
            } elseif (in_array($field, ['type_id', 'department_id', 'state_id', 'id'])) {
                $where[] = "e.$field = ?";
                $params[] = $value;
            }
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode($operator, $where);
        }

        $sql .= " ORDER BY e.create_time DESC";

        $events = $this->db->query($sql, $params)->fetchAll();
        foreach ($events as &$e) {
            $e['services'] = $this->getEventServices($e['id']);
            $e['tags'] = $this->getEventTags($e['id']);
            $e['areas'] = $this->getEventAreas($e['id']);
        }
        return $events;
    }

    public function getStatistics() {
        $stats = [];

        // Basic Totals
        $stats['counts'] = $this->db->query("
            SELECT s.name, COUNT(e.id) as count
            FROM state s LEFT JOIN wb_events e ON s.id = e.state_id
            GROUP BY s.id
        ")->fetchAll();

        $stats['total_impact'] = $this->db->query("SELECT SUM(impactScore) as total FROM wb_events")->fetch()['total'] ?? 0;

        // Avg Resolution Time (State: Closed)
        $sql = "SELECT AVG(STRFTIME('%s', exit_time) - STRFTIME('%s', enter_time)) as avg_seconds
                FROM event_state_history h
                JOIN state s ON h.state_id = s.id
                WHERE s.name != 'Closed' AND h.exit_time IS NOT NULL";

        if (getenv('USE_SQLITE') !== 'true') {
            $sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, enter_time, exit_time)) as avg_seconds
                    FROM event_state_history h
                    JOIN state s ON h.state_id = s.id
                    WHERE s.name != 'Closed' AND h.exit_time IS NOT NULL";
        }
        $val = $this->db->query($sql)->fetch()['avg_seconds'];
        $stats['avg_active_time'] = ($val === null) ? 0 : (float)$val;

        // Tag Cloud Data
        $stats['tag_cloud'] = $this->db->query("
            SELECT t.name as text, COUNT(et.event_id) as size
            FROM tag t JOIN event_tags et ON t.id = et.tag_id
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
                return $this->db->query("
                    SELECT d.name as label, SUM(e.impactScore) as value
                    FROM department d JOIN wb_events e ON d.id = e.department_id
                    $whereStr
                    GROUP BY d.id ORDER BY value DESC
                ", $params)->fetchAll();
            case 'type_freq':
                return $this->db->query("
                    SELECT t.name as label, COUNT(e.id) as value
                    FROM type t JOIN wb_events e ON t.id = e.type_id
                    $whereStr
                    GROUP BY t.id ORDER BY value DESC
                ", $params)->fetchAll();
            case 'service_impact':
                return $this->db->query("
                    SELECT s.name as label, SUM(e.impactScore) as value
                    FROM service s JOIN event_services es ON s.id = es.service_id
                    JOIN wb_events e ON es.event_id = e.id
                    $whereStr
                    GROUP BY s.id ORDER BY value DESC
                ", $params)->fetchAll();
            case 'location':
                return $this->db->query("
                    SELECT a.name as label, COUNT(ea.event_id) as value
                    FROM area a JOIN event_areas ea ON a.id = ea.area_id
                    JOIN wb_events e ON ea.event_id = e.id
                    $whereStr
                    GROUP BY a.id ORDER BY value DESC
                ", $params)->fetchAll();
            case 'tag_usage':
                return $this->db->query("
                    SELECT t.name as label, COUNT(et.event_id) as value
                    FROM tag t JOIN event_tags et ON t.id = et.tag_id
                    JOIN wb_events e ON et.event_id = e.id
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
        $audit   = $this->getAuditTrail('wb_events', $eventId);

        // Fetch translation maps for human-readable labels
        $deptMap = array_column($this->listRef('department'), 'name', 'id');
        $typeMap = array_column($this->listRef('type'), 'name', 'id');

        // --- Build Timeline ---
        $timeline = [];

        // Status Transitions
        foreach ($history as $h) {
            $enter = strtotime($h['enter_time']);
            $exit  = $h['exit_time'] ? strtotime($h['exit_time']) : time();
            $duration = $exit - $enter;

            $text = "Status: " . $h['state_name'];
            if (strtolower($h['state_name']) !== 'closed') {
                $text .= " (Duration: " . $this->formatDuration($duration) . ")";
            }

            $timeline[$h['enter_time'] . "_status"] = [
                'time' => $h['enter_time'],
                'user' => $h['user'],
                'text' => $text,
                'type' => 'status'
            ];
        }

        // Timeline Updates
        foreach ($updates as $u) {
            $timeline[$u['create_time'] . "_update"] = [
                'time' => $u['create_time'],
                'user' => $u['create_user'],
                'text' => "Update: " . $u['update_text'],
                'type' => 'update'
            ];
        }

        // Metadata Changes from Audit
        foreach ($audit as $a) {
            if ($a['action'] !== 'UPDATE') continue;
            $newVals = json_decode($a['new_values'] ?: '{}', true);
            $oldVals = json_decode($a['old_values'] ?: '{}', true);

            if (!is_array($newVals)) $newVals = [];
            if (!is_array($oldVals)) $oldVals = [];

            $changes = [];
            foreach ($newVals as $k => $v) {
                if (in_array($k, ['update_time', 'update_user', 'state_id'])) continue; // state handled by history

                $oldV = $oldVals[$k] ?? null;
                if ($oldV === $v) continue;

                $label = $k;
                $v_old = $oldV;
                $v_new = $v;

                // Handle Arrays (services, tags, areas)
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

                // Handle IDs
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

        // --- MS Teams Card ---
        $card = $this->getAdaptiveCardBase("Incident Closed - Final Summary (#$eventId)", 'good');

        $facts = [
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
        $card['body'][] = ['type' => 'FactSet', 'facts' => array_slice($timelineFacts, -15)]; // Last 15 events

        $this->postCardToTeamsChat($eventId, $card);

        // --- OTRS Article ---
        $body = "<div style='font-family:sans-serif; border:2px solid #198754; border-radius:8px; padding:20px;'>\r\n";
        $body .= "<h2 style='color:#198754; margin-top:0; border-bottom:3px solid #198754; padding-bottom:10px;'>Incident Closure Summary</h2>\r\n";

        $body .= "<p><b>Final Impact Score:</b> " . number_format($event['impactScore']) . "</p>\r\n";

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
            'attention' => 'attention', // Red
            'accent'    => 'accent',    // Blue
            'good'      => 'good',      // Green
            'warning'   => 'warning',   // Yellow
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
                            'color' => ($color === 'default') ? 'default' : 'default'
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
            ['title' => 'Type', 'value' => $event['type_name']],
            ['title' => 'Status', 'value' => $event['state_name']],
            ['title' => 'Department', 'value' => $event['department_name']],
            ['title' => 'Customers', 'value' => number_format($event['customers_affected'])],
            ['title' => 'Impact Score', 'value' => number_format($event['impactScore'])]
        ];

        if (!empty($event['areas']))    $facts[] = ['title' => 'Areas', 'value' => implode(', ', array_column($event['areas'], 'name'))];
        if (!empty($event['services'])) $facts[] = ['title' => 'Services', 'value' => implode(', ', array_column($event['services'], 'name'))];
        if (!empty($event['tags']))     $facts[] = ['title' => 'Tags', 'value' => implode(', ', array_column($event['tags'], 'name'))];
        if ($event['ticket_nr'] && $event['ticket_nr'] !== '0') {
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
                    'text' => $event['description'],
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

    private function logTeams($message, $data = null) {
        $this->log("Teams: $message", $data);
    }

    private function initTeamsChat($eventId, $departmentId, $description) {
        if (!$this->auth) {
            $this->logTeams("Skipping Teams chat: No auth object provided.");
            return;
        }
        $this->auth->requireValidToken();
        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) {
            $this->lastError = "Teams integration error: Failed to get access token.";
            $this->logTeams("Skipping Teams chat: Failed to get access token.");
            return;
        }

        $dept = $this->db->query("SELECT azure_group_id FROM department WHERE id = ?", [$departmentId])->fetch();
        $deptGroupId = $dept['azure_group_id'] ?? null;

        $sso = $this->auth->getSSO();
        $members = [];

        if ($deptGroupId) {
            $memberData = $sso->getGroupMembers($accessToken, $deptGroupId);
            if ($memberData !== null) {
                $members = array_column($memberData, 'id');
            } else {
                $this->logTeams("Warning: Failed to fetch members for department group $deptGroupId");
            }
        }

        // Always include members from global default group if set
        $alwaysIncludeGroupId = $this->getDefault('always_include_azure_group_id');
        if ($alwaysIncludeGroupId && $alwaysIncludeGroupId !== $dept['azure_group_id']) {
            $extraMembers = $sso->getGroupMembers($accessToken, $alwaysIncludeGroupId);
            if ($extraMembers) {
                $extraIds = array_column($extraMembers, 'id');
                $members = array_unique(array_merge($members, $extraIds));
            }
        }

        // Ensure current user is in the chat
        $currentUserOid = $this->auth->user()['azure_oid'] ?? null;
        if ($currentUserOid && !in_array($currentUserOid, $members)) {
            $members[] = $currentUserOid;
        }

        // Teams requires at least 2 members for a group chat
        if (count($members) < 2) {
            $this->logTeams("Skipping Teams chat: Insufficient members (need at least 2).", ['members' => $members]);
            return;
        }

        $topic = "Incident #$eventId - " . substr($description, 0, 50);
        $topic = str_replace([':', '"', "'"], ' ', $topic); // Sanitize colons and quotes

        $chat = $sso->createChat($accessToken, $topic, $members, $currentUserOid);

        if ($chat && isset($chat['id'])) {
            $this->db->query("UPDATE wb_events SET teams_chat_id = ? WHERE id = ?", [$chat['id'], $eventId]);
            $card = $this->formatEventMetadataCard($eventId);
            $sso->sendAdaptiveCardToChat($accessToken, $chat['id'], $card);
            $this->logAudit('wb_events', $eventId, 'TEAMS_CHAT_CREATED', null, ['teams_chat_id' => $chat['id'], 'topic' => $topic]);
        } else {
            $this->lastError = "Teams integration error: Failed to create chat.";
            $this->logAudit('wb_events', $eventId, 'TEAMS_CHAT_FAILED', null, ['reason' => 'API Error or Missing Chat ID']);
        }
    }

    private function syncTeamsChatMembers($eventId, $departmentId) {
        if (!$this->auth) {
            $this->logTeams("Skipping member sync: No auth object.");
            return;
        }
        $this->auth->requireValidToken();
        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) {
            $this->logTeams("Skipping member sync: No access token.");
            return;
        }

        $event = $this->getEvent($eventId);
        if (!$event || !$event['teams_chat_id']) {
            $this->logTeams("Skipping member sync: Event #$eventId has no Teams chat ID.");
            return;
        }

        $dept = $this->db->query("SELECT azure_group_id FROM department WHERE id = ?", [$departmentId])->fetch();
        $deptGroupId = $dept['azure_group_id'] ?? null;

        $sso = $this->auth->getSSO();
        $members = [];

        if ($deptGroupId) {
            $memberData = $sso->getGroupMembers($accessToken, $deptGroupId);
            if ($memberData !== null) {
                $members = array_column($memberData, 'id');
            }
        }

        // Always include members from global default group if set
        $alwaysIncludeGroupId = $this->getDefault('always_include_azure_group_id');
        if ($alwaysIncludeGroupId && $alwaysIncludeGroupId !== $dept['azure_group_id']) {
            $extraMembers = $sso->getGroupMembers($accessToken, $alwaysIncludeGroupId);
            if ($extraMembers) {
                $extraIds = array_column($extraMembers, 'id');
                $members = array_unique(array_merge($members, $extraIds));
            }
        }

        if (empty($members)) {
            $this->logTeams("Skipping member sync: No members found in Azure Group " . $dept['azure_group_id']);
            return;
        }

        $res = $sso->addMembersToChat($accessToken, $event['teams_chat_id'], $members);
        if ($res) {
            $sso->sendMessageToChat($accessToken, $event['teams_chat_id'], "Members from new department added to chat.");
            $this->logAudit('wb_events', $eventId, 'TEAMS_MEMBERS_SYNCED', null, ['count' => count($members)]);
        } else {
            $this->logAudit('wb_events', $eventId, 'TEAMS_MEMBERS_SYNC_FAILED', null, ['count' => count($members)]);
        }
    }

    private function postToTeamsChat($eventId, $message) {
        if (!$this->auth) return;
        $this->auth->requireValidToken();
        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) return;

        $event = $this->db->query("SELECT teams_chat_id FROM wb_events WHERE id = ?", [$eventId])->fetch();
        if (!$event || !$event['teams_chat_id']) return;

        $res = $this->auth->getSSO()->sendMessageToChat($accessToken, $event['teams_chat_id'], $message);
        if (!$res) {
            $this->lastError = "Teams integration error: Failed to post message.";
            $this->logAudit('wb_events', $eventId, 'TEAMS_POST_FAILED', null, ['message' => $message]);
        }
    }

    private function postCardToTeamsChat($eventId, $card) {
        if (!$this->auth) return;
        $this->auth->requireValidToken();
        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) return;

        $event = $this->db->query("SELECT teams_chat_id FROM wb_events WHERE id = ?", [$eventId])->fetch();
        if (!$event || !$event['teams_chat_id']) return;

        $res = $this->auth->getSSO()->sendAdaptiveCardToChat($accessToken, $event['teams_chat_id'], $card);
        if (!$res) {
            $this->lastError = "Teams integration error: Failed to post adaptive card.";
            $this->logAudit('wb_events', $eventId, 'TEAMS_POST_CARD_FAILED', null, ['card' => $card]);
        }
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
            'state_name' => 'Status',
            'type_name' => 'Type',
            'department_name' => 'Department',
            'customers_affected' => 'Customers Affected',
            'ticket_nr' => 'Ticket #'
        ];

        foreach ($fields as $key => $label) {
            if ($old[$key] != $new[$key]) {
                $changes[] = "<tr><th style='text-align:left; border-bottom:1px solid #eee;'>$label</th><td style='border-bottom:1px solid #eee;'><strike style='color:#999;'>" . htmlspecialchars($old[$key]) . "</strike> &rarr; <b>" . htmlspecialchars($new[$key]) . "</b></td></tr>";
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

    private function logOTRS($message, $data = null) {
        $this->log("OTRS: $message", $data);
    }

    private function log($message, $data = null) {
        $logFile = __DIR__ . '/event_manager.log';
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [EventManager] $message";
        if ($data) {
            $entry .= " | Data: " . json_encode($data);
        }
        file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
        error_log($entry);
    }

    private function initOTRSTicket($eventId, $description) {
        if (!$this->otrs || $this->getDefault('otrs_enabled') !== '1') return;

        $otrsUserId = $this->getOTRSUserId();
        $customerUser = $this->getDefault('otrs_customer_user') ?: 'customer@example.com';
        $title = "Incident #$eventId: " . substr($description, 0, 100);

        try {
            $params = [
                'title' => $title,
                'customer' => $customerUser,
                'body' => $description
            ];
            if ($otrsUserId) $params['userID'] = $otrsUserId;

            $res = $this->otrs->CreateTicket($params);
            if ($res && isset($res['ticketid'])) {
                $this->db->query("UPDATE wb_events SET ticket_id = ?, ticket_nr = ? WHERE id = ?", [
                    $res['ticketid'],
                    $res['ticketnr'],
                    $eventId
                ]);
                $this->logAudit('wb_events', $eventId, 'OTRS_TICKET_CREATED', null, $res);

                // Add initial article with FULL details
                $event = $this->getEvent($eventId);
                $body = "<div style='font-family:sans-serif; border:2px solid #dc3545; border-radius:8px; padding:20px;'>\r\n";
                $body .= "<h2 style='color:#dc3545; margin-top:0; border-bottom:3px solid #dc3545; padding-bottom:10px;'>New Incident Reported</h2>\r\n";

                $body .= "<table style='width:100%; border-collapse:collapse;' cellpadding='8'>\r\n";
                $rows = [
                    "Incident ID" => $event['id'],
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
                $body .= "<div style='background:#fcfcfc; border-left:4px solid #ddd; padding:10px; font-style:italic; white-space:pre-wrap;'>" . nl2br(htmlspecialchars($event['description'])) . "</div>\r\n";

                $body .= "<p style='font-size:0.85rem; color:#555; margin-top:25px; border-top:1px solid #ccc; padding-top:15px;'>\r\n";
                $body .= "Reported by: <b>" . htmlspecialchars($this->currentUser) . "</b><br>\r\n";
                $body .= "Timestamp:   " . date('Y-m-d H:i:s') . "\r\n";
                $body .= "</p></div>";

                $this->addOTRSArticle($eventId, "Initial Incident Details", $body);
            } else {
                $this->lastError = "OTRS integration error: Failed to create ticket.";
                $this->logOTRS("Ticket creation failed for Event #$eventId");
            }
        } catch (Exception $e) {
            $this->lastError = "OTRS integration exception: " . $e->getMessage();
            $this->logOTRS("Exception during OTRS ticket creation", ['error' => $e->getMessage()]);
        }
    }

    private function addOTRSArticle($eventId, $subject, $body) {
        if (!$this->otrs || $this->getDefault('otrs_enabled') !== '1') return;

        $event = $this->db->query("SELECT ticket_id FROM wb_events WHERE id = ?", [$eventId])->fetch();
        if (!$event || !$event['ticket_id'] || $event['ticket_id'] === '0') return;

        $otrsUserId = $this->getOTRSUserId();

        try {
            $params = [
                'Ticketid' => (int)$event['ticket_id'],
                'subject' => $subject,
                'body' => $body
            ];
            if ($otrsUserId) $params['userID'] = $otrsUserId;

            $res = $this->otrs->createArticle($params);
            if ($res) {
                $this->logAudit('wb_events', $eventId, 'OTRS_ARTICLE_CREATED', null, ['article_id' => 'API-Article']);
            } else {
                $this->logOTRS("Article creation failed for Ticket #{$event['ticket_id']}");
            }
        } catch (Exception $e) {
            $this->logOTRS("Exception during OTRS article creation", ['error' => $e->getMessage()]);
        }
    }
}
