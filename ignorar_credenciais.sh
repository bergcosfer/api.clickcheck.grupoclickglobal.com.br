#!/bin/bash

# Adicione aqui os arquivos ou pastas que contêm senhas/credenciais
ARQUIVOS_SENSIVEIS=(
  "config/--database.php"
  ".env"
  "*.pem"
  "*.key"
  "credentials.json"
)

echo "🔒 Iniciando proteção de credenciais..."

# Cria o .gitignore se ele não existir
touch .gitignore

for arquivo in "${ARQUIVOS_SENSIVEIS[@]}"; do
    # 1. Adiciona ao .gitignore se ainda não estiver lá
    if ! grep -q "$arquivo" .gitignore 2>/dev/null; then
        echo "$arquivo" >> .gitignore
        echo "✅ Adicionado ao .gitignore: $arquivo"
    else
        echo "ℹ️  Já estava no .gitignore: $arquivo"
    fi

    # 2. Remove do cache do Git (se estiver sendo rastreado)
    # Isso não apaga o arquivo do seu computador, apenas do Git.
    git ls-files --error-unmatch "$arquivo" >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        git rm --cached "$arquivo" >/dev/null 2>&1
        echo "🗑️  Removido do rastreamento do Git: $arquivo"
    fi
done

echo ""
echo "🚀 Concluído! Agora execute os comandos abaixo para salvar as mudanças:"
echo "git add .gitignore"
echo "git commit -m 'chore: adiciona arquivos de credenciais ao .gitignore'"
