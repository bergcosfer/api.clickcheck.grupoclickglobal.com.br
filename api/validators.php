<?php
/**
 * Clickcheck - API de Validadores/Usuários para Metas
 * Lista TODOS os usuários para uso no modal de metas
 */

require_once __DIR__ . '/../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

function requireAuth() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $payload = validateToken($token);
    
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
    return $payload;
}

// Verificar autenticação
requireAuth();

$db = getDB();

// Buscar TODOS os usuários ativos (para ser usado no modal de metas)
$stmt = $db->query("
    SELECT id, email, full_name, nickname, profile_picture, profile, admin_level, manager_id
    FROM users 
    ORDER BY full_name, email
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($users);
