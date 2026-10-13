<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$cust57 = $pdo->query("SELECT * FROM customers WHERE customer_id = 57")->fetch(PDO::FETCH_ASSOC);
echo "=== Customer 57 in customers table ===\n";
print_r($cust57);

$mapping57 = $pdo->query("SELECT * FROM customer_databases WHERE customer_id = 57")->fetch(PDO::FETCH_ASSOC);
echo "\n=== Customer 57 in customer_databases table ===\n";
print_r($mapping57);
