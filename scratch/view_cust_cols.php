<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'customers'")->fetchAll(PDO::FETCH_COLUMN);
print_r($cols);
