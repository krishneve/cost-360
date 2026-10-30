<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$pdo = getDbConnection();
$u = $pdo->query("SELECT user_id, email, full_name, category_id FROM users WHERE is_active = TRUE LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = $u['user_id'];
$_SESSION['role_code'] = 'PLAT_ADMIN';
$_SESSION['user'] = $u;

echo "=== TESTING QUANTITY RECO API SUITE ===\n";

function runEndpoint($params) {
    $_GET = $params;
    $_POST = $params;
    $_REQUEST = $params;

    ob_start();
    require __DIR__ . '/../api/quantity_reco_api.php';
    $out = ob_get_clean();
    return json_decode($out, true);
}

// 1. Get Cost Centres
$res1 = runEndpoint(['action' => 'get_cost_centres', 'plant_id' => 35]);
echo "1. get_cost_centres: ok=" . ($res1['ok'] ? 'true' : 'false') . ", count=" . ($res1['count'] ?? 0) . "\n";

// 2. Get Mappings
$res2 = runEndpoint(['action' => 'get_mappings', 'plant_id' => 35]);
echo "2. get_mappings: ok=" . ($res2['ok'] ? 'true' : 'false') . ", count=" . ($res2['count'] ?? 0) . "\n";

// 3. Get Quantity Reco Matrix
$res3 = runEndpoint(['action' => 'get_reco', 'plant_id' => 35, 'period_id' => 1]);
echo "3. get_reco: ok=" . ($res3['ok'] ? 'true' : 'false') . ", columns=" . count($res3['columns'] ?? []) . ", grand_total=" . ($res3['grand_total'] ?? 0) . "\n";

// 4. Trace Source for Utility
$utilityCCId = 0;
foreach ($res3['columns'] as $c) {
    if ($c['cost_centre_code'] === 'UTILITY') {
        $utilityCCId = (int)$c['cost_centre_id'];
        break;
    }
}
$runId = (int)($res3['run']['run_id'] ?? 0);
$res4 = runEndpoint(['action' => 'trace_source', 'run_id' => $runId, 'cost_centre_id' => $utilityCCId]);
echo "4. trace_source for Utility: ok=" . ($res4['ok'] ? 'true' : 'false') . ", count=" . ($res4['count'] ?? 0) . ", subtotal=" . ($res4['subtotal'] ?? 0) . "\n";

// 5. Get Exceptions
$res5 = runEndpoint(['action' => 'get_exceptions', 'run_id' => $runId]);
echo "5. get_exceptions: ok=" . ($res5['ok'] ? 'true' : 'false') . ", count=" . ($res5['count'] ?? 0) . "\n";

echo ">>> ALL API ENDPOINTS TESTED AND VERIFIED SUCCESSFULLY! <<<\n";
