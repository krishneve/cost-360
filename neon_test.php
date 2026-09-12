<?php
/**
 * Cost360 - Temporary Development-Only Verification Page
 * Safely confirms Neon PostgreSQL database connectivity and auth.users lookup.
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();

    // Mask host for safety
    $rawHost = getenv('PGHOST') ?: 'ep-super-rain-a5zoqjy7-pooler.us-east-2.aws.neon.tech';
    $hostParts = explode('.', $rawHost);
    $maskedHost = $hostParts[0] . '.***.' . implode('.', array_slice($hostParts, 2));

    $dbname = getenv('PGDATABASE') ?: 'code-360-v1';

    // Verify auth.users table accessibility
    $tableStmt = $pdo->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'auth' AND table_name = 'users')");
    $tableExists = $tableStmt->fetchColumn();

    // Verify existing user lookup from auth.users
    $userStmt = $pdo->prepare("SELECT user_id, email, username FROM auth.users LIMIT 1");
    $userStmt->execute();
    $userFound = $userStmt->fetch();

    echo "Database connection: SUCCESS\n";
    echo "Database name: " . htmlspecialchars($dbname) . "\n";
    echo "Database host: " . htmlspecialchars($maskedHost) . "\n";
    echo "Neon auth.users table accessible: " . ($tableExists ? "YES" : "NO") . "\n";
    echo "Existing Neon Auth user found: " . ($userFound ? "YES (" . $userFound['email'] . ")" : "NO") . "\n";

} catch (Exception $e) {
    echo "Database connection: FAILED (" . $e->getMessage() . ")\n";
}
