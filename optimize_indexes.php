<?php
require_once 'config/database.php';
try {
    $db = getDB();
    echo "--- OPTIMIZING DATABASE INDEXES ---\n";
    
    $queries = [
        "ALTER TABLE validation_requests ADD INDEX idx_requested_by (requested_by)",
        "ALTER TABLE validation_requests ADD INDEX idx_assigned_to (assigned_to)",
        "ALTER TABLE validation_requests ADD INDEX idx_created_at (created_at)",
        "ALTER TABLE validation_requests ADD INDEX idx_status (status)"
    ];
    
    foreach ($queries as $q) {
        try {
            $db->exec($q);
            echo "Success: $q\n";
        } catch (Exception $e) {
            echo "Skipped/Error: $q (May already exist or error: " . $e->getMessage() . ")\n";
        }
    }
    
    echo "\nIndexes currently in validation_requests:\n";
    $stmt = $db->query("SHOW INDEX FROM validation_requests");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
