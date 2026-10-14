<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$dbs = $pdo->query('SELECT customer_id, database_name, status FROM customer_databases')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($dbs, JSON_PRETTY_PRINT) . PHP_EOL;
