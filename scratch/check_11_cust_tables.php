<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getCustomerDbConnection('cost360_customer_cust001');

$worksheetTables = [
    'items',
    'work_centers',
    'party_master',
    'cost_center_master',
    'standard_cost',
    'bom',
    'routing',
    'material_transaction',
    'downtime',
    'finance_transaction',
    'trial_balance'
];

echo "All 11 Worksheet Target Tables in cost360_customer_cust001:\n";
echo "--------------------------------------------------------\n";
foreach ($worksheetTables as $wt) {
    try {
        $c = $pdo->query("SELECT count(*) FROM {$wt}")->fetchColumn();
        echo sprintf("%-25s : %d rows\n", $wt, $c);
    } catch (Exception $e) {
        echo sprintf("%-25s : ERROR (%s)\n", $wt, $e->getMessage());
    }
}
