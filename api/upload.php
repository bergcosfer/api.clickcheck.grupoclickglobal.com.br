<?php
/**
 * Clickcheck - API de Upload
 */

require_once __DIR__ . '/../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Método não suportado']);
    exit;
}

requireAuth();

if (!isset($_FILES['file'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Nenhum arquivo enviado']);
    exit;
}

$file = $_FILES['file'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxSize = 5 * 1024 * 1024; // 5MB

if (!in_array($file['type'], $allowedTypes)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tipo de arquivo não permitido']);
    exit;
}

if ($file['size'] > $maxSize) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Arquivo muito grande (máximo 5MB)']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '_' . time() . '.' . $ext;
$filepath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erro ao salvar arquivo']);
    exit;
}

$url = 'https://clickcheck.grupoclickglobal.com.br/uploads/' . $filename;

header('Content-Type: application/json');
echo json_encode(['url' => $url, 'filename' => $filename]);
