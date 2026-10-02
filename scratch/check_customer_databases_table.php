<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
    echo "--- COLUMNS IN customer_databases ---\n";
    $cols = $pdo->query("
        SELECT column_name, data_type, is_nullable 
        FROM information_schema.columns 
        WHERE table_name = 'customer_databases' 
        ORDER BY ordinal_position
    ")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);

    echo "\n--- ROWS IN customer_databases ---\n";
    $rows = $pdo->query("SELECT * FROM customer_databases")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
