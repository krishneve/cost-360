<?php
/**
 * Run any SQL script file
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config/database.php';

// =========================================================
// CHANGE THIS FILENAME TO RUN DIFFERENT SQL FILES
// =========================================================
$filename = 'database/sample_customer.sql'; 
// =========================================================

try {
    $pdo = getDbConnection();
    
    if (isset($argv[1]) && !empty($argv[1])) {
        $filename = $argv[1];
    } elseif (isset($_GET['file']) && !empty($_GET['file'])) {
        $filename = $_GET['file'];
    }

    $filepath = __DIR__ . '/' . $filename;
    
    if (!file_exists($filepath)) {
        die("Error: File not found: " . $filename . "\n");
    }

    $sql = file_get_contents($filepath);
    
    if (!$sql) {
        die("Error: Could not read file content.\n");
    }

    echo "Executing SQL from: " . $filename . " ...\n";
    
    // Execute the SQL queries
    $pdo->exec($sql);
    
    echo "Successfully executed " . $filename . "!\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
