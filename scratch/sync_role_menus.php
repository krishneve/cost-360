<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$roles = [1, 2, 3, 4, 7]; // Admin, CMA Admin, Cust Mgmt, Cust Admin, CMA User

foreach ($roles as $rId) {
    foreach ([27, 28, 29, 30] as $mId) {
        $stmt = $pdo->prepare("INSERT INTO role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active) VALUES (:rid, :mid, TRUE, TRUE, TRUE, FALSE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE) ON CONFLICT DO NOTHING");
        $stmt->execute([':rid' => $rId, ':mid' => $mId]);
    }
}
echo "Role menu sync completed successfully.\n";
