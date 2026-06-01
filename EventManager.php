<?php

require_once 'Database.php';

class EventManager {
    private $db;
    private $currentUser;

    private $allowedEventFields = [
        'type_id', 'ticket_id', 'ticket_nr', 'department_id', 'customers_affected',
        'services_affected', 'area_affected', 'description', 'state_id',
        'teams_message_Id', 'impactScoreNotified', 'impactScore'
    ];

    public function __construct($currentUser = 'system') {
        $this->db = new Database();
        $this->currentUser = $currentUser;
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

        $fields = array_keys($filteredData);
        $placeholders = array_fill(0, count($fields), '?');

        $sql = "INSERT INTO wb_events (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $this->db->query($sql, array_values($filteredData));

        $eventId = $this->db->lastInsertId();

        // Handle Services (multi-select)
        if (isset($data['service_ids']) && is_array($data['service_ids'])) {
            $this->updateEventServices($eventId, $data['service_ids']);
        }

        // Handle Tags
        if (isset($data['tags']) && !empty($data['tags'])) {
            $tagsArray = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
            $this->updateEventTags($eventId, $tagsArray);
        }

        // Log initial state in history
        if (isset($filteredData['state_id'])) {
            $this->logStateTransition($eventId, $filteredData['state_id']);
        }

        $this->logAudit('wb_events', $eventId, 'CREATE', null, $filteredData);

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

        // Handle Services - allow clearing if explicitly passed or if not closed
        if (!$isClosed && isset($data['service_ids'])) {
            $this->updateEventServices($eventId, (array)$data['service_ids']);
        }

        // Handle Tags - allow clearing if explicitly passed or if not closed
        if (!$isClosed && isset($data['tags'])) {
            $tagsArray = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
            $this->updateEventTags($eventId, $tagsArray);
        }

        $newEvent = $this->getEvent($eventId);
        $this->logAudit('wb_events', $eventId, 'UPDATE', $oldEvent, $newEvent);

        return true;
    }

    public function getEvent($eventId) {
        $stmt = $this->db->query("SELECT * FROM wb_events WHERE id = ?", [$eventId]);
        $event = $stmt->fetch();
        if ($event) {
            $event['services'] = $this->getEventServices($eventId);
            $event['tags'] = $this->getEventTags($eventId);
            $event['state_history'] = $this->getStateHistory($eventId);
        }
        return $event;
    }

    public function listEvents() {
        $events = $this->db->query("SELECT e.*, t.name as type_name, d.name as department_name, s.name as state_name
                                 FROM wb_events e
                                 LEFT JOIN type t ON e.type_id = t.id
                                 LEFT JOIN department d ON e.department_id = d.id
                                 LEFT JOIN state s ON e.state_id = s.id
                                 ORDER BY e.create_time DESC")->fetchAll();
        foreach ($events as &$e) {
            $e['services'] = $this->getEventServices($e['id']);
            $e['tags'] = $this->getEventTags($e['id']);
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

            // Get or create tag
            $tag = $this->db->query("SELECT id FROM tag WHERE name = ?", [$tagName])->fetch();
            if (!$tag) {
                $tagId = $this->createRef('tag', $tagName);
            } else {
                $tagId = $tag['id'];
            }
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

        return $updateId;
    }

    public function getEventUpdates($eventId) {
        $stmt = $this->db->query("SELECT * FROM event_updates WHERE event_id = ? ORDER BY create_time DESC", [$eventId]);
        return $stmt->fetchAll();
    }

    // --- Generic CRUD for reference tables ---

    private function createRef($table, $name) {
        $sql = "INSERT INTO `$table` (name) VALUES (?)";
        $this->db->query($sql, [$name]);
        $id = $this->db->lastInsertId();
        $this->logAudit($table, $id, 'CREATE', null, ['name' => $name]);
        return $id;
    }

    private function updateRef($table, $id, $name) {
        $old = $this->getRef($table, $id);
        $sql = "UPDATE `$table` SET name = ? WHERE id = ?";
        $this->db->query($sql, [$name, $id]);
        $this->logAudit($table, $id, 'UPDATE', $old, ['name' => $name]);
        return true;
    }

    private function deleteRef($table, $id) {
        $old = $this->getRef($table, $id);
        $sql = "DELETE FROM `$table` WHERE id = ?";
        $this->db->query($sql, [$id]);
        $this->logAudit($table, $id, 'DELETE', $old, null);
        return true;
    }

    private function getRef($table, $id) {
        $stmt = $this->db->query("SELECT * FROM `$table` WHERE id = ?", [$id]);
        return $stmt->fetch();
    }

    private function listRef($table) {
        return $this->db->query("SELECT * FROM `$table` ORDER BY name ASC")->fetchAll();
    }

    // Department
    public function createDepartment($name) { return $this->createRef('department', $name); }
    public function updateDepartment($id, $name) { return $this->updateRef('department', $id, $name); }
    public function deleteDepartment($id) { return $this->deleteRef('department', $id); }
    public function getDepartment($id) { return $this->getRef('department', $id); }
    public function listDepartments() { return $this->listRef('department'); }

    // Type
    public function createType($name) { return $this->createRef('type', $name); }
    public function listTypes() { return $this->listRef('type'); }

    // State
    public function createState($name) { return $this->createRef('state', $name); }
    public function listStates() { return $this->listRef('state'); }

    // Service
    public function createService($name) { return $this->createRef('service', $name); }
    public function listServices() { return $this->listRef('service'); }

    // --- Audit Log Retrieval ---

    public function getAuditTrail($tableName, $recordId) {
        $stmt = $this->db->query("SELECT * FROM audit_log WHERE table_name = ? AND record_id = ? ORDER BY timestamp ASC", [$tableName, $recordId]);
        return $stmt->fetchAll();
    }
}
