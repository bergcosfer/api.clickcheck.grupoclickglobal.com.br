<?php
/**
 * Clickcheck - Lista de clientes (canais de Chat do ClickUp).
 * Sincroniza a tabela clients com os canais atuais e retorna a lista.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/clickup.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

requireAuth(['user', 'admin_principal', 'admin_secundario']);

$channels = clickup_list_client_channels();
if (isset($channels['error'])) {
    sendError($channels['error'], 502);
}

$db = getDB();

// Upsert: sincroniza clients com os canais atuais (mantém os existentes ativos)
foreach ($channels as $c) {
    $stmt = $db->prepare(
        "INSERT INTO clients (clickup_channel_id, name, clickup_workspace_id, active)
         VALUES (?, ?, ?, TRUE)
         ON DUPLICATE KEY UPDATE name = VALUES(name), clickup_workspace_id = VALUES(clickup_workspace_id), active = TRUE"
    );
    $stmt->execute([
        $c['clickup_channel_id'],
        $c['name'],
        $c['clickup_workspace_id'] ?? null,
    ]);
}

$stmt = $db->query(
    "SELECT id, clickup_channel_id, name, active
     FROM clients
     WHERE active = TRUE
     ORDER BY name"
);

sendJson($stmt->fetchAll(PDO::FETCH_ASSOC));
