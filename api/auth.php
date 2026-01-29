<?php
/**
 * Clickcheck - API de Autenticação com Token
 * Otimizado com Robustez para HostGator/Shared Hosting
 */

// 1. Habilitar erros temporariamente para debug (Descomente se precisar ver o erro na tela)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// 2. Compatibilidade para getallheaders() em servidores que não usam Apache puro (HostGator/FPM)
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

// 3. Carregar configuração com tratamento de erro
$configPath = __DIR__ . '/../config/database.php';
if (!file_exists($configPath)) {
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode(['error' => 'Arquivo de configuração não encontrado. Verifique a pasta config/']));
}
require_once $configPath;

// 4. Obter instância do banco
try {
    $pdo = getDB();
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode(['error' => 'Erro crítico ao inicializar banco: ' . $e->getMessage()]));
}

// 5. Cabeçalhos de Resposta (CORS)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'me':
        handleMe();
        break;
    case 'google-callback':
        handleGoogleCallback();
        break;
    default:
        echo json_encode(['error' => 'Ação não encontrada']);
}

function generateToken($userId) {
    $payload = json_encode(['user_id' => $userId, 'exp' => time() + 604800]);
    return base64_encode($payload . '.' . hash('sha256', $payload . APP_SECRET_SALT));
}

function validateToken($token) {
    if (!$token) return null;
    $decoded = base64_decode($token);
    $parts = explode('.', $decoded);
    if (count($parts) !== 2) return null;
    $payload = json_decode($parts[0], true);
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;
    // Validar assinatura do salt
    $expectedSignature = hash('sha256', $parts[0] . APP_SECRET_SALT);
    if ($parts[1] !== $expectedSignature) return null;
    return $payload;
}

function handleLogin() {
    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_REDIRECT_URI')) {
        die(json_encode(['error' => 'Configurações do Google OAuth ausentes no database.php']));
    }
    $clientId = GOOGLE_CLIENT_ID;
    $redirectUri = urlencode(GOOGLE_REDIRECT_URI);
    $scope = urlencode('email profile');
    
    $authUrl = "https://accounts.google.com/o/oauth2/auth?" .
        "client_id={$clientId}&redirect_uri={$redirectUri}&response_type=code&scope={$scope}&access_type=offline";
    
    header("Location: {$authUrl}");
    exit;
}

function handleGoogleCallback() {
    global $pdo;
    $code = $_GET['code'] ?? null;
    $frontendUrl = defined('FRONTEND_URL') ? FRONTEND_URL : 'https://clickcheck-grupoclickglobal-com-br.vercel.app';
    
    if (!$code) {
        header("Location: {$frontendUrl}?error=no_code");
        exit;
    }
    
    $clientId = GOOGLE_CLIENT_ID;
    $clientSecret = GOOGLE_CLIENT_SECRET;
    $redirectUri = GOOGLE_REDIRECT_URI;
    
    // Trocar código por token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ]));
    $response = curl_exec($ch);
    curl_close($ch);
    
    $tokenData = json_decode($response, true);
    
    if (!isset($tokenData['access_token'])) {
        header("Location: {$frontendUrl}?error=token_failed");
        exit;
    }
    
    // Obter informações do usuário
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$tokenData['access_token']}"]);
    $userResponse = curl_exec($ch);
    curl_close($ch);
    
    $googleUser = json_decode($userResponse, true);
    
    if (!isset($googleUser['email'])) {
        header("Location: {$frontendUrl}?error=no_email");
        exit;
    }
    
    // Criar ou atualizar usuário
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$googleUser['email']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (email, full_name, google_id, profile_picture) VALUES (?, ?, ?, ?)");
        $stmt->execute([$googleUser['email'], $googleUser['name'] ?? '', $googleUser['id'], $googleUser['picture'] ?? '']);
        $userId = $pdo->lastInsertId();
    } else {
        $userId = $user['id'];
    }
    
    $token = generateToken($userId);
    header("Location: {$frontendUrl}?token={$token}");
    exit;
}

function handleMe() {
    global $pdo;
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
    
    $payload = validateToken($token);
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }
    
    unset($user['google_id']);
    
    // Calcular permissões baseadas no perfil ou usar permissions
    $permissions = getPermissionsForUser($user);
    $user['permissions'] = $permissions;
    
    echo json_encode($user);
}

function getPermissionsForUser($user) {
    // Perfis padrão com suas permissões
    $profilePermissions = [
        'validador' => [
            'view_dashboard' => true, 'create_validation' => false, 'view_assigned' => true,
            'view_all_validations' => false, 'validate' => true, 'view_ranking' => true,
            'view_reports' => false, 'manage_packages' => false, 'manage_users' => false,
            'edit_validation' => false, 'delete_validation' => false, 'view_wiki' => true,
        ],
        'solicitante' => [
            'view_dashboard' => true, 'create_validation' => true, 'view_assigned' => false,
            'view_all_validations' => true, 'validate' => false, 'view_ranking' => true,
            'view_reports' => false, 'manage_packages' => false, 'manage_users' => false,
            'edit_validation' => false, 'delete_validation' => false, 'view_wiki' => true,
        ],
        'gerente' => [
            'view_dashboard' => true, 'create_validation' => true, 'view_assigned' => true,
            'view_all_validations' => true, 'validate' => true, 'view_ranking' => true,
            'view_reports' => true, 'manage_packages' => true, 'manage_users' => false,
            'edit_validation' => true, 'delete_validation' => true, 'view_wiki' => true,
        ],
        'admin' => [
            'view_dashboard' => true, 'create_validation' => true, 'view_assigned' => true,
            'view_all_validations' => true, 'validate' => true, 'view_ranking' => true,
            'view_reports' => true, 'manage_packages' => true, 'manage_users' => true,
            'edit_validation' => true, 'delete_validation' => true, 'view_wiki' => true,
        ],
    ];
    
    if ($user['admin_level'] === 'admin_principal' || $user['admin_level'] === 'admin_secundario') {
        return $profilePermissions['admin'];
    }
    
    if (!empty($user['permissions'])) {
        $custom = json_decode($user['permissions'], true);
        if (is_array($custom)) return $custom;
    }
    
    $profile = $user['profile'] ?? 'validador';
    return $profilePermissions[$profile] ?? $profilePermissions['validador'];
}
