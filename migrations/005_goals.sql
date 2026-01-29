-- Tabela de metas por usuário
CREATE TABLE IF NOT EXISTS user_goals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  package_id INT NOT NULL,
  target_count INT NOT NULL DEFAULT 0,
  month VARCHAR(7) NOT NULL COMMENT 'Formato: YYYY-MM',
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (package_id) REFERENCES validation_packages(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_package_month (user_id, package_id, month)
);

-- Índices para performance
CREATE INDEX idx_goals_user_month ON user_goals(user_id, month);
CREATE INDEX idx_goals_month ON user_goals(month);
