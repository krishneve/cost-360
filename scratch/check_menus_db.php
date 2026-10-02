<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
echo "--- ALL MENUS IN CENTRAL DB ---\n";
$menus = $pdo->query("SELECT menu_id, parent_menu_id, menu_code, menu_name, route, display_order, status FROM menus ORDER BY display_order ASC, menu_id ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($menus as $m) {
    echo "ID: {$m['menu_id']} | Parent: {$m['parent_menu_id']} | Code: {$m['menu_code']} | Name: {$m['menu_name']} | Route: {$m['route']} | Status: {$m['status']}\n";
}

echo "\n--- ROLE MENU MAPPINGS ---\n";
$rm = $pdo->query("SELECT rm.role_menu_id, r.role_name, m.menu_name, rm.can_view, rm.can_upload, rm.is_active FROM role_menu rm JOIN roles r ON rm.role_id = r.role_id JOIN menus m ON rm.menu_id = m.menu_id ORDER BY r.role_id, m.menu_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rm as $row) {
    echo "Role: {$row['role_name']} | Menu: {$row['menu_name']} | can_view: " . ($row['can_view'] ? 'YES' : 'NO') . " | active: " . ($row['is_active'] ? 'YES' : 'NO') . "\n";
}
