<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$dbs = $pdo->query("SELECT customer_id, database_name FROM customer_databases WHERE UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_ASSOC);

$worksheetTables = [
    'items', 'work_centers', 'party_master', 'cost_center_master',
    'standard_cost', 'bom', 'routing', 'material_transaction',
    'downtime', 'finance_transaction', 'trial_balance'
];

foreach ($dbs as $d) {
    $dbName = $d['database_name'];
    echo "Checking {$dbName} (Cust ID {$d['customer_id']}):\n";
    try {
        $cpdo = getCustomerDbConnection($dbName);
        foreach ($worksheetTables as $wt) {
            try {
                $c = $cpdo->query("SELECT count(*) FROM {$wt}")->fetchColumn();
                if ($c > 0) {
                    echo "  -> {$wt}: {$c} rows\n";
                }
            } catch (Exception $e) {
                // table may not exist
            }
        }
    } catch (Exception $e) {
        echo "  Connection failed: " . $e->getMessage() . "\n";
    }
}
