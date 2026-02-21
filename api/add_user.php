<?php
/**
 * Script temporário para adicionar usuário manualmente
 * DELETAR APÓS USO!
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

$email = 'grupoclick.designer03@gmail.com';
$name = 'Guilherme Evangelista';

$pdo = getDB();

// Verificar se já existe
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$existing = $stmt->fetch();

if ($existing) {
    $userId = $existing['id'];
    echo "Usuário já existe (ID: $userId). Gerando token...\n";
} else {
    $stmt = $pdo->prepare("INSERT INTO users (email, full_name, admin_level, profile) VALUES (?, ?, 'user', 'validador')");
    $stmt->execute([$email, $name]);
    $userId = $pdo->lastInsertId();
    echo "Usuário criado (ID: $userId). Gerando token...\n";
}

// Gerar token (mesmo formato do auth.php)
$payload = json_encode(['user_id' => $userId, 'exp' => time() + 604800]);
$token = base64_encode($payload . '.' . hash('sha256', $payload . APP_SECRET_SALT));

$frontendUrl = defined('FRONTEND_URL') ? FRONTEND_URL : 'https://clickcheck-grupoclickglobal-com-br.vercel.app';

echo "\n=== TOKEN GERADO ===\n";
echo "Token: $token\n\n";
echo "Link direto de acesso:\n";
echo "$frontendUrl?token=$token\n";
echo "\n=== IMPORTANTE: DELETE ESTE ARQUIVO APÓS USO! ===\n";
