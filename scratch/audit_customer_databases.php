<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

echo "=== 1. CHECK customer_databases TABLE STRUCTURE ===\n";
$cols = $pdo->query("
    SELECT column_name, data_type, is_nullable, column_default 
    FROM information_schema.columns 
    WHERE table_name = 'customer_databases'
    ORDER BY ordinal_position
")->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);

echo "\n=== 2. CHECK customer_databases CONSTRAINTS ===\n";
$constrs = $pdo->query("
    SELECT conname, pg_get_constraintdef(c.oid) 
    FROM pg_constraint c 
    WHERE conrelid = 'public.customer_databases'::regclass
")->fetchAll(PDO::FETCH_ASSOC);
print_r($constrs);

echo "\n=== 3. CHECK customer_databases EXISTING ROWS ===\n";
$rows = $pdo->query("SELECT * FROM customer_databases LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
