<?php
session_start();
$_SESSION['user_id'] = 3;
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

echo "isLoggedIn: " . (isLoggedIn() ? 'YES' : 'NO') . "\n";
echo "isPlatformAdmin: " . (isPlatformAdmin() ? 'YES' : 'NO') . "\n";
echo "isCmaAdmin: " . (isCmaAdmin() ? 'YES' : 'NO') . "\n";
echo "userHasMenuAccess(MAPPING_CENTRE): " . (userHasMenuAccess('MAPPING_CENTRE') ? 'YES' : 'NO') . "\n";
echo "userHasMenuAccess(MAPPING): " . (userHasMenuAccess('MAPPING') ? 'YES' : 'NO') . "\n";
echo "userHasMenuAccess(DATA_MANAGEMENT): " . (userHasMenuAccess('DATA_MANAGEMENT') ? 'YES' : 'NO') . "\n";

$menus = getUserAuthorizedMenus(3);
echo "User 3 menu codes:\n";
foreach ($menus as $m) {
    echo "- " . ($m['menu_code'] ?? '') . " | " . ($m['route'] ?? '') . "\n";
}
