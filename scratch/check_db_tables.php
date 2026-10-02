<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
    echo "Connected successfully to central DB.\n\n";

    // Query tables
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo "--- TABLES IN CENTRAL DB (" . count($tables) . ") ---\n";
    foreach ($tables as $t) {
        echo " - " . $t . "\n";
    }

} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
