<?php
require_once __DIR__ . "/autoload.php";
echo "Demonstration of EventManager Class\n";
echo "===================================\n";

$em = new EventManager('admin_user');

// 1. Create a Department
$deptId = $em->createDepartment('IT Support');
echo "Created Department ID: $deptId\n";

// 2. Create an Event Type
$typeId = $em->createType('Outage');
echo "Created Type ID: $typeId\n";

// 3. Create a State
$stateId = $em->createState('Active');
echo "Created State ID: $stateId\n";

// 4. Create an Event
$eventId = $em->createEvent([
    'type_id' => $typeId,
    'department_id' => $deptId,
    'ticket_id' => 'EXT-12345',
    'ticket_nr' => '98765',
    'description' => 'Main server is down',
    'state_id' => $stateId
]);
echo "Created Event ID: $eventId\n";

// 5. Add an Update
$em->addEventUpdate($eventId, 'Investigating the power supply.');
echo "Added update to event.\n";

// 6. Update Event Field
$em->updateEvent($eventId, [
    'impactScore' => 5
]);
echo "Updated event impact score.\n";

// 7. View Audit Trail
$auditTrail = $em->getAuditTrail('wb_events', $eventId);
echo "\nAudit Trail for Event $eventId:\n";
foreach ($auditTrail as $entry) {
    echo "{$entry['timestamp']} - {$entry['action']} by {$entry['user']}\n";
    if ($entry['action'] == 'UPDATE') {
        echo "  Changes: " . $entry['new_values'] . "\n";
    }
}

// 8. View Updates
$updates = $em->getEventUpdates($eventId);
echo "\nIncident Updates:\n";
foreach ($updates as $update) {
    echo "{$update['create_time']} - {$update['update_text']} (by {$update['create_user']})\n";
}
