<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$dbs = $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname")->fetchAll(PDO::FETCH_COLUMN);
echo "Existing non-template PostgreSQL databases on server:\n";
print_r($dbs);
