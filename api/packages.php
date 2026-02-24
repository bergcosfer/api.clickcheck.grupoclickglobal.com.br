<?php
/**
 * Clickcheck - API de Pacotes
 */

require_once __DIR__ . '/../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function validateToken($token) {
    if (!$token) return null;
    $decoded = base64_decode($token);
    $parts = explode('.', $decoded);
    if (count($parts) !== 2) return null;
    $payload = json_decode($parts[0], true);
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;
    return $payload;
}

function getCurrentUser() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $payload = validateToken($token);
    if (!$payload) return null;
    
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$payload['user_id']]);
    return $stmt->fetch();
}

function requireAuth($levels = null) {
    $user = getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
    if ($levels && !in_array($user['admin_level'], (array)$levels)) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão']);
        exit;
    }
    return $user;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;
$activeOnly = isset($_GET['active']);

switch ($method) {
    case 'GET':
        if ($id) {
            getPackage($id);
        } else {
            listPackages($activeOnly);
        }
        break;
    case 'POST':
        createPackage();
        break;
    case 'PUT':
        updatePackage($id);
        break;
    case 'DELETE':
        deletePackage($id);
        break;
    default:
        echo json_encode(['error' => 'Método não suportado']);
}

function listPackages($activeOnly) {
    requireAuth();
    $db = getDB();
    $sql = "SELECT * FROM validation_packages";
    if ($activeOnly) {
        $sql .= " WHERE active = 1";
    }
    $sql .= " ORDER BY name";
    $stmt = $db->query($sql);
    $packages = $stmt->fetchAll();
    foreach ($packages as &$pkg) {
        $pkg['criteria'] = json_decode($pkg['criteria'], true) ?? [];
        $pkg['active'] = (bool)$pkg['active'];
    }
    echo json_encode($packages);
}

function getPackage($id) {
    requireAuth();
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM validation_packages WHERE id = ?");
    $stmt->execute([$id]);
    $pkg = $stmt->fetch();
    if (!$pkg) {
        http_response_code(404);
        echo json_encode(['error' => 'Pacote não encontrado']);
        return;
    }
    $pkg['criteria'] = json_decode($pkg['criteria'], true) ?? [];
    $pkg['active'] = (bool)$pkg['active'];
    echo json_encode($pkg);
}

function createPackage() {
    $user = requireAuth('admin_principal');
    $data = getJsonPayload();
    
    if (empty($data['name']) || empty($data['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome e tipo são obrigatórios']);
        return;
    }
    
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO validation_packages (name, description, type, criteria, active, created_by_email) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['name'],
        $data['description'] ?? '',
        $data['type'],
        json_encode($data['criteria'] ?? []),
        $data['active'] ?? true,
        $user['email']
    ]);
    
    $id = $db->lastInsertId();
    getPackage($id);
}

function updatePackage($id) {
    requireAuth('admin_principal');
    $data = getJsonPayload();
    
    $fields = [];
    $values = [];
    
    foreach (['name', 'description', 'type', 'active'] as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $values[] = $data[$field];
        }
    }
    if (isset($data['criteria'])) {
        $fields[] = "criteria = ?";
        $values[] = json_encode($data['criteria']);
    }
    
    if (empty($fields)) {
        echo json_encode(['error' => 'Nenhum campo para atualizar']);
        return;
    }
    
    $values[] = $id;
    $db = getDB();
    $stmt = $db->prepare("UPDATE validation_packages SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($values);
    
    getPackage($id);
}

function deletePackage($id) {
    requireAuth('admin_principal');
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM validation_packages WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
}
