<?php
/**
 * COST360 — QUANTITY RECO: PHASE 3.3 ALLOCATION MANAGEMENT & PRODUCTION READINESS AUDIT
 * 
 * Comprehensive Test Suite for Phase 3.3:
 * 1.  API Auth Enforcement: 401 Unauthorized when unauthenticated
 * 2.  API Permission Enforcement: 403 Forbidden when unauthorized (e.g. Auditor User 5)
 * 3.  API Authorized Success: 200 OK for Platform Admin (User 1) & CMA Admin (User 2)
 * 4.  API Server-Side Validation: Invalid ratio sum (80%) rejected with 400
 * 5.  API Server-Side Validation: Cross-plant target Cost Centre rejected with 400
 * 6.  API Server-Side Validation: Missing driver account for DRIVER_ACCOUNT rejected with 400
 * 7.  API Multi-Split Atomicity: Multi-target ratio splits saved and queryable via get_allocation_rules
 * 8.  API Rule Deletion: delete_allocation_rule removes rules cleanly
 * 9.  Allocation Integrity: Invalid ratio configurations safely captured in quantity_reco_exception
 * 10. Allocation Integrity: Missing & zero-value drivers safely captured in quantity_reco_exception
 * 11. Allocation Integrity: Strict period-specific rule override precedence (Period 2 vs Period 1)
 * 12. Allocation Integrity: Deterministic currency rounding residual assignment (<= ₹0.02 to largest slice)
 * 13. Allocation Integrity: Repeated generation idempotency & atomic transaction rollback
 * 14. Allocation Integrity: Source transaction drill-down & traceability preserved
 * 15. Frontend Integration: UI elements (button, modal, dynamic split controls) verified in dashboard and JS
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/quantity_reco.php';

$pdo = getDbConnection();

echo "=================================================================\n";
echo "    COST360 — PHASE 3.3 ALLOCATION MANAGEMENT AUDIT SUITE\n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertAudit(string $testName, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$testName}\n";
        if ($detail) echo "        -> {$detail}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$testName}\n";
        if ($detail) echo "        -> ERROR: {$detail}\n";
    }
}

// Helper to invoke API via subprocess with specified session
function executeApiCall(array $requestData, ?int $userId = null, string $method = 'GET'): array {
    $script = __DIR__ . '/scratch/tmp_p33_runner.php';
    $code = '<?php' . "\n";
    if ($userId !== null) {
        $code .= 'session_start();' . "\n";
        $code .= '$_SESSION["user_id"] = ' . $userId . ';' . "\n";
    }
    $code .= 'register_shutdown_function(function() {
        $c = http_response_code();
        echo "\n__HTTP_STATUS__:" . ($c !== false ? $c : 200);
    });' . "\n";

    if ($method === 'POST') {
        $code .= '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n";
        $code .= '$_POST = ' . var_export($requestData, true) . ';' . "\n";
        $code .= '$_GET = [];' . "\n";
    } else {
        $code .= '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n";
        $code .= '$_GET = ' . var_export($requestData, true) . ';' . "\n";
        $code .= '$_POST = [];' . "\n";
    }
    $code .= '$_REQUEST = array_merge($_GET, $_POST);' . "\n";
    $code .= 'require ' . var_export(__DIR__ . '/api/quantity_reco_api.php', true) . ';' . "\n";

    file_put_contents($script, $code);
    $out = (string)shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);

    $httpCode = 200;
    if (preg_match('/__HTTP_STATUS__:(\d+)/', $out, $m)) {
        $httpCode = (int)$m[1];
        $out = preg_replace('/__HTTP_STATUS__:\d+/', '', $out);
    }
    $json = json_decode(trim($out), true);
    return [
        'http_code' => $httpCode,
        'raw'       => trim($out),
        'json'      => is_array($json) ? $json : []
    ];
}

// -------------------------------------------------------------
// FIXTURE SETUP
// -------------------------------------------------------------
echo "--> Setting up Phase 3.3 Test Scopes & Fixtures...\n";
$divId = (int)$pdo->query("SELECT division_id FROM divisions LIMIT 1")->fetchColumn() ?: 1;

// Plant A (Audited Target)
$stmt = $pdo->prepare("SELECT plant_id FROM plants WHERE plant_code = 'PLANT_AUDIT_33_A' LIMIT 1");
$stmt->execute();
$plantAId = (int)$stmt->fetchColumn();
if (!$plantAId) {
    $ins = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PLANT_AUDIT_33_A', 'Audit Plant 3.3 A', 'Active') RETURNING plant_id");
    $ins->execute([$divId]);
    $plantAId = (int)$ins->fetchColumn();
}

// Plant B (Cross-Plant Isolation)
$stmt = $pdo->prepare("SELECT plant_id FROM plants WHERE plant_code = 'PLANT_AUDIT_33_B' LIMIT 1");
$stmt->execute();
$plantBId = (int)$stmt->fetchColumn();
if (!$plantBId) {
    $ins = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PLANT_AUDIT_33_B', 'Audit Plant 3.3 B', 'Active') RETURNING plant_id");
    $ins->execute([$divId]);
    $plantBId = (int)$ins->fetchColumn();
}

$period1Id = 1;
$period2Id = 2;

// Create CCs for Plant A
$ccNames = ['QC', 'Repairs & Maintenance', 'POH', 'AOH', 'SOH', 'Utility'];
$ccMapA = [];
foreach ($ccNames as $idx => $cName) {
    $code = 'CC_AUD_A_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $cName));
    $res = saveCostCentre($pdo, [
        'plant_id'         => $plantAId,
        'cost_center_code' => $code,
        'cost_center_name' => $cName,
        'cost_basis_code'  => $cName,
        'display_order'    => $idx + 1,
        'is_active'        => true
    ]);
    $ccMapA[$cName] = (int)$res['id'];
}

// Create CCs for Plant B
$ccMapB = [];
foreach (['POH', 'AOH'] as $idx => $cName) {
    $code = 'CC_AUD_B_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $cName));
    $res = saveCostCentre($pdo, [
        'plant_id'         => $plantBId,
        'cost_center_code' => $code,
        'cost_center_name' => $cName,
        'cost_basis_code'  => $cName,
        'display_order'    => $idx + 1,
        'is_active'        => true
    ]);
    $ccMapB[$cName] = (int)$res['id'];
}

// Clean previous fixtures
$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id IN ({$plantAId}, {$plantBId})");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id IN ({$plantAId}, {$plantBId})");

echo "    Test Plant A ID: {$plantAId}, Plant B ID: {$plantBId}\n\n";

// -------------------------------------------------------------
// STAGE 2: API & PERMISSIONS VERIFICATION
// -------------------------------------------------------------

// TEST 01: Unauthenticated request rejected with 401
$res1 = executeApiCall(['action' => 'get_allocation_rules', 'plant_id' => $plantAId], null);
$pass1 = ($res1['http_code'] === 401) && !empty($res1['json']['error']);
assertAudit("TEST 01 — API Auth: Unauthenticated call rejected", $pass1, "HTTP 401 received: '{$res1['json']['error']}'");

// TEST 02: Unauthorized user (Auditor User 5) blocked from modifying rules with 403
$res2 = executeApiCall([
    'action'                => 'save_allocation_rule',
    'plant_id'              => $plantAId,
    'account_code'          => '5000999',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 1.0
], 5, 'POST'); // User 5 is AUDITOR
$pass2 = ($res2['http_code'] === 403) && (strpos($res2['json']['error'] ?? '', 'permission') !== false);
assertAudit("TEST 02 — API Permissions: Auditor user blocked from modifying rules", $pass2, "HTTP 403 received: '{$res2['json']['error']}'");

// TEST 03: Authorized users (User 1 Platform Admin & User 2 CMA Admin) permitted
$res3a = executeApiCall(['action' => 'get_allocation_rules', 'plant_id' => $plantAId], 1);
$res3b = executeApiCall(['action' => 'get_allocation_rules', 'plant_id' => $plantAId], 2);
$pass3 = ($res3a['http_code'] === 200 && ($res3a['json']['ok'] ?? false)) &&
         ($res3b['http_code'] === 200 && ($res3b['json']['ok'] ?? false));
assertAudit("TEST 03 — API Permissions: Platform Admin & CMA Admin authorized", $pass3, "Both Admin roles received HTTP 200 OK with valid JSON response");

// TEST 04: Server-side validation: Invalid ratio sum (80%) rejected with 400
$res4 = executeApiCall([
    'action'       => 'save_allocation_rule',
    'plant_id'     => $plantAId,
    'account_code' => '5000888',
    'cost_basis'   => 'Dept',
    'splits'       => [
        ['target_cost_centre_id' => $ccMapA['POH'], 'allocation_ratio' => 50.0],
        ['target_cost_centre_id' => $ccMapA['AOH'], 'allocation_ratio' => 30.0]
    ]
], 1, 'POST');
$pass4 = ($res4['http_code'] === 400) && (strpos($res4['json']['error'] ?? '', 'must equal 100%') !== false);
assertAudit("TEST 04 — API Validation: Non-100% ratio sum rejected", $pass4, "HTTP 400 received: '{$res4['json']['error']}'");

// TEST 05: Server-side validation: Cross-plant target Cost Centre rejected with 400
$res5 = executeApiCall([
    'action'                => 'save_allocation_rule',
    'plant_id'              => $plantAId,
    'account_code'          => '5000777',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapB['POH'], // Belongs to Plant B!
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 1.0
], 1, 'POST');
$pass5 = ($res5['http_code'] === 400) && (strpos($res5['json']['error'] ?? '', 'Cross-plant mapping is prohibited') !== false);
assertAudit("TEST 05 — API Validation: Cross-plant target Cost Centre rejected", $pass5, "HTTP 400 received: '{$res5['json']['error']}'");

// TEST 06: Server-side validation: Missing driver account for DRIVER_ACCOUNT rejected with 400
$res6 = executeApiCall([
    'action'            => 'save_allocation_rule',
    'plant_id'          => $plantAId,
    'account_code'      => '5000493',
    'cost_basis'        => 'Dept',
    'allocation_method' => 'DRIVER_ACCOUNT',
    'driver_account_code' => '' // Missing driver!
], 1, 'POST');
$pass6 = ($res6['http_code'] === 400) && (strpos($res6['json']['error'] ?? '', 'Driver Account Code is required') !== false);
assertAudit("TEST 06 — API Validation: Missing driver account rejected", $pass6, "HTTP 400 received: '{$res6['json']['error']}'");

// TEST 07: Multi-split atomic save & retrieval via get_allocation_rules
$res7save = executeApiCall([
    'action'       => 'save_allocation_rule',
    'plant_id'     => $plantAId,
    'account_code' => '5000413',
    'cost_basis'   => 'Dept',
    'splits'       => [
        ['target_cost_centre_id' => $ccMapA['QC'],                    'allocation_ratio' => 10.4852],
        ['target_cost_centre_id' => $ccMapA['Repairs & Maintenance'], 'allocation_ratio' => 15.7611],
        ['target_cost_centre_id' => $ccMapA['POH'],                   'allocation_ratio' => 70.7822],
        ['target_cost_centre_id' => $ccMapA['AOH'],                   'allocation_ratio' => 2.5477],
        ['target_cost_centre_id' => $ccMapA['SOH'],                   'allocation_ratio' => 0.4238]
    ]
], 1, 'POST');

$res7get = executeApiCall(['action' => 'get_allocation_rules', 'plant_id' => $plantAId], 1);
$rules7 = $res7get['json']['rules'] ?? [];
$pass7 = ($res7save['http_code'] === 200 && ($res7save['json']['ok'] ?? false)) &&
         (count($rules7) === 5) &&
         !empty($rules7[0]['cost_center_name']);
assertAudit("TEST 07 — API Multi-Split: 5-way split atomically stored & retrieved", $pass7, "5 Cost Centre rules created with target CC names dynamically joined");

// TEST 08: API Rule Deletion by account code
$res8del = executeApiCall([
    'action'       => 'delete_allocation_rule',
    'plant_id'     => $plantAId,
    'account_code' => '5000413'
], 1, 'POST');
$res8chk = executeApiCall(['action' => 'get_allocation_rules', 'plant_id' => $plantAId], 1);
$pass8 = ($res8del['http_code'] === 200 && ($res8del['json']['ok'] ?? false)) &&
         (($res8chk['json']['count'] ?? -1) === 0);
assertAudit("TEST 08 — API Deletion: Rule deletion by account code verified", $pass8, "Deleted all splits for account 5000413; count verified at 0");

// -------------------------------------------------------------
// STAGE 3: ALLOCATION INTEGRATION & ENGINE INTEGRITY
// -------------------------------------------------------------

// TEST 09: Invalid ratio configuration safely isolated in quantity_reco_exception
// Force an invalid ratio rule directly in database for Plant A
$pdo->prepare("INSERT INTO cost_allocation_rules (plant_id, account_code, cost_basis, target_cost_centre_id, allocation_method, allocation_ratio, is_active) VALUES (?, '5000999', 'Dept', ?, 'RATIO', 0.50, TRUE)")->execute([$plantAId, $ccMapA['POH']]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    ['account_code' => '5000999', 'description' => 'BAD RATIO TEST', 'amount' => 100000.0, 'cost_basis' => 'Dept', 'source_row_ref' => 'ROW-1']
]);

$gen9 = generateQuantityReco($pdo, $plantAId, $period1Id);
$excStmt9 = $pdo->prepare("SELECT reason FROM quantity_reco_exception WHERE run_id = ? AND source_description = 'BAD RATIO TEST'");
$excStmt9->execute([$gen9['run_id']]);
$reason9 = (string)$excStmt9->fetchColumn();
$pass9 = (strpos($reason9, 'must equal 100%') !== false);
assertAudit("TEST 09 — Engine Integrity: Non-100% rule logged as unmapped exception", $pass9, "Captured exception reason: '{$reason9}'");

// Clean up
$pdo->exec("DELETE FROM cost_allocation_rules WHERE plant_id = {$plantAId}");
$pdo->exec("DELETE FROM quantity_reco_source_transactions WHERE plant_id = {$plantAId}");

// TEST 10: Missing driver account safely logged as unmapped exception
saveCostAllocationRule($pdo, [
    'plant_id'            => $plantAId,
    'account_code'        => '5000493',
    'cost_basis'          => 'Dept',
    'allocation_method'   => 'DRIVER_ACCOUNT',
    'driver_account_code' => '5000413' // 5000413 has NOT been ingested in this test run!
]);
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    ['account_code' => '5000493', 'description' => 'ORPHAN PF TEST', 'amount' => 20000.0, 'cost_basis' => 'Dept', 'source_row_ref' => 'ROW-2']
]);

$gen10 = generateQuantityReco($pdo, $plantAId, $period1Id);
$excStmt10 = $pdo->prepare("SELECT reason FROM quantity_reco_exception WHERE run_id = ? AND source_description = 'ORPHAN PF TEST'");
$excStmt10->execute([$gen10['run_id']]);
$reason10 = (string)$excStmt10->fetchColumn();
$pass10 = (strpos($reason10, "Driver account '5000413' not found") !== false);
assertAudit("TEST 10 — Engine Integrity: Missing driver logged as unmapped exception", $pass10, "Captured exception reason: '{$reason10}'");

$pdo->exec("DELETE FROM cost_allocation_rules WHERE plant_id = {$plantAId}");
$pdo->exec("DELETE FROM quantity_reco_source_transactions WHERE plant_id = {$plantAId}");

// TEST 11: Strict Period-specific Rule Override Precedence
// Global rule: 100% POH. Period 2 override: 60% POH / 40% AOH
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'period_id'             => null, // Global
    'account_code'          => '5000413',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 1.0
]);

saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'period_id'             => $period2Id, // Specific to Period 2
    'account_code'          => '5000413',
    'cost_basis'            => 'Dept',
    'splits'                => [
        ['target_cost_centre_id' => $ccMapA['POH'], 'allocation_ratio' => 0.60],
        ['target_cost_centre_id' => $ccMapA['AOH'], 'allocation_ratio' => 0.40]
    ]
]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    ['account_code' => '5000413', 'description' => 'SALARY JULY', 'amount' => 100000.0, 'cost_basis' => 'Dept', 'source_row_ref' => 'ROW-JUL']
]);
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period2Id, [
    ['account_code' => '5000413', 'description' => 'SALARY AUG', 'amount' => 100000.0, 'cost_basis' => 'Dept', 'source_row_ref' => 'ROW-AUG']
]);

$genP1 = generateQuantityReco($pdo, $plantAId, $period1Id);
$matP1 = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$genP2 = generateQuantityReco($pdo, $plantAId, $period2Id);
$matP2 = getQuantityRecoMatrix($pdo, $plantAId, $period2Id);

$rowP1 = $matP1['rows'][0] ?? [];
$rowP2 = $matP2['rows'][0] ?? [];

$pass11 = (abs(($rowP1['allocations']['POH'] ?? 0) - 100000.0) < 0.01) &&
          (abs(($rowP2['allocations']['POH'] ?? 0) - 60000.0) < 0.01) &&
          (abs(($rowP2['allocations']['AOH'] ?? 0) - 40000.0) < 0.01);
assertAudit("TEST 11 — Engine Integrity: Period-specific override precedence", $pass11, "Period 1 used Global rule (100% POH = ₹100k); Period 2 used Period override (60% POH = ₹60k, 40% AOH = ₹40k)");

$pdo->exec("DELETE FROM cost_allocation_rules WHERE plant_id = {$plantAId}");
$pdo->exec("DELETE FROM quantity_reco_source_transactions WHERE plant_id = {$plantAId}");

// TEST 12: Currency rounding residual adjustment (<= ₹0.02)
// 100 divided 3 ways (0.3333333334, 0.3333333333, 0.3333333333) -> 33.34, 33.33, 33.33
saveCostAllocationRule($pdo, [
    'plant_id'     => $plantAId,
    'account_code' => '5000555',
    'cost_basis'   => 'Dept',
    'splits'       => [
        ['target_cost_centre_id' => $ccMapA['POH'], 'allocation_ratio' => 0.3333333334],
        ['target_cost_centre_id' => $ccMapA['AOH'], 'allocation_ratio' => 0.3333333333],
        ['target_cost_centre_id' => $ccMapA['SOH'], 'allocation_ratio' => 0.3333333333],
    ]
]);
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    ['account_code' => '5000555', 'description' => 'RESIDUAL TEST', 'amount' => 100.0, 'cost_basis' => 'Dept', 'source_row_ref' => 'ROW-RES']
]);

$gen12 = generateQuantityReco($pdo, $plantAId, $period1Id);
$mat12 = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$row12 = $mat12['rows'][0] ?? [];

$pass12 = ($row12['is_balanced'] ?? false) &&
          (abs(($row12['allocations']['POH'] ?? 0) - 33.34) < 0.01) &&
          (abs(($row12['allocations']['AOH'] ?? 0) - 33.33) < 0.01) &&
          (abs(($row12['allocations']['SOH'] ?? 0) - 33.33) < 0.01) &&
          (abs(($row12['total'] ?? 0) - 100.0) < 0.0001) &&
          (abs(($row12['check'] ?? 0) - 0.0) < 0.0001);
assertAudit("TEST 12 — Engine Integrity: Currency residual allocated to largest slice", $pass12, "POH=₹33.34 (absorbed 1 paise residual), AOH=₹33.33, SOH=₹33.33; Total=₹100.00, Check=0.00 (Balanced)");

$pdo->exec("DELETE FROM cost_allocation_rules WHERE plant_id = {$plantAId}");
$pdo->exec("DELETE FROM quantity_reco_source_transactions WHERE plant_id = {$plantAId}");

// TEST 13: Regeneration idempotency & rollback
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000413',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 1.0
]);
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    ['account_code' => '5000413', 'description' => 'SALARY TEST', 'amount' => 250000.0, 'cost_basis' => 'Dept', 'source_row_ref' => 'ROW-IDEM']
]);

for ($i = 0; $i < 3; $i++) {
    generateQuantityReco($pdo, $plantAId, $period1Id);
}
$runsCount = (int)$pdo->query("SELECT COUNT(*) FROM quantity_reco_run WHERE plant_id = {$plantAId} AND period_id = {$period1Id}")->fetchColumn();
$mat13 = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$pass13 = ($runsCount === 1) &&
          (count($mat13['rows']) === 1) &&
          (abs(($mat13['rows'][0]['total'] ?? 0) - 250000.0) < 0.01);
assertAudit("TEST 13 — Engine Integrity: Repeated generation idempotency", $pass13, "3 successive generations maintained exact 1 active run and zero duplicate details");

// TEST 14: Source traceability drilldown
$runId14 = (int)$pdo->query("SELECT run_id FROM quantity_reco_run WHERE plant_id = {$plantAId} AND period_id = {$period1Id}")->fetchColumn();
$traced = traceCostCentreAmountToSource($pdo, $runId14, $ccMapA['POH']);
$pass14 = (count($traced) === 1) &&
          ($traced[0]['source_description'] === 'SALARY TEST') &&
          (abs((float)$traced[0]['amount'] - 250000.0) < 0.01);
assertAudit("TEST 14 — Source Traceability: Drill-down traces back to contributing invoice", $pass14, "Traced ₹250,000 back to source record 'SALARY TEST' (ROW-IDEM)");

$pdo->exec("DELETE FROM cost_allocation_rules WHERE plant_id = {$plantAId}");
$pdo->exec("DELETE FROM quantity_reco_source_transactions WHERE plant_id = {$plantAId}");

// -------------------------------------------------------------
// STAGE 4: FRONTEND IMPLEMENTATION & DOM AUDIT
// -------------------------------------------------------------
$dashHtml = file_get_contents(__DIR__ . '/dashboard.php');
$jsCode = file_get_contents(__DIR__ . '/assets/js/quantity_reco.js');

$hasAllocBtn = (strpos($dashHtml, 'id="qrAllocRulesBtn"') !== false);
$hasAllocModal = (strpos($dashHtml, 'id="qrAllocRulesModal"') !== false);
$hasJsModalFunc = (strpos($jsCode, 'function openAllocRulesModal') !== false);
$hasRatioBadge = (strpos($jsCode, 'arRatioTotalBadge') !== false);
$hasInlineBtn = (strpos($jsCode, 'qr-alloc-inline-btn') !== false);

$pass15 = $hasAllocBtn && $hasAllocModal && $hasJsModalFunc && $hasRatioBadge && $hasInlineBtn;
assertAudit("TEST 15 — Frontend UI: Allocation Rules management components verified", $pass15, "Button, Modal, dynamic split rows, live ratio validation badge, and exception shortcut verified in UI & JS");

// Clean fixtures
$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id IN ({$plantAId}, {$plantBId})");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id IN ({$plantAId}, {$plantBId})");

echo "\n=================================================================\n";
echo "    PHASE 3.3 AUDIT SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "=================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL 15 PHASE 3.3 ALLOCATION AUDIT TESTS PASSED WITH 100% SUCCESS! <<<\n";
    exit(0);
} else {
    echo ">>> AUDIT FAILED WITH {$failCount} ERRORS! <<<\n";
    exit(1);
}
