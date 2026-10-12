<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$rows = $pdo->query("SELECT * FROM customer_databases")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
