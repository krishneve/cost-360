<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt1 = $pdo->query("
    SELECT rm.role_id, r.role_code, m.menu_code, m.menu_name 
    FROM role_menu rm 
    JOIN roles r ON r.role_id = rm.role_id 
    JOIN menus m ON m.menu_id = rm.menu_id 
    WHERE r.role_code IN ('CMA_ADMIN', 'PLATFORM_ADMIN', 'CUSTOMER_ADMIN')
");
echo "ROLE MENUS:\n";
print_r($stmt1->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query("SELECT menu_id, parent_menu_id, menu_code, menu_name, route, status FROM menus ORDER BY display_order ASC");
echo "\nALL MENUS:\n";
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
