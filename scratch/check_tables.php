<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
echo "--- GLOBAL DB TABLES ---\n";
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
echo implode(", ", $tables) . "\n\n";

echo "--- CUSTOMER DATABASES ---\n";
$custDbs = $pdo->query("SELECT * FROM customer_databases")->fetchAll(PDO::FETCH_ASSOC);
foreach ($custDbs as $cdb) {
    echo "DB: {$cdb['database_name']} (Customer ID: {$cdb['customer_id']})\n";
    try {
        $cpdo = getCustomerDbConnection($cdb['database_name']);
        $ctables = $cpdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        echo "  Tables: " . implode(", ", $ctables) . "\n";
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}

