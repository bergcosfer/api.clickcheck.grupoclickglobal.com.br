<?php
require_once 'api/config/database.php';

try {
    $db = getDB();
    
    echo "--- SUMMARY BY STATUS ---\n";
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM validation_requests GROUP BY status");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as $row) {
        echo "{$row['status']}: {$row['count']}\n";
    }
    
    echo "\n--- BY ASSIGNED_TO (VALIDATOR) ---\n";
    $stmt = $db->query("SELECT assigned_to, status, COUNT(*) as count FROM validation_requests GROUP BY assigned_to, status ORDER BY assigned_to");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as $row) {
        echo "{$row['assigned_to']} | {$row['status']}: {$row['count']}\n";
    }
    
    echo "\n--- ANOMALIES ---\n";
    $stmt = $db->query("SELECT id, title, status FROM validation_requests WHERE status NOT IN ('pendente','em_analise','aprovado','reprovado','aprovado_parcial') OR status IS NULL");
    $anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($anomalies) > 0) {
        echo "FOUND " . count($anomalies) . " INVALID STATUSES:\n";
        print_r($anomalies);
    } else {
        echo "No invalid statuses found.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trying alternative path for config...\n";
    try {
        require_once 'config/database.php';
        $db = getDB();
        echo "Connected via alternative path.\n";
    } catch (Exception $e2) {
        echo "Error 2: " . $e2->getMessage();
    }
}
