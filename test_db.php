<?php
/**
 * Cost360 - Database Connection Test Script
 * Verifies PDO PostgreSQL connectivity to Neon database.
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();
    
    // Execute simple query to verify connection
    $stmt = $pdo->query('SELECT 1');
    if ($stmt) {
        echo "Neon database connected successfully.\n";
    } else {
        echo "Database query test failed.\n";
    }
} catch (Exception $e) {
    echo "Connection test failed: " . $e->getMessage() . "\n";
}
