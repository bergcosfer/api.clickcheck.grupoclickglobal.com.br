-- Adicionar campo profile na tabela users (se não existir)
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile VARCHAR(50) DEFAULT 'validador';

-- Adicionar campo permissions se não existir
ALTER TABLE users ADD COLUMN IF NOT EXISTS permissions JSON DEFAULT NULL;

-- Atualizar usuários existentes para ter perfil validador por padrão
UPDATE users SET profile = 'validador' WHERE profile IS NULL AND admin_level != 'admin_principal';
