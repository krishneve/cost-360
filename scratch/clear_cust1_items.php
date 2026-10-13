<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getCustomerDbConnection('cost360_customer_cust001');

echo "Clearing items table in cost360_customer_cust001...\n";
$pdo->exec("TRUNCATE TABLE public.items RESTART IDENTITY CASCADE");

$cnt = $pdo->query("SELECT count(*) FROM public.items")->fetchColumn();
echo "Verified row count of items in cost360_customer_cust001: {$cnt} rows\n";
