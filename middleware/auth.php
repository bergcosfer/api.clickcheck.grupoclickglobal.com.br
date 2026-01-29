<?php
/**
 * Clickcheck - Middleware de Autenticação com Token
 */

session_start();

require_once __DIR__ . '/../config/database.php';

function enableCORS() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=UTF-8');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function validateToken($token) {
    if (!$token) return null;
    
    $decoded = base64_decode($token);
    $parts = explode('.', $decoded);
    if (count($parts) !== 2) return null;
    
    $payload = json_decode($parts[0], true);
    $signature = $parts[1];
    
    $expectedSignature = hash('sha256', $parts[0] . 'clickcheck_secret_key_2024');
    if ($signature !== $expectedSignature) return null;
    
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;
    
    return $payload;
}

function getTokenFromHeader() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    return str_replace('Bearer ', '', $authHeader);
}

function isAuthenticated() {
    $token = getTokenFromHeader();
    return validateToken($token) !== null;
}

function getCurrentUser() {
    $token = getTokenFromHeader();
    $payload = validateToken($token);
    
    if (!$payload) return null;
    
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$payload['user_id']]);
    return $stmt->fetch();
}

function hasPermission($requiredLevels) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    $levels = is_array($requiredLevels) ? $requiredLevels : [$requiredLevels];
    return in_array($user['admin_level'], $levels);
}

function requireAuth($requiredLevels = null) {
    if (!isAuthenticated()) {
        jsonResponse(['error' => 'Não autenticado'], 401);
    }
    
    if ($requiredLevels !== null && !hasPermission($requiredLevels)) {
        jsonResponse(['error' => 'Acesso negado'], 403);
    }
}
