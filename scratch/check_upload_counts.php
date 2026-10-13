<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$pdo = getDbConnection();
$allTables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND (table_name LIKE '%upload%' OR table_name LIKE '%import%')")->fetchAll(PDO::FETCH_COLUMN);
$fks = $pdo->query("
    SELECT
        tc.table_name, kcu.column_name,
        ccu.table_name AS foreign_table_name,
        ccu.column_name AS foreign_column_name 
    FROM 
        information_schema.table_constraints AS tc 
        JOIN information_schema.key_column_usage AS kcu
          ON tc.constraint_name = kcu.constraint_name
          AND tc.table_schema = kcu.table_schema
        JOIN information_schema.constraint_column_usage AS ccu
          ON ccu.constraint_name = tc.constraint_name
          AND ccu.table_schema = tc.table_schema
    WHERE tc.constraint_type = 'FOREIGN KEY' AND ccu.table_name IN ('uploads', 'upload_files')
")->fetchAll(PDO::FETCH_ASSOC);
echo "FKs referencing uploads or upload_files:\n";
print_r($fks);

// Check audit logs related to upload
try {
    $auditUploadCount = $pdo->query("SELECT count(*) FROM public.audit_logs WHERE module = 'Upload Centre' OR action LIKE '%UPLOAD%' OR action LIKE '%IMPORT%'")->fetchColumn();
    echo "Audit logs (Upload Centre): {$auditUploadCount} rows\n";
} catch (Exception $e) {
    echo "Audit logs check: " . $e->getMessage() . "\n";
}

// Also check customer DB if accessible
$admin = $pdo->query("SELECT * FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($admin) {
    $custContext = getUserCustomerDbContext($admin);
    $custPdo = $custContext['pdo'] ?? null;
    if ($custPdo) {
        echo "\nCustomer DB (" . ($custContext['database_name'] ?? 'unknown') . "):\n";
        $custTables = ['items', 'work_centers', 'party_master', 'cost_center_master', 'standard_cost', 'bom', 'routing', 'material_transactions', 'downtime', 'finance_transactions', 'trial_balance'];
        foreach ($custTables as $ct) {
            try {
                $c = $custPdo->query("SELECT count(*) FROM {$ct}")->fetchColumn();
                echo "  Customer Table {$ct}: {$c} rows\n";
            } catch (Exception $e) {
                // Table might not exist or be named differently
            }
        }
    }
}
