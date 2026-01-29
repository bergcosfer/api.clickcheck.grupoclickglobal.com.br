<?php
require_once 'api/config/database.php';
try {
    $db = getDB();
    $month = date('Y-m');
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate)) . ' 23:59:59';

    echo "--- AUDIT FOR GOALS TRACKING ---\n";
    $names = ['Rayane', 'Ingrid'];
    
    foreach ($names as $name) {
        echo "\nChecking for: $name\n";
        $stmt = $db->prepare("SELECT email, full_name, id FROM users WHERE full_name LIKE ?");
        $stmt->execute(['%'.$name.'%']);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo "User $name not found in DB.\n";
            continue;
        }
        
        $email = $user['email'];
        echo "Email: $email (ID: {$user['id']})\n";
        
        // Check goals for this month
        $stmt = $db->prepare("SELECT g.*, p.name as pkg_name FROM user_goals g JOIN validation_packages p ON g.package_id = p.id WHERE g.user_id = ? AND g.month = ?");
        $stmt->execute([$user['id'], $month]);
        $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($goals)) {
            echo "No goals found for $month.\n";
        } else {
            foreach ($goals as $g) {
                echo "Goal: {$g['pkg_name']} (ID: {$g['package_id']}) | Target: {$g['target_count']}\n";
                
                // Check matching requests
                $stmt = $db->prepare("
                    SELECT status, approved_links_count, created_at 
                    FROM validation_requests 
                    WHERE requested_by = ? AND package_id = ? 
                    AND created_at BETWEEN ? AND ?
                ");
                $stmt->execute([$email, $g['package_id'], $startDate, $endDate]);
                $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "  Found " . count($reqs) . " requests in this month for this package.\n";
                $approved_sum = 0;
                foreach ($reqs as $r) {
                    echo "    - Status: {$r['status']} | Approved: {$r['approved_links_count']} | Created: {$r['created_at']}\n";
                    if (in_array($r['status'], ['aprovado', 'aprovado_parcial'])) {
                        $approved_sum += $r['approved_links_count'];
                    }
                }
                echo "  Calculated Progress: $approved_sum\n";
            }
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
