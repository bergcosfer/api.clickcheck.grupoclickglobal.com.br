<?php
/**
 * Clickcheck - API de Convites
 */

require_once __DIR__ . '/../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

function requireAdmin() {
    $user = getCurrentUser();
    if (!$user || $user['admin_level'] !== 'admin_principal') {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas admins podem gerenciar convites']);
        exit;
    }
    return $user;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        listInvites();
        break;
    case 'POST':
        if ($action === 'verify') {
            verifyInvite();
        } else {
            createInvite();
        }
        break;
    case 'DELETE':
        deleteInvite();
        break;
    default:
        echo json_encode(['error' => 'Método não suportado']);
}

function listInvites() {
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("SELECT id, email, admin_level, invited_by, used_at, expires_at, created_at, 
                        CASE WHEN used_at IS NOT NULL THEN 'usado' 
                             WHEN expires_at < NOW() THEN 'expirado' 
                             ELSE 'pendente' END as status
                        FROM invites ORDER BY created_at DESC");
    echo json_encode($stmt->fetchAll());
}

function createInvite() {
    $admin = requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email é obrigatório']);
        return;
    }
    
    $email = strtolower(trim($data['email']));
    $adminLevel = $data['admin_level'] ?? 'user';
    $expiresIn = $data['expires_in'] ?? 7; // dias
    
    // Verificar se email já foi convidado
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM invites WHERE email = ? AND used_at IS NULL AND expires_at > NOW()");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Email já possui um convite ativo']);
        return;
    }
    
    // Verificar se usuário já existe
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Usuário já cadastrado no sistema']);
        return;
    }
    
    // Gerar token único
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiresIn days"));
    
    $stmt = $db->prepare("INSERT INTO invites (email, token, admin_level, invited_by, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$email, $token, $adminLevel, $admin['email'], $expiresAt]);
    
    $inviteUrl = "https://clickcheck-grupoclickglobal-com-facmok9as.vercel.app/invite?token=$token";
    
    echo json_encode([
        'success' => true,
        'email' => $email,
        'token' => $token,
        'invite_url' => $inviteUrl,
        'expires_at' => $expiresAt,
        'message' => "Convite criado! Envie o link para $email"
    ]);
}

function verifyInvite() {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['token'] ?? '';
    
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token é obrigatório']);
        return;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM invites WHERE token = ?");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();
    
    if (!$invite) {
        http_response_code(404);
        echo json_encode(['valid' => false, 'error' => 'Convite não encontrado']);
        return;
    }
    
    if ($invite['used_at']) {
        echo json_encode(['valid' => false, 'error' => 'Convite já foi utilizado']);
        return;
    }
    
    if (strtotime($invite['expires_at']) < time()) {
        echo json_encode(['valid' => false, 'error' => 'Convite expirado']);
        return;
    }
    
    echo json_encode([
        'valid' => true,
        'email' => $invite['email'],
        'admin_level' => $invite['admin_level'],
        'expires_at' => $invite['expires_at']
    ]);
}

function deleteInvite() {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID é obrigatório']);
        return;
    }
    
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM invites WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
}
