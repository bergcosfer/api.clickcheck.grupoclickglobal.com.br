<?php
/**
 * Clickcheck - API de Usuários com Perfis
 */

require_once __DIR__ . '/../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Perfis de permissão
$PROFILES = [
    'validador' => [
        'view_dashboard' => true,
        'create_validation' => false,
        'view_assigned' => true,
        'view_all_validations' => false,
        'validate' => true,
        'view_ranking' => true,
        'view_reports' => false,
        'manage_packages' => false,
        'manage_users' => false,
        'edit_validation' => false,
        'delete_validation' => false,
        'view_wiki' => true,
    ],
    'solicitante' => [
        'view_dashboard' => true,
        'create_validation' => true,
        'view_assigned' => false,
        'view_all_validations' => true,
        'validate' => false,
        'view_ranking' => true,
        'view_reports' => false,
        'manage_packages' => false,
        'manage_users' => false,
        'edit_validation' => false,
        'delete_validation' => false,
        'view_wiki' => true,
    ],
    'gerente' => [
        'view_dashboard' => true,
        'create_validation' => true,
        'view_assigned' => true,
        'view_all_validations' => true,
        'validate' => true,
        'view_ranking' => true,
        'view_reports' => true,
        'manage_packages' => true,
        'manage_users' => false,
        'edit_validation' => true,
        'delete_validation' => true,
        'view_wiki' => true,
    ],
    'admin' => [
        'view_dashboard' => true,
        'create_validation' => true,
        'view_assigned' => true,
        'view_all_validations' => true,
        'validate' => true,
        'view_ranking' => true,
        'view_reports' => true,
        'manage_packages' => true,
        'manage_users' => true,
        'edit_validation' => true,
        'delete_validation' => true,
        'view_wiki' => true,
    ],
];

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

function requireAdmin() {
    $user = getCurrentUser();
    if (!$user || $user['admin_level'] !== 'admin_principal') {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão']);
        exit;
    }
    return $user;
}

function getUserPermissions($user) {
    global $PROFILES;
    
    if ($user['admin_level'] === 'admin_principal') {
        return $PROFILES['admin'];
    }
    
    // Se tem permissões customizadas, usa elas
    if (!empty($user['permissions'])) {
        $perms = json_decode($user['permissions'], true);
        if ($perms && is_array($perms)) {
            return $perms;
        }
    }
    
    // Se tem perfil, usa as permissões do perfil
    $profile = $user['profile'] ?? 'validador';
    return $PROFILES[$profile] ?? $PROFILES['validador'];
}

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

switch ($method) {
    case 'GET':
        listUsers();
        break;
    case 'POST':
        createUser();
        break;
    case 'PUT':
        updateUser($id);
        break;
    case 'DELETE':
        deleteUser($id);
        break;
    default:
        echo json_encode(['error' => 'Método não suportado']);
}

function listUsers() {
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("SELECT id, email, full_name, nickname, profile_picture, admin_level, profile, permissions, google_id, manager_id, created_at FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
    
    foreach ($users as &$user) {
        $user['permissions'] = getUserPermissions($user);
        $user['profile'] = $user['profile'] ?? 'validador';
    }
    
    echo json_encode($users);
}

function createUser() {
    global $PROFILES;
    requireAdmin();
    $data = getJsonPayload();
    
    if (empty($data['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email é obrigatório']);
        return;
    }
    
    $email = strtolower(trim($data['email']));
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Usuário já existe com este email']);
        return;
    }
    
    $profile = $data['profile'] ?? 'validador';
    $permissions = $data['permissions'] ?? $PROFILES[$profile] ?? $PROFILES['validador'];
    
    $stmt = $db->prepare("INSERT INTO users (email, full_name, admin_level, profile, permissions) VALUES (?, ?, 'user', ?, ?)");
    $stmt->execute([
        $email,
        $data['full_name'] ?? '',
        $profile,
        json_encode($permissions)
    ]);
    
    $id = $db->lastInsertId();
    
    $stmt = $db->prepare("SELECT id, email, full_name, nickname, admin_level, profile, permissions, created_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    $user['permissions'] = getUserPermissions($user);
    echo json_encode($user);
}

function updateUser($id) {
    global $PROFILES;
    requireAdmin();
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID é obrigatório']);
        return;
    }
    
    $data = getJsonPayload();
    $fields = [];
    $values = [];
    
    foreach (['nickname', 'full_name'] as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $values[] = $data[$field];
        }
    }
    
    // Manager (equipe)
    if (array_key_exists('manager_id', $data)) {
        $fields[] = "manager_id = ?";
        $values[] = $data['manager_id'] ?: null;
    }
    
    // Perfil
    if (isset($data['profile'])) {
        $fields[] = "profile = ?";
        $values[] = $data['profile'];
    }
    
    // Permissões
    if (isset($data['permissions'])) {
        $fields[] = "permissions = ?";
        $values[] = json_encode($data['permissions']);
    }
    
    if (empty($fields)) {
        echo json_encode(['error' => 'Nenhum campo para atualizar']);
        return;
    }
    
    $values[] = $id;
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($values);
    
    echo json_encode(['success' => true]);
}

function deleteUser($id) {
    $admin = requireAdmin();
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID é obrigatório']);
        return;
    }
    
    if ($id == $admin['id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Você não pode excluir sua própria conta']);
        return;
    }
    
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
}
