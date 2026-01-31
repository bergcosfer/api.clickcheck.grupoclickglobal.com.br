<?php
/**
 * Clickcheck - API de Solicitações de Validação (Hardened)
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Fallback para getallheaders em Nginx/FPM
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
    echo json_encode($data);
    exit;
}

function sendError($message, $code = 400) {
    sendJson(['error' => $message], $code);
}

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
    
    // Suporte para lowercase e cases variados
    if (!$authHeader) {
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $authHeader = $v;
                break;
            }
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
            'user' => $userEmail
        ];
        
        $stmt = $db->prepare("UPDATE validation_requests SET history = ? WHERE id = ?");
        $stmt->execute([json_encode($history), $requestId]);
    } catch (Exception $e) {
        // Silently fails for history to not break main flow
    }
}

function requireAuth($levels = null) {
    $user = getCurrentUser();
    if (!$user) {
        sendError('Sessão expirada. Faça login novamente.', 401);
    }
    
    $userLevel = $user['admin_level'] ?? 'user';
    
    if ($levels && !in_array($userLevel, (array)$levels)) {
        sendError('Sem permissão para esta ação', 403);
    }
    return $user;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

try {
    switch ($method) {
        case 'GET':
            if ($action === 'stats') getStats();
            elseif ($id) getRequest($id);
            else listRequests();
            break;
        case 'POST': createRequest(); break;
        case 'PUT':
            if ($action === 'validate') validateRequest($id);
            elseif ($action === 'correct') correctRequest($id);
            elseif ($action === 'revert') revertRequest($id);
            else updateRequest($id);
            break;
        case 'DELETE': deleteRequest($id); break;
        default: sendError('Método não suportado', 405);
    }
} catch (Exception $e) {
    sendError('Erro interno: ' . $e->getMessage(), 500);
}

function listRequests() {
    $user = requireAuth();
    $db = getDB();
    
    $tab = $_GET['tab'] ?? '';
    $search = $_GET['search'] ?? '';
    $requested_by = $_GET['requested_by'] ?? '';
    $assigned_to = $_GET['assigned_to'] ?? '';
    $package_id = $_GET['package_id'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
    
    $usePagination = isset($_GET['page']) || isset($_GET['limit']) || !empty($tab) || !empty($search);
    
    $where = [];
    $params = [];
    $userLevel = $user['admin_level'] ?? 'user';
    $userEmail = $user['email'] ?? '';

    if (empty($userEmail)) sendError('Usuário sem email configurado', 400);

    // Helper to check for enhanced view permissions (Gerente or custom permission)
    $perms = !empty($user['permissions']) ? json_decode($user['permissions'], true) : [];
    $isGerente = ($user['profile'] ?? '') === 'gerente';
    $viewAll = $isGerente || ($perms['view_all_validations'] ?? false) || $userLevel === 'admin_principal' || $userLevel === 'admin_secundario';

    // Tab Filters
    if ($tab === 'recebidas') {
        $where[] = "LOWER(r.assigned_to) = LOWER(?) AND r.status IN ('pendente', 'em_analise')";
        $params[] = $userEmail;
    } elseif ($tab === 'minhas') {
        $where[] = "LOWER(r.requested_by) = LOWER(?)";
        $params[] = $userEmail;
    } elseif ($tab === 'parcial') {
        $where[] = "LOWER(r.requested_by) = LOWER(?) AND r.status = 'aprovado_parcial'";
        $params[] = $userEmail;
    } elseif ($tab === 'finalizadas') {
        if ($viewAll) {
             $where[] = "r.status IN ('aprovado', 'reprovado', 'aprovado_parcial')";
        } else {
             $where[] = "(LOWER(r.requested_by) = LOWER(?) OR LOWER(r.assigned_to) = LOWER(?)) AND r.status IN ('aprovado', 'reprovado', 'aprovado_parcial')";
             $params[] = $userEmail;
             $params[] = $userEmail;
        }
    } elseif ($tab === 'todas') {
        if (!$viewAll) {
             $where[] = "(LOWER(r.requested_by) = LOWER(?) OR LOWER(r.assigned_to) = LOWER(?))";
             $params[] = $userEmail;
             $params[] = $userEmail;
        }
    } else {
        // Fallback Base Permissions
        if (!$viewAll) {
            $where[] = "(LOWER(r.requested_by) = LOWER(?) OR LOWER(r.assigned_to) = LOWER(?))";
            $params[] = $userEmail;
            $params[] = $userEmail;
        }
    }
    
    if (!empty($search)) {
        $where[] = "r.title LIKE ?";
        $params[] = "%$search%";
    }
    if (!empty($requested_by)) {
        $where[] = "LOWER(r.requested_by) = LOWER(?)";
        $params[] = $requested_by;
    }
    if (!empty($assigned_to)) {
        $where[] = "LOWER(r.assigned_to) = LOWER(?)";
        $params[] = $assigned_to;
    }
    if (!empty($package_id)) {
        $where[] = "r.package_id = ?";
        $params[] = $package_id;
    }
    
    
    if (!empty($startDate)) {
        $where[] = "r.created_at >= ?";
        $params[] = $startDate . ' 00:00:00';
    }
    if (!empty($endDate)) {
        $where[] = "r.created_at <= ?";
        $params[] = $endDate . ' 23:59:59';
    }

    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Backward compatibility check
    if (!$usePagination) {
        $stmt = $db->prepare("SELECT r.*, req.full_name as requester_name, ass.full_name as assigned_name FROM validation_requests r LEFT JOIN users req ON r.requested_by = req.email LEFT JOIN users ass ON r.assigned_to = ass.email $whereSQL ORDER BY r.created_at DESC");
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requests as &$req) {
            $req['content_urls'] = json_decode($req['content_urls'], true) ?? [];
            $req['validation_per_link'] = json_decode($req['validation_per_link'], true) ?? [];
        }
        sendJson($requests);
    }
    
    // Paged Logic
    $offset = ($page - 1) * $limit;
    $countStmt = $db->prepare("SELECT COUNT(*) FROM validation_requests r LEFT JOIN users req ON r.requested_by = req.email LEFT JOIN users ass ON r.assigned_to = ass.email $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT r.*, req.full_name as requester_name, ass.full_name as assigned_name FROM validation_requests r LEFT JOIN users req ON r.requested_by = req.email LEFT JOIN users ass ON r.assigned_to = ass.email $whereSQL ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($requests as &$req) {
        $req['content_urls'] = json_decode($req['content_urls'], true) ?? [];
        $req['validation_per_link'] = json_decode($req['validation_per_link'], true) ?? [];
    }
    
    sendJson([
        'items' => $requests,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / max(1, $limit))
        ]
    ]);
}

function getRequest($id) {
    $user = requireAuth();
    $db = getDB();
    $stmt = $db->prepare("SELECT r.*, req.full_name as requester_name, ass.full_name as assigned_name FROM validation_requests r LEFT JOIN users req ON r.requested_by = req.email LEFT JOIN users ass ON r.assigned_to = ass.email WHERE r.id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) sendError('Não encontrado', 404);
    $req['content_urls'] = json_decode($req['content_urls'], true) ?? [];
    $req['validation_per_link'] = json_decode($req['validation_per_link'], true) ?? [];
    sendJson($req);
}

function createRequest() {
    $user = requireAuth(['user', 'admin_principal']);
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['title']) || empty($data['package_id']) || empty($data['assigned_to'])) sendError('Faltam dados');
    
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM validation_packages WHERE id = ?");
    $stmt->execute([$data['package_id']]);
    $pkg = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("INSERT INTO validation_requests (title, description, description_images, package_id, package_name, content_urls, priority, assigned_to, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['title'], $data['description'] ?? '', json_encode($data['description_images'] ?? []),
        $data['package_id'], $pkg['name'] ?? '', json_encode($data['content_urls'] ?? []),
        $data['priority'] ?? 'normal', $data['assigned_to'], $user['email']
    ]);
    getRequest($db->lastInsertId());
}

function validateRequest($id) {
    $user = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $validationPerLink = $data['validation_per_link'] ?? [];
    $approved = 0;
    foreach ($validationPerLink as $v) {
        if (($v['status'] ?? '') === 'aprovado' || ($v['approved'] ?? false)) $approved++;
    }
    $total = count($validationPerLink);
    $status = $approved === $total ? 'aprovado' : ($approved === 0 ? 'reprovado' : 'aprovado_parcial');
    
    $db = getDB();
    $stmt = $db->prepare("UPDATE validation_requests SET status = ?, validation_per_link = ?, approved_links_count = ?, final_observations = ?, validated_by = ?, validated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, json_encode($validationPerLink), $approved, $data['final_observations'] ?? '', $user['email'], $id]);
    
    addHistory($id, 'validacao', "Status: $status. Links: $approved/$total", $user['email']);
    
    getRequest($id);
}

function correctRequest($id) {
    $user = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $db = getDB();
    $stmt = $db->prepare("UPDATE validation_requests SET status = 'pendente', content_urls = ?, return_count = return_count + 1, validation_per_link = NULL WHERE id = ?");
    $stmt->execute([json_encode($data['content_urls'] ?? []), $id]);
    
    addHistory($id, 'correcao', "Iniciou correção apos status parcial/reprovado", $user['email']);
    
    getRequest($id);
}

function revertRequest($id) {
    $user = requireAuth(['admin_principal']);
    $db = getDB();
    $stmt = $db->prepare("UPDATE validation_requests SET status = 'pendente', validation_per_link = NULL WHERE id = ?");
    $stmt->execute([$id]);
    
    $admin = getCurrentUser();
    addHistory($id, 'reversao', "Admin reverteu status para pendente", $admin['email'] ?? 'admin');
    
    getRequest($id);
}

function deleteRequest($id) {
    $user = requireAuth(['admin_principal']);
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM validation_requests WHERE id = ?");
    $stmt->execute([$id]);
    sendJson(['success' => true]);
}

function updateRequest($id) {
    $user = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $fields = []; $values = [];
    foreach (['title', 'description', 'priority', 'assigned_to', 'status'] as $f) {
        if (isset($data[$f])) { $fields[] = "$f = ?"; $values[] = $data[$f]; }
    }
    if (empty($fields)) sendError('Nada para atualizar');
    $values[] = $id;
    $db = getDB();
    $stmt = $db->prepare("UPDATE validation_requests SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($values);
    getRequest($id);
}

function getStats() {
    $user = requireAuth();
    $db = getDB();
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    
    $where = [];
    $params = [];
    
    $userLevel = $user['admin_level'] ?? 'user';
    $userEmail = $user['email'] ?? '';
    
    if (empty($userEmail)) sendError('Usuário sem email configurado', 400);

    $perms = !empty($user['permissions']) ? json_decode($user['permissions'], true) : [];
    $isGerente = ($user['profile'] ?? '') === 'gerente';
    $viewAll = $isGerente || ($perms['view_all_validations'] ?? false) || $userLevel === 'admin_principal' || $userLevel === 'admin_secundario';

    if (!empty($startDate)) {
        $where[] = "r.created_at >= ?";
        $params[] = $startDate . ' 00:00:00';
    }
    if (!empty($endDate)) {
        $where[] = "r.created_at <= ?";
        $params[] = $endDate . ' 23:59:59';
    }
    
    // Stricter viewAll: Only admins see everything. 
    // Managers (gerente) and users see only their own stats by default on dashboard.
    $isSystemAdmin = $userLevel === 'admin_principal' || $userLevel === 'admin_secundario';
    
    if (!$isSystemAdmin) {
        $where[] = "(LOWER(r.requested_by) = LOWER(?) OR LOWER(r.assigned_to) = LOWER(?))";
        $params[] = $userEmail;
        $params[] = $userEmail;
    }
    
    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN r.status IN ('pendente', 'em_analise') THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN r.status IN ('aprovado', 'aprovado_parcial') THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN r.status = 'reprovado' THEN 1 ELSE 0 END) as rejected
    FROM validation_requests r $whereSQL";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        foreach ($result as $key => $val) {
            $result[$key] = (int)$val;
        }
    } else {
        $result = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
    }
    
    sendJson($result);
}
