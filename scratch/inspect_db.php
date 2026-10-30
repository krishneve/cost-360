<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "=== PUBLIC TABLES ===\n";
print_r($tables);
