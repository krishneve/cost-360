<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$r = $pdo->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);
print_r($r);
$rm = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'role_menu'")->fetchAll(PDO::FETCH_COLUMN);
print_r($rm);
