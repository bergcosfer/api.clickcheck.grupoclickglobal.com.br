<?php
require_once __DIR__ . '/api/config/database.php';

try {
    $db = getDB();
    
    // 1. Adicionar coluna history se não existir
    $stmt = $db->query("SHOW COLUMNS FROM validation_requests LIKE 'history'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE validation_requests ADD COLUMN history TEXT NULL AFTER final_observations");
        echo "Coluna 'history' adicionada com sucesso!\n";
    } else {
        echo "Coluna 'history' já existe.\n";
    }

    echo "\nMigração concluída!";
} catch (Exception $e) {
    echo "Erro na migração: " . $e->getMessage();
}
