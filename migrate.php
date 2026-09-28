<?php
/**
 * Run database migrations
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();
    
    $sql = file_get_contents(__DIR__ . '/database/schema.sql');
    
    if (!$sql) {
        die("Could not read schema.sql file.\n");
    }

    echo "Running migrations...\n";
    
    // Execute the SQL queries
    $pdo->exec($sql);
    
    echo "Migrations executed successfully.\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
