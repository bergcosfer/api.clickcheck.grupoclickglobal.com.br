-- Clickcheck Database Schema
-- Execute este arquivo no PHPMyAdmin para criar as tabelas

CREATE DATABASE IF NOT EXISTS clickcheck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE clickcheck;

-- Tabela de Usuários
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    full_name VARCHAR(255),
    admin_level ENUM('convidado', 'user', 'admin_principal') DEFAULT 'convidado',
    department VARCHAR(255),
    phone VARCHAR(50),
    profile_picture VARCHAR(500),
    nickname VARCHAR(100),
    google_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_admin_level (admin_level)
) ENGINE=InnoDB;

-- Tabela de Pacotes de Validação
CREATE TABLE IF NOT EXISTS validation_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    type ENUM('artwork', 'texto_copy', 'video', 'documento', 'outro') NOT NULL,
    criteria JSON NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_by_email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_active (active)
) ENGINE=InnoDB;

-- Tabela de Solicitações de Validação
CREATE TABLE IF NOT EXISTS validation_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    package_id INT NOT NULL,
    package_name VARCHAR(255),
    content_urls JSON NOT NULL,
    status ENUM('pendente', 'em_analise', 'aprovado', 'reprovado', 'aprovado_parcial') DEFAULT 'pendente',
    priority ENUM('baixa', 'normal', 'alta', 'urgente') DEFAULT 'normal',
    assigned_to VARCHAR(255) NOT NULL,
    due_date DATETIME,
    return_count INT DEFAULT 0,
    approved_links_count INT DEFAULT 0,
    total_links_count INT DEFAULT 0,
    validation_per_link JSON,
    final_observations TEXT,
    validated_by VARCHAR(255),
    validated_at DATETIME,
    requested_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_requested_by (requested_by),
    FOREIGN KEY (package_id) REFERENCES validation_packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Inserir usuário admin inicial (você deve alterar o email)
INSERT INTO users (email, full_name, admin_level, nickname) 
VALUES ('admin@clickcheck.com', 'Administrador', 'admin_principal', 'Admin')
ON DUPLICATE KEY UPDATE admin_level = 'admin_principal';
