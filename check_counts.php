<?php
require_once 'api/config/database.php';
try {
    $db = getDB();
    
    // Find Jessica's email first
    $stmt = $db->query("SELECT email, full_name, admin_level FROM users WHERE full_name LIKE '%Jessica%'");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "--- USERS FOUND ---\n";
    foreach ($users as $u) {
        echo "{$u['full_name']} | {$u['email']} | {$u['admin_level']}\n";
        $email = $u['email'];
        
        // Count for this email
        $q = $db->prepare("SELECT status, COUNT(*) as c FROM validation_requests WHERE assigned_to = ? OR requested_by = ? GROUP BY status");
        $q->execute([$email, $email]);
        $counts = $q->fetchAll(PDO::FETCH_ASSOC);
        echo "Counts for $email:\n";
        foreach ($counts as $c) {
            echo "  - {$c['status']}: {$c['c']}\n";
        }
    }
    
    echo "\n--- SYSTEM TOTALS ---\n";
    $stmt = $db->query("SELECT status, COUNT(*) as c FROM validation_requests GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "{$row['status']}: {$row['c']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
