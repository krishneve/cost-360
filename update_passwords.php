<?php
/**
 * Update all user passwords to a hashed version of "12345678"
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

try {
    $pdo = getDbConnection();
    
    // Hash the password exactly as auth.php expects
    $plainPassword = '12345678';
    $hashedPassword = hashPassword($plainPassword);
    
    echo "Generated Hash: " . $hashedPassword . "\n";
    
    // Update the 'users' table 
    $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash');
    $stmt->execute([':hash' => $hashedPassword]);
    
    $count = $stmt->rowCount();
    echo "Successfully updated " . $count . " users with the new hashed password.\n";
    
    // Also, if you have an auth.users table locally, update it too
    // (login.php queries auth.users)
    try {
        $stmtAuth = $pdo->prepare('UPDATE auth.users SET password_hash = :hash');
        $stmtAuth->execute([':hash' => $hashedPassword]);
        $countAuth = $stmtAuth->rowCount();
        echo "Successfully updated " . $countAuth . " users in auth.users table.\n";
    } catch (Exception $e) {
        // auth.users doesn't exist locally, that's fine
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
