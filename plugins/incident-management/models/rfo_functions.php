<?php
// rfo_functions.php - RFO / Incident Report Details & Timeline Management for Incident Management Plugin

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}

if (file_exists(__DIR__ . '/../../../PluginDatabase.php')) {
    require_once __DIR__ . '/../../../PluginDatabase.php';
} elseif (file_exists(__DIR__ . '/../../PluginDatabase.php')) {
    require_once __DIR__ . '/../../PluginDatabase.php';
} elseif (file_exists(__DIR__ . '/../../../zzz5-repo/PluginDatabase.php')) {
    require_once __DIR__ . '/../../../zzz5-repo/PluginDatabase.php';
}

function incident_get_pdb() {
    static $pdb = null;
    if ($pdb === null) {
        $pdb = new PluginDatabase('incident-management');
    }
    return $pdb;
}

/**
 * Programmatically create an RFO / Incident Report document and save its details.
 * Compatible with doc_manager hook calls and direct invocation.
 *
 * @param array $data Contains title, incident_id, incident_number, service_affected, systems_affected, impact_description, timeline_entries, etc.
 * @return int Created RFO document ID
 */
function doc_create_rfo_report($data) {
    return incident_create_rfo_report($data);
}

function incident_create_rfo_report($data) {
    $pdb = incident_get_pdb();
    $tbDetails = $pdb->getTableName('rfo_details');

    $doc_id = (int)($data['document_id'] ?? ($data['incident_id'] ?? 0));
    if ($doc_id <= 0) {
        $stmt = $pdb->query("SELECT MAX(document_id) FROM {$tbDetails}");
        $maxDocId = (int)$stmt->fetchColumn();
        $doc_id = max(1000, $maxDocId + 1);
    }

    if (empty($data['incident_number']) && !empty($data['incident_id'])) {
        $data['incident_number'] = "INC-" . sprintf('%06d', $data['incident_id']);
    } elseif (empty($data['incident_number'])) {
        $data['incident_number'] = "RFO-" . date('Y') . "-" . sprintf('%06d', $doc_id);
    }

    if (empty($data['service_affected']) && !empty($data['services'])) {
        $data['service_affected'] = is_array($data['services']) ? implode(', ', $data['services']) : $data['services'];
    }

    if (empty($data['geographic_areas_affected']) && !empty($data['areas'])) {
        $data['geographic_areas_affected'] = is_array($data['areas']) ? implode(', ', $data['areas']) : $data['areas'];
    }

    if (empty($data['impact_description']) && !empty($data['description'])) {
        $data['impact_description'] = $data['description'];
    }

    if (empty($data['start_datetime']) && !empty($data['start_time'])) {
        $data['start_datetime'] = $data['start_time'];
    }

    if (empty($data['service_restoration_datetime']) && !empty($data['end_time'])) {
        $data['service_restoration_datetime'] = $data['end_time'];
    }

    if (empty($data['related_tickets']) && !empty($data['ticket_nr'])) {
        $data['related_tickets'] = $data['ticket_nr'];
    }

    incident_save_rfo_details($doc_id, $data);

    // Support single initial timeline entry string
    if (!empty($data['initial_event_timeline'])) {
        incident_add_rfo_timeline_entry(
            $doc_id,
            date('Y-m-d H:i:s'),
            $data['initial_event_timeline'],
            $data['person'] ?? ($data['author'] ?? 'System'),
            $data['source'] ?? 'Incident System',
            $data['notes'] ?? 'Auto-generated event timeline from incident creation trigger'
        );
    }

    // Support array of timeline entries in creation payload
    $timeline_entries = $data['timeline_entries'] ?? ($data['timelines'] ?? []);
    if (is_array($timeline_entries)) {
        foreach ($timeline_entries as $entry) {
            if (is_array($entry) && (!empty($entry['event']) || !empty($entry['update_text']))) {
                incident_add_rfo_timeline_entry(
                    $doc_id,
                    $entry['timestamp'] ?? date('Y-m-d H:i:s'),
                    $entry['event'] ?? ($entry['update_text'] ?? 'Timeline Update'),
                    $entry['person'] ?? ($entry['user'] ?? ($data['author'] ?? 'System')),
                    $entry['source'] ?? ($entry['type'] ?? 'Incident System'),
                    $entry['notes'] ?? ''
                );
            }
        }
    }

    return $doc_id;
}

function doc_save_rfo_details($doc_id, $data) {
    return incident_save_rfo_details($doc_id, $data);
}

