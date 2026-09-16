<?php
// REST API View / Endpoint Handler for Incident Management Plugin

header("Content-Type: application/json");

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'api_user');
$em = new EventManager($currentUser);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action === 'netbox_search') {
    $q = $_GET['q'] ?? '';
    $nbUrl = $em->getDefault('netbox_url');
    $nbToken = $em->getDefault('netbox_token');
    if (empty($nbUrl) || empty($nbToken)) {
        echo json_encode([]);
        exit;
    }
    $netbox = new NetBoxClient([
        'api' => [
            'netbox' => [
                'url' => $nbUrl,
                'token' => $nbToken
            ]
        ]
    ]);
    echo json_encode($netbox->searchCircuits($q));
    exit;
} elseif ($action === 'add_circuit') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!empty($data['event_id']) && !empty($data['circuit_id'])) {
        $em->addCircuit($data['event_id'], $data['circuit_id'], $data['circuit_cid'] ?? '', $data['provider'] ?? '');
        echo json_encode(["status" => "success", "message" => "Circuit added"]);
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid parameters"]);
    }
    exit;
} elseif ($action === 'remove_circuit') {
    $eventId = $_GET['event_id'] ?? null;
    $circuitId = $_GET['circuit_id'] ?? null;
    if ($eventId && $circuitId) {
        $em->removeCircuit($eventId, $circuitId);
        echo json_encode(["status" => "success", "message" => "Circuit removed"]);
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid parameters"]);
    }
    exit;
} elseif ($action === 'list_events') {
    $includeClosed = isset($_GET['all']) && $_GET['all'] == '1';
    echo json_encode($em->listEvents($includeClosed));
    exit;
} elseif ($action === 'get_event') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $event = $em->getEvent($id);
        if ($event) {
            echo json_encode($event);
        } else {
            http_response_code(404);
            echo json_encode(["error" => "Event not found"]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Event ID required"]);
    }
    exit;
}

// Default fallback API response
echo json_encode([
    "plugin" => "incident-management",
    "version" => "1.0.0",
    "status" => "online",
    "available_actions" => ["netbox_search", "add_circuit", "remove_circuit", "list_events", "get_event"]
]);
exit;
