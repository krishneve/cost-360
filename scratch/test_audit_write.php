<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();

// Test writeAuditLog with USER_CREATED
require_once __DIR__ . '/../admin/users/api.php';
// Let's test writeAuditLog directly
try {
    writeAuditLog($pdo, 1, 99999, 'INSERT', null, ['username' => 'test_audit_user', 'status' => 'ACTIVE']);
    echo "Audit log insert SUCCESS!\n";
    // Clean up test audit log row
    $pdo->prepare("DELETE FROM audit_log WHERE entity_id = 99999 AND entity_name = 'users'")->execute();
} catch (Exception $e) {
    echo "Audit log insert FAILED: " . $e->getMessage() . "\n";
}
