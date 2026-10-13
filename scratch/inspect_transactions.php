<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'transactions'")->fetchAll(PDO::FETCH_COLUMN);
echo "Columns of transactions:\n";
print_r($cols);

$rows = $pdo->query("SELECT * FROM transactions LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "Sample rows of transactions:\n";
print_r($rows);
