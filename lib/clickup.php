<?php
/**
 * Clickcheck - Helper de integração com ClickUp (Chat API v3)
 * Usa CLICKUP_API_TOKEN e CLICKUP_WORKSPACE_ID de config/database.php
 * Não depende de banco (só do token). Testável isoladamente.
 */

function clickup_base_url() {
    return 'https://api.clickup.com/api/v3';
}

function clickup_request($method, $path, $body = null) {
    $url = clickup_base_url() . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . CLICKUP_API_TOKEN,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    return [$httpCode, $response, $error];
}

/**
 * Lista os canais de cliente (1 cliente = 1 canal de Chat no ClickUp).
 *
 * Heurística de "canal de cliente": tipo CHANNEL, público, não arquivado/oculto,
 * e nome começando com número (ex.: "202315-desentupidora-roter", "202328 Clinica Multi Criar").
 * Isso exclui canais internos tipo "equipe".
 *
 * Retorna array de ['clickup_channel_id' => ..., 'name' => ..., 'clickup_workspace_id' => ...]
 * ou ['error' => ...] em caso de falha.
 */
function clickup_list_client_channels() {
    $path = '/workspaces/' . CLICKUP_WORKSPACE_ID . '/chat/channels';
    [$code, $response, $error] = clickup_request('GET', $path);
    if ($code !== 200 || $error) {
        return ['error' => "ClickUp listar canais falhou (HTTP $code): " . ($error ?: $response)];
    }
    $data = json_decode($response, true);
    $channels = $data['data'] ?? [];
    $result = [];
    foreach ($channels as $c) {
        if (($c['type'] ?? '') !== 'CHANNEL') continue;
        if (!empty($c['archived']) || !empty($c['is_hidden'])) continue;
        if (($c['visibility'] ?? '') !== 'PUBLIC') continue;
        $name = trim($c['name'] ?? '');
        if ($name === '' || !preg_match('/^[0-9]/', $name)) continue; // só canais com código de cliente
        $result[] = [
            'clickup_channel_id' => $c['id'],
            'name' => $name,
            'clickup_workspace_id' => $c['workspace_id'] ?? CLICKUP_WORKSPACE_ID,
        ];
    }
    return $result;
}

/**
 * Posta uma mensagem na timeline de um canal do cliente.
 * Retorna true em sucesso, ou ['error' => ...] em falha.
 */
function clickup_send_message($channelId, $text) {
    $path = '/workspaces/' . CLICKUP_WORKSPACE_ID . '/chat/channels/' . rawurlencode($channelId) . '/messages';
    [$code, $response, $error] = clickup_request('POST', $path, [
        'type' => 'message',
        'content' => $text,
        'content_format' => 'text/md',
    ]);
    if ($code >= 200 && $code < 300) {
        return true;
    }
    return ['error' => "ClickUp enviar mensagem falhou (HTTP $code): " . ($error ?: $response)];
}
