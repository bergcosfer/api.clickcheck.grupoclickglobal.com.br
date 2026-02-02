<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $results = ['tables' => $tables];
    
    if (in_array('validation_requests', $tables)) {
        $sample = $db->query("SELECT id, title, created_at FROM validation_requests ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $results['validation_requests_sample'] = $sample;
    }

    if (in_array('users', $tables)) {
        $users = $db->query("SELECT email, admin_level, profile FROM users WHERE email = 'berg.cosfer@gmail.com'")->fetchAll(PDO::FETCH_ASSOC);
        $results['master_user'] = $users;
    }

    echo json_encode($results, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
