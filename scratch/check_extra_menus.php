<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT * FROM role_menu WHERE menu_id IN (27,28,29,30,31)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
