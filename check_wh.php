<?php
require 'c:/xampp/htdocs/cost-360-main/config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'warehouses'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
