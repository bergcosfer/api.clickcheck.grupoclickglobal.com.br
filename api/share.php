<?php
/**
 * Clickcheck - Gera/retorna o link público do cliente + link de WhatsApp.
 * Requer que a validação já esteja aprovada pelo gestor.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/clickup.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$user = requireAuth(['user', 'admin_principal', 'admin_secundario']);

$id = $_GET['id'] ?? null;
if (!$id) sendError('ID da validação obrigatório');

$db = getDB();
$stmt = $db->prepare("SELECT * FROM validation_requests WHERE id = ?");
$stmt->execute([$id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) sendError('Validação não encontrada', 404);
if (($req['status'] ?? '') !== 'aprovado') {
    sendError('A validação precisa estar aprovada pelo gestor antes de gerar o link do cliente', 400);
}

// Gera o token público na primeira vez; reutiliza nas seguintes
if (empty($req['public_token'])) {
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare("UPDATE validation_requests SET public_token = ?, client_status = 'enviado' WHERE id = ?");
    $stmt->execute([$token, $id]);
    addHistory($id, 'cliente', 'Link do cliente gerado', $user['email']);
} else {
    $token = $req['public_token'];
}

$frontUrl = defined('FRONTEND_URL') ? FRONTEND_URL : 'https://clickcheck-grupoclickglobal-com-br.vercel.app';
$publicUrl = $frontUrl . '/c/' . $token;
$message = 'Olá! Sua peça está pronta para aprovação. Acesse, aprove ou peça ajustes: ' . $publicUrl;
$whatsappUrl = 'https://wa.me/?text=' . urlencode($message);

// Notifica na timeline do canal do cliente (se houver cliente vinculado)
$channelId = null;
if (!empty($req['client_id'])) {
    $s = $db->prepare("SELECT clickup_channel_id FROM clients WHERE id = ?");
    $s->execute([$req['client_id']]);
    $c = $s->fetch(PDO::FETCH_ASSOC);
    $channelId = $c['clickup_channel_id'] ?? null;
}
if ($channelId) {
    $mentions = clickup_mentions_for_request($req);
    $text = "🔔 Nova validação aguardando o cliente: **{$req['title']}**\n{$publicUrl}";
    if ($mentions !== '') $text .= "\n\n" . $mentions;
    clickup_send_message($channelId, $text);
}

sendJson([
    'public_token' => $token,
    'public_url' => $publicUrl,
    'whatsapp_url' => $whatsappUrl,
]);
