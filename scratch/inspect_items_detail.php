<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getCustomerDbConnection('cost360_customer_cust001');

$cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'items' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
echo "Columns of items in cost360_customer_cust001:\n";
print_r($cols);

$rows = $pdo->query("SELECT * FROM items")->fetchAll(PDO::FETCH_ASSOC);
echo "\nAll rows in items in cost360_customer_cust001:\n";
print_r($rows);
