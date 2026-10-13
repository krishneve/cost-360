<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getCustomerDbConnection('cost360_customer_cust001');

echo "=== Items in cost360_customer_cust001 ===\n";
$items = $pdo->query("SELECT item_code, item_name, created_at FROM items LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
print_r($items);

echo "=== Material transactions ===\n";
$txs = $pdo->query("SELECT doc_no, doc_date, item_code FROM material_transaction LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
print_r($txs);

echo "=== Standard cost ===\n";
$sc = $pdo->query("SELECT product_code, std_revision_date FROM standard_cost LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
print_r($sc);
