<?php
/**
 * Add missing columns to users table
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();
    
    $sql = "
    ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(150);
    ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(100);
    ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(100);
    ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ACTIVE';
    ALTER TABLE users ADD COLUMN IF NOT EXISTS is_email_verified BOOLEAN DEFAULT FALSE;
    ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts INT DEFAULT 0;
    ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until TIMESTAMP;
    ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP;
    
    UPDATE users SET 
        username = email,
        first_name = SPLIT_PART(full_name, ' ', 1),
        last_name = SPLIT_PART(full_name, ' ', 2),
        status = CASE WHEN is_active THEN 'ACTIVE' ELSE 'INACTIVE' END
    WHERE username IS NULL;
    ";
    
    $pdo->exec($sql);
    echo "Successfully updated users table schema and populated default values for existing users.\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
