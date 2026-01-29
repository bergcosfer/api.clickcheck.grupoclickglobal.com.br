<?php
require_once 'api/config/database.php';
try {
    $db = getDB();
    $stmt = $db->query("SELECT nickname, full_name, email, admin_level FROM users WHERE full_name LIKE '%Jessica%' OR nickname LIKE '%Jessica%'");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- USERS FOUND ---\n";
    foreach ($users as $user) {
        echo "{$user['full_name']} ({$user['nickname']}) | {$user['email']} | {$user['admin_level']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
