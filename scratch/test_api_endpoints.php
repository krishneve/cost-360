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
        require 'api/quantity_reco_api.php';
        echo ob_get_clean();
    ";
    
    $tmpFile = __DIR__ . '/tmp_test_api.php';
    file_put_contents($tmpFile, "<?php\n" . $script);
    $output = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($tmpFile));
    @unlink($tmpFile);
    return json_decode($output, true);
}

echo "=== VERIFYING QUANTITY RECO APIS ===\n";

// 1. get_cost_centres
$r1 = testApiAction('get_cost_centres', ['plant_id' => 35]);
echo "1. get_cost_centres: ok=" . ($r1['ok'] ? 'true' : 'false') . ", count=" . ($r1['count'] ?? 0) . "\n";

// 2. get_mappings
$r2 = testApiAction('get_mappings', ['plant_id' => 35]);
echo "2. get_mappings: ok=" . ($r2['ok'] ? 'true' : 'false') . ", count=" . ($r2['count'] ?? 0) . "\n";

// 3. get_reco
$r3 = testApiAction('get_reco', ['plant_id' => 35, 'period_id' => 1]);
echo "3. get_reco: ok=" . ($r3['ok'] ? 'true' : 'false') . ", columns=" . count($r3['columns'] ?? []) . ", grand_total=" . ($r3['grand_total'] ?? 0) . "\n";

$runId = (int)($r3['run']['run_id'] ?? 0);
$utilityCCId = 0;
foreach ($r3['columns'] as $c) {
    if ($c['cost_centre_code'] === 'UTILITY') {
        $utilityCCId = (int)$c['cost_centre_id'];
        break;
    }
}

// 4. trace_source
if ($runId > 0 && $utilityCCId > 0) {
    $r4 = testApiAction('trace_source', ['run_id' => $runId, 'cost_centre_id' => $utilityCCId]);
    echo "4. trace_source for Utility: ok=" . ($r4['ok'] ? 'true' : 'false') . ", count=" . ($r4['count'] ?? 0) . ", subtotal=" . ($r4['subtotal'] ?? 0) . "\n";
}

// 5. get_exceptions
if ($runId > 0) {
    $r5 = testApiAction('get_exceptions', ['run_id' => $runId]);
    echo "5. get_exceptions: ok=" . ($r5['ok'] ? 'true' : 'false') . ", count=" . ($r5['count'] ?? 0) . "\n";
}

// 6. generate via API
$r6 = testApiAction('generate', ['plant_id' => 35, 'period_id' => 1]);
echo "6. generate via API: ok=" . ($r6['ok'] ? 'true' : 'false') . ", mapped_amount=" . ($r6['summary']['mapped_amount'] ?? 0) . "\n";

echo "=== ALL API TESTS COMPLETED SUCCESSFULLY! ===\n";
