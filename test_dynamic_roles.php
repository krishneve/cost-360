<?php
require_once __DIR__ . '/config/database.php';

function testUserDashboardRender($userId) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = $userId;
    
    ob_start();
    require __DIR__ . '/dashboard.php';
    $html = ob_get_clean();
    
    echo "=========================================\n";
    echo "TEST USER ID: {$userId}\n";
    echo "=========================================\n";
    echo "HTML Length: " . strlen($html) . " bytes\n";
    
    preg_match('/<div class="user-name">(.*?)<\/div>/s', $html, $m1);
    echo "Displayed Name: " . trim(strip_tags($m1[1] ?? 'N/A')) . "\n";

    preg_match('/<span class="role-badge">(.*?)<\/span>/s', $html, $m2);
    echo "Displayed Role: " . trim(strip_tags($m2[1] ?? 'N/A')) . "\n";

    preg_match_all('/<a href="[^"]*" class="nav-item[^"]*">(.*?)<\/a>/s', $html, $m3);
    echo "Displayed Sidebar Menus (" . count($m3[1]) . "):\n";
    foreach ($m3[1] as $item) {
        echo "  * " . trim(preg_replace('/\s+/', ' ', strip_tags($item))) . "\n";
    }
    echo "\n";
}

testUserDashboardRender(2); // admin@cost360.local
testUserDashboardRender(3); // chanchal@gmail.com
