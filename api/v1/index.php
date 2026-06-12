<?php
require_once __DIR__ . "/../../autoload.php";
/**
 * REST API for Event Management System
 */
header("Content-Type: application/json");

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..', '/\\');

require_once $docRoot . '/inc/config.php';
// Logic to force SQLite ONLY if MySQL config is missing or invalid
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

// Auth Check (API Key or Session)
$auth = null;
try {
    $auth = new Auth($config);
} catch (PDOException $e) {
    $useSqlite = true;
    putenv('USE_SQLITE=true');
}
if (!isset($_SESSION['user_id'])) {
    // Check for API Key in headers if provided
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    $validKey = $config['api']['key'] ?? 'DEV-KEY-12345';

    if (!$apiKey || $apiKey !== $validKey) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized. Please login or provide a valid X-API-KEY header."]);
        exit;
    }
    $currentUser = 'api_user';
} else {
    $currentUser = $_SESSION['user']['name'] ?? 'session_user';
}

$em = new EventManager($currentUser, $auth);

$method = $_SERVER['REQUEST_METHOD'];
$path = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$resource = $path[0] ?? null;
$id = $path[1] ?? null;

switch ($resource) {
    case 'events':
        handleEvents($em, $method, $id);
        break;
    case 'departments':
        handleRef($em, 'Department', $method, $id);
        break;
    case 'types':
        handleRef($em, 'Type', $method, $id);
        break;
    case 'states':
        handleRef($em, 'State', $method, $id);
        break;
    case 'services':
        handleRef($em, 'Service', $method, $id);
        break;
    case 'netbox':
        handleNetBox($em, $method, $path);
        break;
    default:
        http_response_code(404);
        echo json_encode(["error" => "Resource not found"]);
}

function handleEvents($em, $method, $id) {
    switch ($method) {
        case 'GET':
            if ($id) {
                $event = $em->getEvent($id);
                if ($event) echo json_encode($event);
                else { http_response_code(404); echo json_encode(["error" => "Event not found"]); }
            } else {
                echo json_encode($em->listEvents(isset($_GET['all'])));
            }
            break;
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) { http_response_code(400); echo json_encode(["error" => "Invalid JSON body"]); break; }
            $eventId = $em->createEvent($data);
            http_response_code(201);
            echo json_encode(["id" => $eventId, "message" => "Event created"]);
            break;
        case 'PUT':
        case 'PATCH':
            if (!$id) { http_response_code(400); echo json_encode(["error" => "ID required"]); break; }
            $data = json_decode(file_get_contents('php://input'), true);
            if ($em->updateEvent($id, $data)) echo json_encode(["message" => "Event updated"]);
            else { http_response_code(400); echo json_encode(["error" => "Update failed (event might be closed)"]); }
            break;
        default:
            http_response_code(405);
    }
}

function handleNetBox($em, $method, $path) {
    $action = $path[1] ?? null;
    $id = $path[2] ?? null;

    $configPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..', '/\\') . '/inc/config.php';
    include $configPath;
    $netbox = new NetBoxClient($config);

    if ($action === 'search') {
        $q = $_GET['q'] ?? '';
        echo json_encode($netbox->searchCircuits($q));
    } elseif ($action === 'circuits') {
        $eventId = $_GET['event_id'] ?? null;
        if ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $em->addCircuit($data['event_id'], $data['circuit_id'], $data['circuit_cid'], $data['provider']);
            echo json_encode(["message" => "Circuit added"]);
        } elseif ($method === 'DELETE') {
            $circuitId = $_GET['circuit_id'];
            $em->removeCircuit($eventId, $circuitId);
            echo json_encode(["message" => "Circuit removed"]);
        }
    }
}

function handleRef($em, $type, $method, $id) {
    $listMethod = "list" . $type . "s";
    $createMethod = "create" . $type;
    $updateMethod = "update" . $type;
    $deleteMethod = "delete" . $type;

    switch ($method) {
        case 'GET':
            echo json_encode($em->$listMethod());
            break;
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            $newId = $em->$createMethod($data['name'], $data['azure_group_id'] ?? null);
            http_response_code(201);
            echo json_encode(["id" => $newId, "message" => "$type created"]);
            break;
        case 'PUT':
            if (!$id) { http_response_code(400); break; }
            $data = json_decode(file_get_contents('php://input'), true);
            if ($type === 'Department') {
                $em->updateDepartment($id, $data);
            } else {
                $em->$updateMethod($id, $data['name']);
            }
            echo json_encode(["message" => "$type updated"]);
            break;
        case 'DELETE':
            if (!$id) { http_response_code(400); break; }
            $em->$deleteMethod($id);
            echo json_encode(["message" => "$type deleted"]);
            break;
        default:
            http_response_code(405);
    }
}
