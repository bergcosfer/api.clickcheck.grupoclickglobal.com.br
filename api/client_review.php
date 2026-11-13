<?php
/**
 * Clickcheck - Visualização e decisão do CLIENTE (público, sem login).
 * GET  ?token=...  -> conteúdo da validação (título, texto, links)
 * POST ?token=...  -> { decision: "aprovado"|"reprovado", feedback: "..." }
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

$token = $_GET['token'] ?? null;
if (!$token) sendError('Token inválido', 400);

$db = getDB();
$stmt = $db->prepare(
    "SELECT r.*, c.name AS client_name, c.clickup_channel_id
     FROM validation_requests r
     LEFT JOIN clients c ON r.client_id = c.id
     WHERE r.public_token = ?"
);
$stmt->execute([$token]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) sendError('Link inválido ou expirado', 404);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    sendJson([
        'title' => $req['title'],
        'description' => $req['description'],
        'content_urls' => json_decode($req['content_urls'] ?? '[]', true) ?? [],
        'description_images' => json_decode($req['description_images'] ?? '[]', true) ?? [],
        'client_status' => $req['client_status'],
        'client_feedback' => $req['client_feedback'],
        'client_annotations' => json_decode($req['client_annotations'] ?? '[]', true) ?? [],
        'client_name' => $req['client_name'],
    ]);
}

if ($method === 'POST') {
    $data = getJsonPayload();
    $decision = $data['decision'] ?? null;
    $feedback = trim($data['feedback'] ?? '');
    $annotations = $data['annotations'] ?? [];

    if (!in_array($decision, ['aprovado', 'reprovado'], true)) sendError('Decisão inválida');
    if ($decision === 'reprovado' && $feedback === '' && empty($annotations)) {
        sendError('Descreva o que precisa ser ajustado ou marque uma região da imagem');
    }

    $stmt = $db->prepare(
        "UPDATE validation_requests
         SET client_status = ?, client_feedback = ?, client_annotations = ?, client_reviewed_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$decision, $feedback, json_encode($annotations, JSON_UNESCAPED_UNICODE), $req['id']]);

    addHistory($req['id'], 'cliente', "Cliente {$decision}: {$feedback}", 'cliente');

    if (!empty($req['clickup_channel_id'])) {
        $emoji = $decision === 'aprovado' ? '✅' : '❌';
        $text = "{$emoji} Cliente **{$decision}** a validação \"{$req['title']}\"";
        if ($feedback !== '') $text .= "\nFeedback: {$feedback}";
        foreach ($annotations as $i => $a) {
            $text .= "\n📌 Anotação " . ($i + 1) . ": " . ($a['comment'] ?? '');
        }
        clickup_send_message($req['clickup_channel_id'], $text);
    }

    sendJson(['success' => true, 'status' => $decision]);
}

sendError('Método não suportado', 405);
