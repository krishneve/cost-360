<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
try {
    $pdo->exec("CREATE SCHEMA IF NOT EXISTS cust_test_schema;");
    echo "Schema creation SUCCESS!\n";
    $pdo->exec("DROP SCHEMA cust_test_schema;");
} catch (Exception $e) {
    echo "Schema creation ERROR: " . $e->getMessage() . "\n";
}
