<?php
require_once __DIR__ . '/../config/database.php';

$globalPdo = getDbConnection();
$custDbs = $globalPdo->query("SELECT * FROM customer_databases")->fetchAll(PDO::FETCH_ASSOC);

echo "Checking all customer databases for records...\n";
foreach ($custDbs as $cdb) {
    $cid = $cdb['customer_id'];
    $dbname = $cdb['database_name'];
    try {
        $cpdo = getCustomerDbConnection($cid);
        echo "Customer ID $cid ($dbname):\n";
        
        $tables = ['cost_center_master', 'trial_balance', 'finance_transaction', 'material_transaction'];
        foreach ($tables as $t) {
            $chk = $cpdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = '$t'")->fetch();
            if ($chk) {
                $cnt = $cpdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
                echo "  $t: $cnt rows\n";
            } else {
                echo "  $t: table does not exist\n";
            }
        }
    } catch (Exception $e) {
        echo "Customer ID $cid ($dbname): Connection failed: " . $e->getMessage() . "\n";
    }
}

echo "\n=== LOCAL CONVEYANCE IN SOURCE & DETAIL ===\n";
$src = $globalPdo->query("SELECT * FROM quantity_reco_source_transactions WHERE description ILIKE '%LOCAL CONVEYANCE%' AND plant_id = 38")->fetchAll(PDO::FETCH_ASSOC);
print_r($src);
$det = $globalPdo->query("SELECT d.*, c.cost_center_name FROM quantity_reco_detail d JOIN cost_center_master c ON d.cost_centre_id = c.id WHERE d.source_description ILIKE '%LOCAL CONVEYANCE%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($det);
$exc = $globalPdo->query("SELECT * FROM quantity_reco_exception WHERE source_description ILIKE '%LOCAL CONVEYANCE%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($exc);










