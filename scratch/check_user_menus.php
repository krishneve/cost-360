<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$users = [1, 2, 3, 4, 5, 6, 7, 8];
foreach ($users as $uid) {
    $menus = getUserAuthorizedMenus($uid);
    $names = array_column($menus, 'menu_name');
    echo "User ID {$uid}: " . implode(' | ', $names) . "\n";
}
