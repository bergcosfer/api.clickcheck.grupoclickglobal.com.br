<?php
require_once 'config/database.php';
try {
    $db = getDB();
    
    // Find Jessica's email
    $stmt = $db->query("SELECT email, full_name, admin_level, profile FROM users WHERE full_name LIKE '%Jessica%' OR nickname LIKE '%Jessica%'");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "No user found with name Jessica.\n";
        // List all users to see what we have
        $stmt = $db->query("SELECT email, full_name FROM users LIMIT 20");
        echo "Sample Users:\n";
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            echo "- {$u['full_name']} ({$u['email']})\n";
        }
    } else {
        foreach ($users as $u) {
            $email = $u['email'];
            echo "--- AUDIT FOR: {$u['full_name']} ({$email}) ---\n";
            echo "Admin Level: {$u['admin_level']} | Profile: {$u['profile']}\n\n";
            
            // Total items where she is creator or assigned
            $q = $db->prepare("SELECT status, COUNT(*) as c FROM validation_requests WHERE assigned_to = ? OR requested_by = ? GROUP BY status");
            $q->execute([$email, $email]);
            $counts = $q->fetchAll(PDO::FETCH_ASSOC);
            
            $total = 0;
            echo "Items count by status:\n";
            foreach ($counts as $c) {
                echo "  - {$c['status']}: {$c['c']}\n";
                $total += $c['c'];
            }
            echo "Total associated: $total\n\n";
            
            // Check specifically for items assigned to her that are pending/em_analise
            $q = $db->prepare("SELECT id, title, status FROM validation_requests WHERE assigned_to = ? AND status IN ('pendente', 'em_analise') ORDER BY created_at DESC LIMIT 5");
            $q->execute([$email]);
            echo "Top 5 Pending for Jessica:\n";
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                echo "  ID: {$row['id']} | {$row['title']} | Status: {$row['status']}\n";
            }
            
            // Check for items she approved
            $q = $db->prepare("SELECT id, title, status FROM validation_requests WHERE validated_by = ? ORDER BY validated_at DESC LIMIT 5");
            $q->execute([$email]);
            echo "\nTop 5 Items validated by Jessica:\n";
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                echo "  ID: {$row['id']} | {$row['title']} | Status: {$row['status']}\n";
            }
        }
    }
    
    echo "\n--- SYSTEM TOTALS ---\n";
    $stmt = $db->query("SELECT status, COUNT(*) as c FROM validation_requests GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "{$row['status']}: {$row['c']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
