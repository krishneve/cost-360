<?php
require_once __DIR__ . '/../config/database.php';
$cpdo = getCustomerDbConnection('cost360_customer_cust001');

echo "trial_balance count: " . $cpdo->query("SELECT COUNT(*) FROM trial_balance")->fetchColumn() . "\n";
echo "finance_transaction count: " . $cpdo->query("SELECT COUNT(*) FROM finance_transaction")->fetchColumn() . "\n";
echo "overhead_ledger count: " . $cpdo->query("SELECT COUNT(*) FROM overhead_ledger")->fetchColumn() . "\n";
echo "cost_center_master count: " . $cpdo->query("SELECT COUNT(*) FROM cost_center_master")->fetchColumn() . "\n";

$rows = $cpdo->query("SELECT * FROM trial_balance LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

$ftRows = $cpdo->query("SELECT * FROM finance_transaction LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($ftRows);

