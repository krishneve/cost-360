<?php
/**
 * Cost360 - User Table Migration Script
 * Adds enhanced user columns (username, first_name, last_name, status, etc.)
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();
    
    // Check which columns already exist in the public.users table
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users'");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $requiredColumns = [
        'username' => 'VARCHAR(150)',
        'first_name' => 'VARCHAR(100)',
        'last_name' => 'VARCHAR(100)',
        'status' => "VARCHAR(20) DEFAULT 'ACTIVE'",
        'is_email_verified' => "BOOLEAN DEFAULT FALSE",
        'failed_login_attempts' => "INT DEFAULT 0",
        'locked_until' => "TIMESTAMP",
        'last_login_at' => "TIMESTAMP"
    ];

    $missingCols = [];
    foreach ($requiredColumns as $col => $type) {
        if (!in_array($col, $existingColumns)) {
            $missingCols[$col] = $type;
        }
    }

    if (!empty($missingCols)) {
        $alterClauses = [];
        foreach ($missingCols as $col => $type) {
            $alterClauses[] = "ADD COLUMN IF NOT EXISTS {$col} {$type}";
        }
        $alterSql = "ALTER TABLE users " . implode(', ', $alterClauses) . ";";
        $pdo->exec($alterSql);
        echo "Added missing columns: " . implode(', ', array_keys($missingCols)) . "\n";
    } else {
        echo "All required columns (username, first_name, last_name, status, is_email_verified, failed_login_attempts, locked_until, last_login_at) already exist in table 'users'.\n";
    }
    
    $updateStmt = $pdo->prepare("
        UPDATE users SET 
            username = email,
            first_name = SPLIT_PART(full_name, ' ', 1),
            last_name = SPLIT_PART(full_name, ' ', 2),
            status = CASE WHEN is_active THEN 'ACTIVE' ELSE 'INACTIVE' END
        WHERE username IS NULL
    ");
    $updateStmt->execute();
    $updatedCount = $updateStmt->rowCount();

    echo "Data update complete: {$updatedCount} users updated with default values.\n";
    echo "Migration finished successfully.\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
