<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$actions = ['USER_CREATED', 'USER_UPDATED', 'USER_ACTIVATED', 'USER_DEACTIVATED', 'INSERT', 'UPDATE', 'DELETE'];

foreach ($actions as $act) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
            VALUES (1, 'users', 99999, :action, null, '{\"test\":1}', CURRENT_TIMESTAMP, '127.0.0.1')
        ");
        $stmt->execute([':action' => $act]);
        echo "Action '$act': SUCCESS\n";
    } catch (Exception $e) {
        echo "Action '$act': FAILED -> " . $e->getMessage() . "\n";
    }
}

// Clean up
$pdo->prepare("DELETE FROM audit_log WHERE entity_id = 99999 AND entity_name = 'users'")->execute();
echo "Cleanup completed.\n";
