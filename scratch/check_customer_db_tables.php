<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

try {
    // Attempt to connect to Customer 1 database handle
    $custPdo = getCustomerDbConnection(1, 1);
    echo "Successfully connected to Customer 1 Database handle!\n\n";

    $tables = $custPdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo "--- TABLES IN CUSTOMER DB (" . count($tables) . ") ---\n";
    foreach ($tables as $t) {
        echo " - " . $t . "\n";
    }

} catch (Exception $e) {
    echo "Customer DB Connection Info / Fallback: " . $e->getMessage() . "\n";
}
