<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT * FROM customer_databases");
$dbs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== CUSTOMER DATABASES ===\n";
print_r($dbs);

$stmt = $pdo->query("SELECT schema_name FROM information_schema.schemata");
$schemas = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "=== SCHEMAS ===\n";
print_r($schemas);
