-- Migration 007: Adicionar coluna para imagens na descrição das validações
ALTER TABLE validation_requests ADD COLUMN IF NOT EXISTS description_images JSON NULL;
