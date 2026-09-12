<?php
/**
 * Cost360 - Neon Auth Diagnostic Utility (Development Only)
 * Confirms existing user records in Neon PostgreSQL auth.users table.
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();

    // Query existing Neon auth.users table
    $stmt = $pdo->query("SELECT user_id, username, email, first_name, last_name, status, created_at FROM auth.users ORDER BY user_id ASC");
    $users = $stmt->fetchAll();

    echo "Neon Auth Users Found (" . count($users) . "):\n";
    echo "=========================================\n";
    foreach ($users as $u) {
        echo "ID: {$u['user_id']} | Email: {$u['email']} | Username: {$u['username']} | Name: {$u['first_name']} {$u['last_name']} | Status: {$u['status']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
