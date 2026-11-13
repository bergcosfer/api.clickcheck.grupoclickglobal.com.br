# ONDE PARAMOS — Clickcheck "Cliente Final"

Última atualização: **2026-11-13**

---

## 1. O que é o sistema

**Clickcheck** = sistema de validação de conteúdo (arte/carrossel/vídeo).
- **Backend:** PHP puro, HostGator (shared).
- **Frontend:** React + Vite, na Vercel.

---

## 2. A feature (o que estamos construindo)

Incluir o **CLIENTE FINAL** no fluxo de validação:

1. Analista cria a validação, seleciona **cliente** (canal do ClickUp), **designer/editor** e **gestor**.
2. Gestor aprova (fluxo interno).
3. Sistema gera **link público** + **link WhatsApp**.
4. Cliente abre → vê a arte → **APROVA** ou **REPROVA** (reprovando, arrasta **retângulos** na imagem e comenta, estilo Google Docs).
5. Tudo cai na **timeline do canal do cliente no ClickUp**, com **@menção** dos 3 envolvidos.

---

## 3. Ambientes (homologação)

| Item | Valor |
|---|---|
| Backend homolog | `https://homolog.clickcheck.grupoclickglobal.com.br` |
| HostGator | user `linden03`, servidor `162.241.61.184` |
| Banco homolog | `linden03_clickcheck_homolog` |
| Repo deploy backend | `github.com/bergcosfer/homolog.clickcheck.grupoclickglobal.com.br` (branch `master`) |
| Repo frontend | `github.com/bergcosfer/clickcheck.grupoclickglobal.com.br` (branch `homologacao`) |
| Front homolog | Vercel preview (URL **muda a cada deploy**) |
| ClickUp | workspace "Grupo Click" (id `3069076`) |
| Credenciais | **`config/database.php` (gitignored, NÃO commitar)** — token ClickUp + senha DB + APP_SECRET_SALT |

---

## 4. Já validado (funcionando de ponta a ponta)

✅ Listar clientes do ClickUp
✅ Criar validação com cliente + designer
✅ Gestor aprovar
✅ Gerar link público + WhatsApp
✅ Cliente ver conteúdo + aprovar/reprovar
✅ Timeline no canal do ClickUp

---

## 5. O QUE FALTA AGORA (2 coisas — o usuário)

1. **Rodar a migration 010** no phpMyAdmin (banco `linden03_clickcheck_homolog`):
```sql
ALTER TABLE validation_requests
    ADD COLUMN client_annotations JSON NULL,
    ADD COLUMN designer VARCHAR(255) NULL;
```
2. **Puxar o backend** no HostGator (cPanel → Git Version Control → Pull do repo homolog).

Depois: testar **anotação por retângulo** e as **@menções** no ClickUp.

---

## 6. Pendências / incertezas

- **Formato da @menção:** usei `[@Nome](#user_mention#id)`. **CONFIRMAR** se renderiza como menção clicável no app do ClickUp (senão vira texto cru). Se falhar, ajustar o formato.
- **Domínio fixo do front homolog:** ainda não criado o projeto Vercel `clickcheck-homolog` (URL estável). Sem ele, a URL muda a cada deploy.
- **Segurança (deferida, como combinado):**
  - `validators.php` (e outros) aceitam token **sem checar assinatura**.
  - Scripts soltos commitados (`add_user.php`, `test_db.php`, `find_jessica.php`, `error_log` 3MB).
  - **Senha do banco vazou** num erro anterior — precisa **trocar** (está no config, gitignored).

---

## 7. Arquivos-chave

### Backend (`~/clickcheck-api`)
- `migrations/009_client_approval.sql` — tabela `clients` + colunas de cliente.
- `migrations/010_client_annotations_designer.sql` — `client_annotations` + `designer`.
- `api/clients.php` — lista/sincroniza clientes (ClickUp).
- `api/share.php` — gera link público + WhatsApp.
- `api/client_review.php` — público: GET conteúdo / POST decisão + anotações.
- `lib/bootstrap.php` — helpers HTTP/auth compartilhados.
- `lib/clickup.php` — integração ClickUp (canais, mensagens, @menções).
- `api/requests.php` — `createRequest` aceita `client_id` + `designer`.
- `config/database.php` — **gitignored** (DB homolog + CLICKUP + FRONTEND_URL).

### Frontend (`~/clickcheck-front`)
- `src/pages/ClienteAprovacao.jsx` — viewer público `/c/:token` + anotação por retângulo.
- `src/pages/NovaValidacao.jsx` — dropdowns de cliente + designer.
- `src/pages/CentralValidacao.jsx` — botão "Enviar pro cliente" + feedback do cliente.
- `src/lib/api.jsx` — `VITE_API_URL`, login via `window.location.origin`, share via GET.

---

## 8. Comando útil — gerar token de teste

```bash
php -r '$s="APP_SECRET_SALT_DO_CONFIG"; $p=json_encode(["user_id"=>1,"exp"=>time()+604800]); echo base64_encode($p.".".hash("sha256",$p.$s));'
```

Teste: `curl -H "Authorization: Bearer TOKEN" https://homolog.clickcheck.grupoclickglobal.com.br/api/clients.php`
