-- Clickcheck - Migration 010: anotações do cliente (retângulo) + designer/editor
-- ADITIVA: não apaga nem altera dados existentes.

ALTER TABLE validation_requests
    ADD COLUMN client_annotations JSON NULL,
    ADD COLUMN designer VARCHAR(255) NULL;
