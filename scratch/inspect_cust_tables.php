<?php
require_once __DIR__ . '/../config/database.php';
$centralPdo = getDbConnection();
$dbs = $centralPdo->query("SELECT * FROM customer_databases WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);

foreach ($dbs as $db) {
    echo "--- DB: " . $db['database_name'] . " (Customer ID: " . $db['customer_id'] . ") ---\n";
    try {
        $pdo = getCustomerDbConnection($db['database_name']);
        $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['trial_balance', 'finance_transaction', 'overhead_ledger', 'material_transaction'] as $t) {
            if (in_array($t, $tables)) {
                $cnt = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
                echo "  $t: $cnt rows\n";
            }
        }
    } catch (Exception $e) {
        echo "  Could not connect: " . $e->getMessage() . "\n";
    }
}


