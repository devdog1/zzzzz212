<?php

require_once 'Database.php';

class EventManager {
    private $db;
    private $currentUser;

    private $allowedEventFields = [
        'type_id', 'ticket_id', 'ticket_nr', 'department_id', 'customers_affected',
        'services_affected', 'area_affected', 'description', 'state_id',
        'parent_service', 'teams_message_Id', 'impactScoreNotified', 'impactScore'
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
        $this->logAudit('wb_events', $eventId, 'CREATE', null, $filteredData);

        return $eventId;
    }

    public function updateEvent($eventId, $data) {
        $oldEvent = $this->getEvent($eventId);
        if (!$oldEvent) return false;

        $filteredData = array_intersect_key($data, array_flip($this->allowedEventFields));
        $filteredData['update_user'] = $this->currentUser;

        if (empty($filteredData)) return false;

        $fields = [];
        $values = [];
        foreach ($filteredData as $key => $value) {
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        $values[] = $eventId;

        $sql = "UPDATE wb_events SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->query($sql, $values);

        $newEvent = $this->getEvent($eventId);
        $this->logAudit('wb_events', $eventId, 'UPDATE', $oldEvent, $newEvent);

        return true;
    }

    public function getEvent($eventId) {
        $stmt = $this->db->query("SELECT * FROM wb_events WHERE id = ?", [$eventId]);
        return $stmt->fetch();
    }

    public function listEvents() {
        return $this->db->query("SELECT e.*, t.name as type_name, d.name as department_name, s.name as state_name
                                 FROM wb_events e
                                 LEFT JOIN type t ON e.type_id = t.id
                                 LEFT JOIN department d ON e.department_id = d.id
                                 LEFT JOIN state s ON e.state_id = s.id
                                 ORDER BY e.create_time DESC")->fetchAll();
    }

    // --- Event Updates ---

    public function addEventUpdate($eventId, $updateText) {
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

    // --- Generic CRUD for reference tables (department, type, state) ---

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
    public function updateType($id, $name) { return $this->updateRef('type', $id, $name); }
    public function deleteType($id) { return $this->deleteRef('type', $id); }
    public function getType($id) { return $this->getRef('type', $id); }
    public function listTypes() { return $this->listRef('type'); }

    // State
    public function createState($name) { return $this->createRef('state', $name); }
    public function updateState($id, $name) { return $this->updateRef('state', $id, $name); }
    public function deleteState($id) { return $this->deleteRef('state', $id); }
    public function getState($id) { return $this->getRef('state', $id); }
    public function listStates() { return $this->listRef('state'); }

    // --- Audit Log Retrieval ---

    public function getAuditTrail($tableName, $recordId) {
        $stmt = $this->db->query("SELECT * FROM audit_log WHERE table_name = ? AND record_id = ? ORDER BY timestamp ASC", [$tableName, $recordId]);
        return $stmt->fetchAll();
    }
}
