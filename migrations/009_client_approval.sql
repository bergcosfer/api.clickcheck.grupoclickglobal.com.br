-- Clickcheck - Migration 009: cliente final no fluxo de validação
-- ADITIVA: NÃO apaga nem altera nenhum dado existente.
-- (1) cria a tabela clients (1 cliente = 1 canal de Chat no ClickUp)
-- (2) adiciona colunas de aprovação do cliente em validation_requests

-- 1) Tabela de clientes (sincronizada com os canais de Chat do ClickUp)
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clickup_channel_id VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    clickup_workspace_id VARCHAR(50) DEFAULT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clients_clickup_channel (clickup_channel_id),
    INDEX idx_clients_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Colunas de aprovação do cliente em validation_requests
--    (nullable + default NULL => linhas existentes ficam intactas, só ganham NULL)
ALTER TABLE validation_requests
    ADD COLUMN client_id INT NULL,
    ADD COLUMN public_token VARCHAR(64) NULL,
    ADD COLUMN client_status ENUM('aguardando','enviado','aprovado','reprovado') NULL DEFAULT NULL,
    ADD COLUMN client_feedback TEXT NULL,
    ADD COLUMN client_reviewed_at DATETIME NULL,
    ADD INDEX idx_requests_client (client_id),
    ADD UNIQUE KEY uq_requests_public_token (public_token),
    ADD CONSTRAINT fk_requests_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
