# Homologação Clickcheck

Ambiente de homologação **separado da produção**. Nada em produção é tocado até você validar.

## Arquitetura

```
FRONT homolog  (Vercel Preview, branch homologacao)  →  BACKEND homolog (subdomínio)  →  BANCO homolog
FRONT produção (Vercel main)                          →  BACKEND produção              →  BANCO produção
```

Branches: `homologacao` (dev/teste) e `main`/`master` (produção). Só merge `homologacao` → `main`/`master` quando validado.

---

## Backend (HostGator, via SSH git push)

### 1. Banco de homologação (cPanel)
1. **MySQL Databases** → crie o banco `USUARIO_clickcheck_homolog` + um usuário + senha; dê ALL PRIVILEGES.
2. No **PHPMyAdmin da produção**: exporte o schema (só estrutura) e importe no banco novo.
   - Opcional: exporte também os dados (INSERTs) para testar com dados realistas.

### 2. Subdomínio (cPanel)
1. **Domains → Subdomains** → crie `homolog`, apontando para uma pasta (ex.: `public_html/homolog`).

### 3. Repositório git de deploy no servidor (SSH)
1. Criar o repo bare no servidor:
   ```
   ssh USUARIO@HOST "mkdir -p ~/repos && cd ~/repos && git init --bare clickcheck-homolog.git"
   ```
2. Criar o hook de deploy `~/repos/clickcheck-homolog.git/hooks/post-receive` com o conteúdo abaixo e `chmod +x`:
   ```sh
   #!/bin/sh
   # Deploy homolog: repo bare -> document root do subdomínio
   TARGET="/home/USUARIO/public_html/homolog"
   while read oldrev newrev ref; do
     if [ "$ref" = "refs/heads/master" ]; then
       git --work-tree="$TARGET" checkout -f
       echo "Homolog deployado em $TARGET"
     fi
   done
   ```
3. No seu computador, adicionar o remote e subir a branch:
   ```
   cd ~/clickcheck-api
   git remote add homolog USUARIO@HOST:repos/clickcheck-homolog.git
   git push homolog homologacao:master
   ```

### 4. `config/database.php` do homolog (no servidor)
No `public_html/homolog/config/`, criar `database.php` apontando pro **banco homolog**:
```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'USUARIO_clickcheck_homolog');
define('DB_USER', 'USUARIO_DB');
define('DB_PASS', 'SENHA_DB');

define('GOOGLE_CLIENT_ID', '...');      // mesmas do Google OAuth
define('GOOGLE_CLIENT_SECRET', '...');
define('GOOGLE_REDIRECT_URI', 'https://homolog.SEUDOMINIO/api/auth.php?action=google-callback');
define('APP_SECRET_SALT', '...');

define('CLICKUP_API_TOKEN', 'pk_...');
define('CLICKUP_WORKSPACE_ID', '3069076');
define('FRONTEND_URL', 'https://URL-DO-PREVIEW-DA-VERCEL'); // front de homolog

// getDB() e getJsonPayload() iguais aos da produção
```

> ⚠️ O `config/database.php` é gitignorado — cada ambiente tem o seu. O do homolog aponta pro banco homolog.

---

## Front (Vercel)

1. Subir a branch:
   ```
   cd ~/clickcheck-front
   git push origin homologacao
   ```
2. A Vercel cria um **Preview Deployment** automaticamente (URL própria, não é a produção).
3. Na Vercel → **Settings → Environment Variables** → adicionar `VITE_API_URL`:
   - Escopo **Preview** = `https://homolog.SEUDOMINIO/api`
   - Escopo **Production** = `https://clickcheck.grupoclickglobal.com.br/api` (ou deixe sem, que usa o fallback)

---

## Fluxo de trabalho

1. Desenvolver/commitar na branch `homologacao`.
2. `git push` (front e backend) → sobe na homologação.
3. Testar no Preview da Vercel + backend do subdomínio (banco homolog).
4. Validou → `git merge homologacao` em `main`/`master` → produção.

> Obs.: a integração ClickUp usa o **mesmo workspace** em homolog e produção. Ao testar, as mensagens de timeline caem nos canais reais — use um canal de teste se quiser evitar.
