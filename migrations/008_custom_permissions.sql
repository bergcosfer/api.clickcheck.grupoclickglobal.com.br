-- Migration 008: Adicionar coluna custom_permissions se não existir
ALTER TABLE users ADD COLUMN IF NOT EXISTS custom_permissions JSON NULL;
