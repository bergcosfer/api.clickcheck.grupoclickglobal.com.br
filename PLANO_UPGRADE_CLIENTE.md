# Clickcheck — Upgrade "Cliente Final"

Incluir o cliente final no fluxo de validação, com link público de aprovação e sincronização com o ClickUp (canais de Chat) + link de WhatsApp.

## Fluxo alvo

1. Analista cria validação e **seleciona o cliente** (dropdown vindo do ClickUp).
2. Gestor aprova (fluxo interno atual, que já funciona).
3. Sistema **gera link público** (token) + **link WhatsApp**.
4. Cliente abre → viewer (arte/carrossel/vídeo + título + texto) → **aprova/reprova** (reprova abre modal de feedback).
5. Tudo é notificado na **timeline do canal do cliente no ClickUp**.

## Infra já validada (2026-11-12)

- API: PHP puro, HostGator. Repo local `~/clickcheck-api`.
- Front: **Vite + React 18 + Tailwind**, Vercel. Repo local `~/clickcheck-front`.
- ClickUp: workspace **Grupo Click** (`3069076`). Token `pk_...` em `config/database.php` (gitignored).
- Chat API é **v3**: `GET /api/v3/workspaces/{id}/chat/channels` (listar) e `POST .../chat/channels/{channel_id}/messages` (postar). Clientes já são canais (`202315-desentupidora-roter`, etc.).

## Subsistemas

### S1 — Dados (migrations)
- ✅ `migrations/009_client_approval.sql`: tabela `clients` + colunas `client_id`, `public_token`, `client_status`, `client_feedback`, `client_reviewed_at` em `validation_requests`.

### S2 — API (PHP, novos endpoints)
- `api/clients.php` — listar clientes (dropdown do analista), sincronizados do ClickUp.
- `api/client_review.php` — público (só `public_token`): GET conteúdo; POST aprovar/reprovar + feedback.
- `api/share.php` — gerar/retornar link público + link `wa.me`.
- `api/clickup.php` (ou lib) — sync de clientes + postar na timeline do canal.
- `config/database.php` — constantes `CLICKUP_API_TOKEN`, `CLICKUP_WORKSPACE_ID` (já presentes).

### S3 — Integração ClickUp (REST v3)
- Listar canais (`type=CHANNEL`) → popular `clients`.
- Postar mensagem na timeline (`client_status`/eventos).
- *Risco:* API de Chat marcada como experimental — plano B = List + Comments.

### S4 — Frontend (Vercel, Vite/React)
- Página pública do cliente: `/c/{public_token}` (viewer + modal de reprovação).
- Dropdown de cliente em `NovaValidacao.jsx` + botão "enviar pro cliente (WhatsApp)".
- Notificação interna de aprovação/reprovação do cliente.

### S5 — WhatsApp
- Geração de link `https://wa.me/?text=<mensagem + link público>`.

## Ordem de implementação

1. ✅ S1 (migration aditiva)
2. S3 core (lib de ClickUp + sync de clientes) — testável já
3. S2 (endpoints de clientes + share + client_review)
4. S4 (viewer público + seleção de cliente)
5. S5 (link WhatsApp) — junto do S2/S4
6. Segurança (depois, conforme combinado)