function incident_save_rfo_details($doc_id, $data) {
    $pdb = incident_get_pdb();
    $tb = $pdb->getTableName('rfo_details');

    $stmt = $pdb->query("SELECT id FROM {$tb} WHERE document_id = ?", [(int)$doc_id]);
    $exists = $stmt->fetchColumn();

    $fields = [
        'incident_number', 'service_affected', 'systems_affected', 'customers_affected',
        'geographic_areas_affected', 'incident_severity', 'start_datetime', 'detection_datetime',
        'escalation_datetime', 'service_restoration_datetime', 'total_duration', 'impact_description',
        'initial_symptoms', 'detection_method', 'root_cause', 'contributing_factors', 'resolution',
        'recovery_actions', 'corrective_actions', 'preventative_actions', 'lessons_learned',
        'monitoring_improvements', 'assigned_owner_id', 'reviewer_id', 'approver_id',
        'related_tickets', 'related_changes', 'related_rfos'
    ];

    $params = [];
    foreach ($fields as $f) {
        $val = $data[$f] ?? null;
        if (is_string($val) && trim($val) === '') {
            $val = null;
        }
        $params[$f] = $val;
    }

    if ($exists) {
        $set_sql = implode(', ', array_map(fn($f) => "`{$f}` = ?", $fields));
        $sql_params = array_values($params);
        $sql_params[] = (int)$doc_id;
        $pdb->query("UPDATE {$tb} SET {$set_sql} WHERE document_id = ?", $sql_params);
    } else {
        $cols_sql = '`document_id`, ' . implode(', ', array_map(fn($f) => "`{$f}`", $fields));
        $placeholders = '?, ' . implode(', ', array_fill(0, count($fields), '?'));
        $sql_params = array_merge([(int)$doc_id], array_values($params));
        $pdb->query("INSERT INTO {$tb} ({$cols_sql}) VALUES ({$placeholders})", $sql_params);
    }
}

function doc_get_rfo_details($doc_id) {
    return incident_get_rfo_details($doc_id);
}

function incident_get_rfo_details($doc_id) {
    $pdb = incident_get_pdb();
    $tb = $pdb->getTableName('rfo_details');
    $stmt = $pdb->query("SELECT * FROM {$tb} WHERE document_id = ?", [(int)$doc_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

function doc_add_rfo_timeline_entry($doc_id, $timestamp, $event, $person = '', $source = '', $notes = '') {
    return incident_add_rfo_timeline_entry($doc_id, $timestamp, $event, $person, $source, $notes);
}

function incident_add_rfo_timeline_entry($doc_id, $timestamp, $event, $person = '', $source = '', $notes = '') {
    $pdb = incident_get_pdb();
    $tb = $pdb->getTableName('rfo_timelines');
    $pdb->query("
        INSERT INTO {$tb} (document_id, timestamp, event, person, source, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ", [(int)$doc_id, $timestamp, $event, $person, $source, $notes]);
}

function doc_get_rfo_timelines($doc_id) {
    return incident_get_rfo_timelines($doc_id);
}

function incident_get_rfo_timelines($doc_id) {
    $pdb = incident_get_pdb();
    $tb = $pdb->getTableName('rfo_timelines');
    return $pdb->query("SELECT * FROM {$tb} WHERE document_id = ? ORDER BY timestamp ASC", [(int)$doc_id])->fetchAll(PDO::FETCH_ASSOC);
}

function doc_search_rfos($criteria = []) {
    return incident_search_rfos($criteria);
}

function incident_search_rfos($criteria = []) {
    $pdb = incident_get_pdb();
    $tb = $pdb->getTableName('rfo_details');
    $sql = "SELECT r.* FROM {$tb} r WHERE 1=1";
    $params = [];

    if (!empty($criteria['query'])) {
        $q = '%' . $criteria['query'] . '%';
        $sql .= " AND (r.incident_number LIKE ? OR r.service_affected LIKE ? OR r.impact_description LIKE ? OR r.root_cause LIKE ?)";
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }

    if (!empty($criteria['severity'])) {
        $sql .= " AND r.incident_severity = ?";
        $params[] = $criteria['severity'];
    }

    $sql .= " ORDER BY r.id DESC";
    return $pdb->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

function doc_delete_rfo($doc_id) {
    return incident_delete_rfo($doc_id);
}

function incident_delete_rfo($doc_id) {
    $pdb = incident_get_pdb();
    $tbDetails = $pdb->getTableName('rfo_details');
    $tbTimelines = $pdb->getTableName('rfo_timelines');

    $pdb->query("DELETE FROM {$tbTimelines} WHERE document_id = ?", [(int)$doc_id]);
    $pdb->query("DELETE FROM {$tbDetails} WHERE document_id = ?", [(int)$doc_id]);
}

/* =========================================================
 * INTER-PLUGIN HOOK HANDLERS
 * ========================================================= */

function doc_handle_create_rfo_hook($data) {
    return incident_create_rfo_report($data);
}

function doc_handle_add_rfo_timeline_hook($data) {
    $doc_id = (int)($data['document_id'] ?? ($data['incident_id'] ?? 0));
    if (!$doc_id && !empty($data['incident_number'])) {
        $pdb = incident_get_pdb();
        $tb = $pdb->getTableName('rfo_details');
        $stmt = $pdb->query("SELECT document_id FROM {$tb} WHERE incident_number = ?", [$data['incident_number']]);
        $doc_id = (int)$stmt->fetchColumn();
    }

    if ($doc_id && (!empty($data['event']) || !empty($data['update_text']))) {
        incident_add_rfo_timeline_entry(
            $doc_id,
            $data['timestamp'] ?? date('Y-m-d H:i:s'),
            $data['event'] ?? ($data['update_text'] ?? 'Update'),
            $data['person'] ?? ($data['user'] ?? 'System'),
            $data['source'] ?? ($data['type'] ?? 'External API'),
            $data['notes'] ?? ''
        );
        return true;
    }
    return false;
}
