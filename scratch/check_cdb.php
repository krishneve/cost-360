<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$rows = $pdo->query("SELECT customer_id, database_name, status FROM customer_databases")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
