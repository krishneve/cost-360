<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

session_start();
$_SESSION['user_id'] = 3;
$_SESSION['username'] = 'aarav.cma@cost360.com';
$_SESSION['active_customer_id'] = 1;
$_SESSION['active_company_id'] = 2;
$_SESSION['active_company_code'] = 'ABC02';

$user = getAuthUser();

for ($fileId = 1; $fileId <= 14; $fileId++) {
    $res = getFilePreviewData($fileId, $user);
    echo "File $fileId: " . ($res['ok'] ? 'OK' : 'FAILED - ' . ($res['message'] ?? '')) . "\n";
    if ($res['ok']) {
        echo "  Table: {$res['target_table']} | Headers: " . count($res['headers']) . " | Data: " . count($res['data']) . " | TargetCols: " . count($res['target_columns']) . "\n";
    }
}
