<?php
/**
 * Cost360 - CMA Admin Foundation Database Migration
 */
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();
    echo "Connected to database successfully.\n";

    $sql = file_get_contents(__DIR__ . '/database/cma_admin_foundation_schema.sql');
    if (!$sql) {
        throw new Exception("Could not read database/cma_admin_foundation_schema.sql");
    }

    echo "Executing CMA Admin Foundation migrations...\n";
    $pdo->exec($sql);
    echo "Migration completed successfully!\n";

    // Verify
    echo "\n--- Verification ---\n";
    $cmaRole = $pdo->query("SELECT role_id, role_code, role_name, status FROM roles WHERE role_code IN ('CMA_ADMIN', 'CMA_USER')")->fetchAll(PDO::FETCH_ASSOC);
    echo "Roles:\n";
    print_r($cmaRole);

    $menus = $pdo->query("SELECT menu_id, menu_code, menu_name, route, parent_menu_id FROM menus WHERE menu_code LIKE 'CMA_%' ORDER BY menu_id")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nCMA Menus:\n";
    print_r($menus);

    $cmaPerms = $pdo->query("SELECT m.menu_code, rm.can_view, rm.can_create FROM role_menu rm JOIN menus m ON m.menu_id = rm.menu_id WHERE rm.role_id = 2 AND rm.is_active = TRUE ORDER BY m.menu_code")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nActive Menus for CMA_ADMIN (role_id=2):\n";
    print_r($cmaPerms);

} catch (Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
