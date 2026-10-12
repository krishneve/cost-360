<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$dbs = $pdo->query("SELECT datname FROM pg_database WHERE datname LIKE 'cust_%' OR datname IN ('cost360-neon-local', 'cost360-local') ORDER BY datname")->fetchAll(PDO::FETCH_COLUMN);
echo "Databases in PostgreSQL:\n";
print_r($dbs);