<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT menu_id, menu_code, menu_name, route, menu_type, status FROM menus ORDER BY display_order, menu_id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
