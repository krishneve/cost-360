<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$rm13 = $pdo->query("SELECT * FROM role_menu WHERE menu_id IN (13, 27)")->fetchAll(PDO::FETCH_ASSOC);
print_r($rm13);
