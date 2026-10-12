<?php
require_once __DIR__ . '/../config/database.php';
try {
    $pdo = getDbConnection();
    echo "Successfully connected to global database!\n";
    $currUser = $pdo->query("SELECT current_user")->fetchColumn();
    echo "Current user is: $currUser\n";
} catch (Throwable $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
}
