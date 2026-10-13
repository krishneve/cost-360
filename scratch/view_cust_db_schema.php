<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'customer_databases'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
