<?php

require_once 'Database.php';

class EventManager {
    public $db;
    private $currentUser;
    private $auth;

    private $allowedEventFields = [
        'type_id', 'ticket_id', 'ticket_nr', 'department_id', 'customers_affected',
        'description', 'state_id', 'teams_message_Id', 'impactScoreNotified', 'impactScore', 'teams_chat_id'
    ];

    private $outageStates = ['Detected', 'Acknowledged', 'Investigating', 'Identified', 'Mitigating', 'Reopened'];

    public function __construct($currentUser = 'system', $auth = null) {
        $this->db = new Database();
        $this->currentUser = $currentUser;
        $this->auth = $auth;
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
                $changes[] = "**$label:** " . $old[$key] . " -> " . $new[$key];
            }
        }

        // Check Services, Tags, Areas (Arrays)
        $arrayFields = ['services', 'tags', 'areas'];
        foreach ($arrayFields as $key) {
            $oldNames = array_column($old[$key], 'name');
            $newNames = array_column($new[$key], 'name');
            sort($oldNames);
            sort($newNames);
            if ($oldNames !== $newNames) {
                $label = ucfirst($key);
                $changes[] = "**$label:** " . implode(', ', $oldNames) . " -> " . implode(', ', $newNames);
            }
        }

        if (!empty($changes)) {
            $msg = "**Incident Metadata Updated**\n\n";
            $msg .= implode("\n", $changes);
            $this->postToTeamsChat($new['id'], $msg);
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
        $this->postToTeamsChat($eventId, "**NEW UPDATE:** " . $updateText);

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

    // --- Teams Integration ---

    private function formatEventMetadata($eventId) {
        $event = $this->getEvent($eventId);
        if (!$event) return "";

        $msg = "**Incident Details for #" . $event['id'] . "**\n\n";
        $msg .= "--------------------------------\n";
        $msg .= "**Description:** " . $event['description'] . "\n";
        $msg .= "**Status:** " . $event['state_name'] . "\n";
        $msg .= "**Type:** " . $event['type_name'] . "\n";
        $msg .= "**Department:** " . $event['department_name'] . "\n";

        if (!empty($event['areas'])) {
            $areas = array_column($event['areas'], 'name');
            $msg .= "**Affected Areas:** " . implode(', ', $areas) . "\n";
        }

        if (!empty($event['services'])) {
            $svcs = array_column($event['services'], 'name');
            $msg .= "**Impacted Services:** " . implode(', ', $svcs) . "\n";
        }

        if (!empty($event['tags'])) {
            $tags = array_column($event['tags'], 'name');
            $msg .= "**Tags:** " . implode(', ', $tags) . "\n";
        }

        if ($event['ticket_nr'] && $event['ticket_nr'] !== '0') {
            $msg .= "**Ticket:** " . $event['ticket_nr'] . "\n";
        }

        $msg .= "**Impact Score:** " . number_format($event['impactScore']) . " (Customers: " . $event['customers_affected'] . ")\n";

        return $msg;
    }

    private function logTeams($message, $data = null) {
        $logFile = __DIR__ . '/teams_integration.log';
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [EventManager] $message";
        if ($data) {
            $entry .= " | Data: " . json_encode($data);
        }
        file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
        error_log($entry);
    }

    private function initTeamsChat($eventId, $departmentId, $description) {
        if (!$this->auth) {
            $this->logTeams("Skipping Teams chat: No auth object provided.");
            return;
        }
        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) {
            $this->logTeams("Skipping Teams chat: Failed to get access token.");
            return;
        }

        $dept = $this->db->query("SELECT azure_group_id FROM department WHERE id = ?", [$departmentId])->fetch();
        if (!$dept || !$dept['azure_group_id']) {
            $this->logTeams("Skipping Teams chat: Department #$departmentId has no Azure Group ID.");
            return;
        }

        $sso = $this->auth->getSSO();
        $memberData = $sso->getGroupMembers($accessToken, $dept['azure_group_id']);
        $members = array_column($memberData, 'id');

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
            $msg = $this->formatEventMetadata($eventId);
            $sso->sendMessageToChat($accessToken, $chat['id'], $msg);
            $this->logAudit('wb_events', $eventId, 'TEAMS_CHAT_CREATED', null, ['teams_chat_id' => $chat['id'], 'topic' => $topic]);
        } else {
            $this->logAudit('wb_events', $eventId, 'TEAMS_CHAT_FAILED', null, ['reason' => 'API Error or Missing Chat ID']);
        }
    }

    private function syncTeamsChatMembers($eventId, $departmentId) {
        if (!$this->auth) {
            $this->logTeams("Skipping member sync: No auth object.");
            return;
        }
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
        if (!$dept || !$dept['azure_group_id']) {
            $this->logTeams("Skipping member sync: Department #$departmentId has no Azure Group ID.");
            return;
        }

        $sso = $this->auth->getSSO();
        $memberData = $sso->getGroupMembers($accessToken, $dept['azure_group_id']);
        $members = array_column($memberData, 'id');

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
        if (!$this->auth) {
            $this->logTeams("Skipping post: No auth object.");
            return;
        }
        $accessToken = $this->auth->getAccessToken();
        if (!$accessToken) {
            $this->logTeams("Skipping post: No access token.");
            return;
        }

        $event = $this->db->query("SELECT teams_chat_id FROM wb_events WHERE id = ?", [$eventId])->fetch();
        if (!$event || !$event['teams_chat_id']) {
            $this->logTeams("Skipping post: Event #$eventId has no Teams chat ID.");
            return;
        }

        $res = $this->auth->getSSO()->sendMessageToChat($accessToken, $event['teams_chat_id'], $message);
        if (!$res) {
            $this->logAudit('wb_events', $eventId, 'TEAMS_POST_FAILED', null, ['message' => $message]);
        }
    }
}
