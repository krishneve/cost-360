<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
$roles = $pdo->query("SELECT role_id, role_name FROM roles")->fetchAll(PDO::FETCH_ASSOC);
foreach ($roles as $r) {
    $rid = $r['role_id'];
    $stmt = $pdo->prepare("
        SELECT m.menu_name, m.menu_code, rm.can_view, rm.can_upload 
        FROM role_menu rm 
        JOIN menus m ON rm.menu_id = m.menu_id 
        WHERE rm.role_id = :rid AND (LOWER(m.menu_code) LIKE '%upload%' OR LOWER(m.menu_name) LIKE '%upload%')
    ");
    $stmt->execute([':rid' => $rid]);
    $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Role: {$r['role_name']} (ID {$rid}):\n";
    if (!empty($perms)) {
        foreach ($perms as $p) {
            echo "  - Menu: {$p['menu_name']} ({$p['menu_code']}) | can_view: " . ($p['can_view'] ? 'YES' : 'NO') . " | can_upload: " . ($p['can_upload'] ? 'YES' : 'NO') . "\n";
        }
    } else {
        echo "  - No upload menu mappings found.\n";
    }
}
