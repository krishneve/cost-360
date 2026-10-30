<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();
$u = $pdo->query("SELECT user_id, email, full_name, category_id FROM users WHERE is_active = TRUE LIMIT 1")->fetch(PDO::FETCH_ASSOC);

function testApiAction($action, $params = []) {
    global $u;
    $params['action'] = $action;
    $script = "
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        \$_SESSION['user_id'] = {$u['user_id']};
        \$_SESSION['role_code'] = 'PLAT_ADMIN';
        \$_SESSION['user'] = " . var_export($u, true) . ";
        \$_GET = " . var_export($params, true) . ";
        \$_POST = \$_GET;
        \$_REQUEST = \$_GET;
        ob_start();
        require __DIR__ . '/../api/quantity_reco_api.php';
        echo ob_get_clean();
    ";
    
    $tmpFile = __DIR__ . '/tmp_test_api_p3.php';
    file_put_contents($tmpFile, "<?php\n" . $script);
    $output = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($tmpFile));
    @unlink($tmpFile);
    return json_decode($output, true);
}

echo "=== TESTING PHASE 3 API ENDPOINTS ===\n";

// 1. import_plant_excel
$r1 = testApiAction('import_plant_excel', ['plant_id' => 38, 'period_id' => 1, 'replace' => 1]);
echo "1. import_plant_excel: ok=" . ($r1['ok'] ? 'true' : 'false') . ", count=" . ($r1['count'] ?? 0) . ", file_hash=" . substr($r1['file_hash'] ?? '', 0, 12) . "...\n";

// 2. generate
$r2 = testApiAction('generate', ['plant_id' => 38, 'period_id' => 1]);
echo "2. generate: ok=" . ($r2['ok'] ? 'true' : 'false') . ", status=" . ($r2['summary']['status'] ?? '') . ", mapped=" . ($r2['summary']['mapped_records_count'] ?? 0) . "\n";

// 3. reconcile_excel
$r3 = testApiAction('reconcile_excel', ['plant_id' => 38, 'period_id' => 1]);
echo "3. reconcile_excel: ok=" . ($r3['ok'] ? 'true' : 'false') . ", status=" . ($r3['status'] ?? '') . ", matched_rows=" . ($r3['level_1_row_count']['matched'] ?? 0) . ", closing_matches=" . ($r3['level_3_closing']['matched'] ?? 0) . "\n";

// 4. sync_tb
$r4 = testApiAction('sync_tb', ['plant_id' => 38, 'period_id' => 1]);
echo "4. sync_tb: ok=" . ($r4['ok'] ? 'true' : 'false') . ", msg=" . ($r4['message'] ?? '') . "\n";

echo "=== PHASE 3 API ENDPOINTS TEST COMPLETE! ===\n";
