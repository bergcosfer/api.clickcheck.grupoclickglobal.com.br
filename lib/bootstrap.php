<?php
/**
 * Clickcheck - Helpers compartilhados de HTTP/auth (mesmo padrão dos endpoints existentes).
 * Requer config/database.php já carregado (fornece getDB() e getJsonPayload()).
 */

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

function sendJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($message, $code = 400) {
    sendJson(['error' => $message], $code);
}

// NOTE: validação de assinatura fica para a etapa de segurança (unificação).
// Hoje os endpoints usam esta versão (sem checagem de assinatura) por consistência.
function validateToken($token) {
    if (!$token) return null;
    $decoded = base64_decode($token);
    if (!$decoded) return null;
    $parts = explode('.', $decoded);
    if (count($parts) !== 2) return null;
    $payload = json_decode($parts[0], true);
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;
    return $payload;
}

function getCurrentUser() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!$authHeader) {
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') { $authHeader = $v; break; }
        }
    }
    $token = str_replace('Bearer ', '', $authHeader);
    if (!$token) return null;
    $payload = validateToken($token);
    if (!$payload) return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$payload['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

function requireAuth($levels = null) {
    $user = getCurrentUser();
    if (!$user) sendError('Sessão expirada. Faça login novamente.', 401);
    $userLevel = $user['admin_level'] ?? 'user';
    if ($levels && !in_array($userLevel, (array)$levels)) sendError('Sem permissão para esta ação', 403);
    return $user;
}

function addHistory($requestId, $action, $details, $userEmail) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT history FROM validation_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $history = json_decode($row['history'] ?? '[]', true);
        $history[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'details' => $details,
            'user' => $userEmail,
        ];
        $stmt = $db->prepare("UPDATE validation_requests SET history = ? WHERE id = ?");
        $stmt->execute([json_encode($history), $requestId]);
    } catch (Exception $e) {
        // histórico falha silenciosamente para não quebrar o fluxo principal
    }
}
