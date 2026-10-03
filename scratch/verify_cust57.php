<?php
require_once __DIR__ . '/../config/database.php';

try {
    $custPdo = getCustomerDbConnection('cust_57');
    $stmt = $custPdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "cust_57 Tables: " . implode(', ', $tables) . PHP_EOL;

    // Test items table structure
    $stmtCols = $custPdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'items'");
    $cols = $stmtCols->fetchAll(PDO::FETCH_ASSOC);
    echo "items table columns:" . PHP_EOL;
    print_r($cols);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
