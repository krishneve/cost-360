<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$menus = $pdo->query("SELECT menu_id, menu_code, menu_name, route FROM menus ORDER BY menu_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($menus as $m) {
    echo "ID: {$m['menu_id']} | Code: {$m['menu_code']} | Name: {$m['menu_name']} | Route: {$m['route']}\n";
}
