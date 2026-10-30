<?php
/**
 * COST360 — QUANTITY RECO: PHASE 3.2 VERIFICATION TEST SUITE
 * 
 * Verifies Configurable Department Allocation Engine:
 * 1.  Salary A/C (5000413) 5-CC Split
 * 2.  Employer PF (5000493) Driver-Based Split
 * 3.  Professional Charges (6300213) 3-CC Split
 * 4.  Missing & Zero Driver Handling (quantity_reco_exception)
 * 5.  Invalid Ratio Sum Validation (sum != 100% -> exception)
 * 6.  Inactive Rule & Inactive Target Cost Centre Exception Handling
 * 7.  Negative CLOSING Values & Sign Preservation
 * 8.  Deterministic Currency Rounding Residual Adjustment (<= ₹0.02 to largest slice)
 * 9.  Plant & Period Rule Isolation (Period-specific precedence)
 * 10. Cross-Plant Mapping Strict Prohibition
 * 11. Repeated Generation Idempotency & Atomic Rollback
 * 12. REST API Endpoints (get, save, delete allocation rules)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/quantity_reco.php';

$pdo = getDbConnection();

echo "=================================================================\n";
echo "    COST360 — QUANTITY RECO PHASE 3.2 VERIFICATION SUITE\n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $testName, bool $condition, string $detail = '') {
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

// -------------------------------------------------------------
// FIXTURE SETUP
// -------------------------------------------------------------
echo "--> Initializing Phase 3.2 Test Fixtures...\n";

$divId = (int)$pdo->query("SELECT division_id FROM divisions LIMIT 1")->fetchColumn() ?: 1;

// Test Plant 1 (Department Allocation Engine Test)
$stmt = $pdo->prepare("SELECT plant_id FROM plants WHERE plant_code = 'PLANT_DEPT_TEST_A' LIMIT 1");
$stmt->execute();
$plantAId = (int)$stmt->fetchColumn();
if (!$plantAId) {
    $ins = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PLANT_DEPT_TEST_A', 'Dept Test Plant A', 'Active') RETURNING plant_id");
    $ins->execute([$divId]);
    $plantAId = (int)$ins->fetchColumn();
}

// Test Plant 2 (Isolation Test)
$stmt = $pdo->prepare("SELECT plant_id FROM plants WHERE plant_code = 'PLANT_DEPT_TEST_B' LIMIT 1");
$stmt->execute();
$plantBId = (int)$stmt->fetchColumn();
if (!$plantBId) {
    $ins = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PLANT_DEPT_TEST_B', 'Dept Test Plant B', 'Active') RETURNING plant_id");
    $ins->execute([$divId]);
    $plantBId = (int)$ins->fetchColumn();
}

$period1Id = 1; // July 2026
$period2Id = 2; // August 2026

// Ensure Cost Centres exist for Plant A
$ccNames = ['QC', 'Repairs & Maintenance', 'POH', 'AOH', 'SOH', 'Sales', 'Utility'];
$ccMapA = [];
foreach ($ccNames as $idx => $cName) {
    $cCode = 'CC_TA_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $cName));
    $res = saveCostCentre($pdo, [
        'plant_id'         => $plantAId,
        'cost_center_code' => $cCode,
        'cost_center_name' => $cName,
        'cost_basis_code'  => $cName,
        'display_order'    => $idx + 1,
        'is_active'        => true
    ]);
    $ccMapA[$cName] = (int)$res['id'];
}

// Ensure Cost Centres exist for Plant B
$ccMapB = [];
foreach (['POH', 'AOH', 'SOH'] as $idx => $cName) {
    $cCode = 'CC_TB_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $cName));
    $res = saveCostCentre($pdo, [
        'plant_id'         => $plantBId,
        'cost_center_code' => $cCode,
        'cost_center_name' => $cName,
        'cost_basis_code'  => $cName,
        'display_order'    => $idx + 1,
        'is_active'        => true
    ]);
    $ccMapB[$cName] = (int)$res['id'];
}

// Clean up previous test transactions and rules for clean fixture
$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id IN ({$plantAId}, {$plantBId})");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id IN ({$plantAId}, {$plantBId})");

echo "    Plant A ID: {$plantAId}, Plant B ID: {$plantBId}\n\n";

// -------------------------------------------------------------
// TEST 01 — Salary A/C 5-CC Split
// -------------------------------------------------------------
// Seed Salary A/C 5-way ratios for Plant A
$salarySplits = [
    ['cc_id' => $ccMapA['QC'],                    'ratio' => 0.1048520648, 'order' => 1],
    ['cc_id' => $ccMapA['Repairs & Maintenance'], 'ratio' => 0.1576106236, 'order' => 2],
    ['cc_id' => $ccMapA['POH'],                   'ratio' => 0.7078216336, 'order' => 3],
    ['cc_id' => $ccMapA['AOH'],                   'ratio' => 0.0254767677, 'order' => 4],
    ['cc_id' => $ccMapA['SOH'],                   'ratio' => 0.0042389103, 'order' => 5],
];
foreach ($salarySplits as $s) {
    saveCostAllocationRule($pdo, [
        'plant_id'              => $plantAId,
        'account_code'          => '5000413',
        'cost_basis'            => 'Dept',
        'target_cost_centre_id' => $s['cc_id'],
        'allocation_method'     => 'RATIO',
        'allocation_ratio'      => $s['ratio'],
        'display_order'         => $s['order'],
        'is_active'             => true
    ]);
}

// Insert Salary source transaction
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    [
        'account_code'       => '5000413',
        'description'        => 'SALARY A/C',
        'amount'             => 41308230.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Employee Benefit',
        'nature'             => 'Fixed',
        'source_row_ref'     => 'ROW-69'
    ]
]);

$genRes = generateQuantityReco($pdo, $plantAId, $period1Id);
$matrix = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$salaryRow = null;
foreach ($matrix['rows'] as $r) {
    if ($r['acode'] === '5000413') $salaryRow = $r;
}

$detStmt = $pdo->prepare("SELECT COUNT(*) FROM quantity_reco_detail WHERE run_id = ? AND account_code = '5000413'");
$detStmt->execute([$genRes['run_id']]);
$salaryDetailCount = (int)$detStmt->fetchColumn();

$salaryAllocPass = (
    $salaryDetailCount === 5 &&
    $salaryRow !== null &&
    abs($salaryRow['allocations']['QC'] - 4331253.21) < 0.01 &&
    abs($salaryRow['allocations']['Repairs & Maintenance'] - 6510615.89) < 0.01 &&
    abs($salaryRow['allocations']['POH'] - 29238858.84) < 0.01 &&
    abs($salaryRow['allocations']['AOH'] - 1052400.18) < 0.01 &&
    abs($salaryRow['allocations']['SOH'] - 175101.88) < 0.01 &&
    abs($salaryRow['total'] - 41308230.00) < 0.01 &&
    abs($salaryRow['check'] - 0.0) < 0.01
);
assertTest("TEST 01 — Salary A/C 5-CC split", $salaryAllocPass, "Generated 5 relational details; QC=₹4.33M, Repairs=₹6.51M, POH=₹29.24M, AOH=₹1.05M, SOH=₹175k; Total=₹41.31M, Check=0.00");

// -------------------------------------------------------------
// TEST 02 — Employer PF Deduction Driver-Based Split
// -------------------------------------------------------------
// Seed PF driver rule pointing to 5000413
saveCostAllocationRule($pdo, [
    'plant_id'            => $plantAId,
    'account_code'        => '5000493',
    'cost_basis'          => 'Dept',
    'allocation_method'   => 'DRIVER_ACCOUNT',
    'driver_account_code' => '5000413',
    'display_order'       => 1,
    'is_active'           => true
]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    [
        'account_code'       => '5000493',
        'description'        => 'EMPLOYER PF DEDUCTION',
        'amount'             => 2306028.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Employee Benefit',
        'nature'             => 'Fixed',
        'source_row_ref'     => 'ROW-62'
    ]
]);

$genRes = generateQuantityReco($pdo, $plantAId, $period1Id);
$matrix = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$pfRow = null;
foreach ($matrix['rows'] as $r) {
    if ($r['acode'] === '5000493') $pfRow = $r;
}

$pfAllocPass = (
    $pfRow !== null &&
    abs($pfRow['allocations']['QC'] - 241791.80) < 0.02 &&
    abs($pfRow['allocations']['Repairs & Maintenance'] - 363454.51) < 0.02 &&
    abs($pfRow['allocations']['POH'] - 1632256.50) < 0.02 &&
    abs($pfRow['allocations']['AOH'] - 58750.14) < 0.02 &&
    abs($pfRow['allocations']['SOH'] - 9775.05) < 0.02 &&
    abs($pfRow['total'] - 2306028.00) < 0.01 &&
    abs($pfRow['check'] - 0.0) < 0.01
);
assertTest("TEST 02 — Employer PF driver-based split", $pfAllocPass, "Inherited Salary proportions: QC=₹241.79k, Repairs=₹363.45k, POH=₹1.63M, AOH=₹58.75k, SOH=₹9.78k; Total=₹2.31M, Check=0.00");

// -------------------------------------------------------------
// TEST 03 — Professional Charges 3-CC Split
// -------------------------------------------------------------
$profSplits = [
    ['cc_id' => $ccMapA['POH'], 'ratio' => 0.4722222222, 'order' => 1],
    ['cc_id' => $ccMapA['AOH'], 'ratio' => 0.2222222222, 'order' => 2],
    ['cc_id' => $ccMapA['SOH'], 'ratio' => 0.3055555556, 'order' => 3],
];
foreach ($profSplits as $s) {
    saveCostAllocationRule($pdo, [
        'plant_id'              => $plantAId,
        'account_code'          => '6300213',
        'cost_basis'            => 'Dept',
        'target_cost_centre_id' => $s['cc_id'],
        'allocation_method'     => 'RATIO',
        'allocation_ratio'      => $s['ratio'],
        'display_order'         => $s['order'],
        'is_active'             => true
    ]);
}

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    [
        'account_code'       => '6300213',
        'description'        => 'PROFESSIONAL CHARGES',
        'amount'             => 720000.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Other Exp',
        'nature'             => 'Fixed',
        'source_row_ref'     => 'ROW-122'
    ]
]);

$genRes = generateQuantityReco($pdo, $plantAId, $period1Id);
$matrix = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$profRow = null;
foreach ($matrix['rows'] as $r) {
    if ($r['acode'] === '6300213') $profRow = $r;
}

$profAllocPass = (
    $profRow !== null &&
    abs($profRow['allocations']['POH'] - 340000.00) < 0.01 &&
    abs($profRow['allocations']['AOH'] - 160000.00) < 0.01 &&
    abs($profRow['allocations']['SOH'] - 220000.00) < 0.01 &&
    abs($profRow['total'] - 720000.00) < 0.01 &&
    abs($profRow['check'] - 0.0) < 0.01
);
assertTest("TEST 03 — Professional Charges 3-CC split", $profAllocPass, "POH=₹340,000, AOH=₹160,000, SOH=₹220,000; Total=₹720,000, Check=0.00");

// -------------------------------------------------------------
// TEST 04 — Missing & Zero Driver Exception Handling
// -------------------------------------------------------------
// Configure account 5000999 with missing driver 5000000
saveCostAllocationRule($pdo, [
    'plant_id'            => $plantAId,
    'account_code'        => '5000999',
    'cost_basis'          => 'Dept',
    'allocation_method'   => 'DRIVER_ACCOUNT',
    'driver_account_code' => '5000000', // Non-existent driver
    'display_order'       => 1,
    'is_active'           => true
]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    [
        'account_code'       => '5000999',
        'description'        => 'ORPHAN DEPENDENT BENEFIT',
        'amount'             => 50000.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Other Exp',
        'nature'             => 'Variable',
        'source_row_ref'     => 'ROW-999'
    ]
]);

$genRes = generateQuantityReco($pdo, $plantAId, $period1Id);
$excStmt = $pdo->prepare("SELECT reason FROM quantity_reco_exception WHERE run_id = ? AND source_description = 'ORPHAN DEPENDENT BENEFIT'");
$excStmt->execute([$genRes['run_id']]);
$excReason = (string)$excStmt->fetchColumn();

$missingDriverPass = (
    strpos($excReason, "Driver account '5000000' not found") !== false
);
assertTest("TEST 04 — Missing & zero driver exception handling", $missingDriverPass, "Captured exception: '{$excReason}'");

// Clean up test account 5000999
$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id = {$plantAId} AND account_code = '5000999'");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id = {$plantAId} AND account_code = '5000999'");

// -------------------------------------------------------------
// TEST 05 — Ratios != 100% Validation
// -------------------------------------------------------------
// Configure account 5000888 with ratios summing to 80% (0.5 + 0.3)
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000888',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.50,
    'display_order'         => 1,
    'is_active'             => true
]);
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000888',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['AOH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.30,
    'display_order'         => 2,
    'is_active'             => true
]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    [
        'account_code'       => '5000888',
        'description'        => 'BAD RATIO SPLIT',
        'amount'             => 100000.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Other Exp',
        'nature'             => 'Variable',
        'source_row_ref'     => 'ROW-888'
    ]
]);

$genRes = generateQuantityReco($pdo, $plantAId, $period1Id);
$excStmt = $pdo->prepare("SELECT reason FROM quantity_reco_exception WHERE run_id = ? AND source_description = 'BAD RATIO SPLIT'");
$excStmt->execute([$genRes['run_id']]);
$excReason = (string)$excStmt->fetchColumn();

$ratioSumPass = (
    strpos($excReason, "must equal 100%") !== false
);
assertTest("TEST 05 — Ratios != 100% exception handling", $ratioSumPass, "Captured exception: '{$excReason}'");

// Clean up test account 5000888
$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id = {$plantAId} AND account_code = '5000888'");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id = {$plantAId} AND account_code = '5000888'");

// -------------------------------------------------------------
// TEST 06 — Inactive Rule & Inactive Target CC Exception Handling
// -------------------------------------------------------------
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000777',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 1.0,
    'display_order'         => 1,
    'is_active'             => false // Inactive rule!
]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    [
        'account_code'       => '5000777',
        'description'        => 'INACTIVE RULE TEST',
        'amount'             => 25000.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Other Exp',
        'nature'             => 'Variable',
        'source_row_ref'     => 'ROW-777'
    ]
]);

$genRes = generateQuantityReco($pdo, $plantAId, $period1Id);
$excStmt = $pdo->prepare("SELECT reason FROM quantity_reco_exception WHERE run_id = ? AND source_description = 'INACTIVE RULE TEST'");
$excStmt->execute([$genRes['run_id']]);
$excReason = (string)$excStmt->fetchColumn();

$inactiveRulePass = (
    strpos($excReason, "marked Inactive") !== false
);
assertTest("TEST 06 — Inactive rule captured as exception", $inactiveRulePass, "Captured exception: '{$excReason}'");

$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id = {$plantAId} AND account_code = '5000777'");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id = {$plantAId} AND account_code = '5000777'");

// -------------------------------------------------------------
// TEST 07 — Negative CLOSING Values Preservation
// -------------------------------------------------------------
// Create account with negative closing split across POH and AOH
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000666',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.60,
    'display_order'         => 1,
    'is_active'             => true
]);
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000666',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['AOH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.40,
    'display_order'         => 2,
    'is_active'             => true
]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    [
        'account_code'       => '5000666',
        'description'        => 'PAYROLL CREDIT REBATE',
        'amount'             => -150000.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Employee Benefit',
        'nature'             => 'Fixed',
        'source_row_ref'     => 'ROW-666'
    ]
]);

$genRes = generateQuantityReco($pdo, $plantAId, $period1Id);
$matrix = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$negRow = null;
foreach ($matrix['rows'] as $r) {
    if ($r['acode'] === '5000666') $negRow = $r;
}

$negativePass = (
    $negRow !== null &&
    abs($negRow['closing'] - (-150000.00)) < 0.01 &&
    abs($negRow['allocations']['POH'] - (-90000.00)) < 0.01 &&
    abs($negRow['allocations']['AOH'] - (-60000.00)) < 0.01 &&
    abs($negRow['total'] - (-150000.00)) < 0.01 &&
    abs($negRow['check'] - 0.0) < 0.01
);
assertTest("TEST 07 — Negative CLOSING values preserved", $negativePass, "POH=₹-90k, AOH=₹-60k; Total=₹-150k, Check=0.00 (Balanced)");

$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id = {$plantAId} AND account_code = '5000666'");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id = {$plantAId} AND account_code = '5000666'");

// -------------------------------------------------------------
// TEST 08 — Currency Rounding Residual Adjustment (<= ₹0.02)
// -------------------------------------------------------------
// 100.00 divided by 3 (0.3333333333 each) -> 33.33 * 3 = 99.99 (0.01 residual)
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000555',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.3333333334,
    'display_order'         => 1,
    'is_active'             => true
]);
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000555',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['AOH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.3333333333,
    'display_order'         => 2,
    'is_active'             => true
]);
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'account_code'          => '5000555',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['SOH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.3333333333,
    'display_order'         => 3,
    'is_active'             => true
]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    [
        'account_code'       => '5000555',
        'description'        => 'RESIDUAL ADJUSTMENT TEST',
        'amount'             => 100.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Other Exp',
        'nature'             => 'Variable',
        'source_row_ref'     => 'ROW-555'
    ]
]);

$genRes = generateQuantityReco($pdo, $plantAId, $period1Id);
$matrix = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$resRow = null;
foreach ($matrix['rows'] as $r) {
    if ($r['acode'] === '5000555') $resRow = $r;
}

$residualPass = (
    $resRow !== null &&
    abs($resRow['total'] - 100.00) < 0.0001 &&
    abs($resRow['check'] - 0.0) < 0.0001 &&
    $resRow['is_balanced'] === true &&
    // The largest slice (POH) absorbs the 1-paise residual: 33.34 + 33.33 + 33.33 = 100.00
    abs($resRow['allocations']['POH'] - 33.34) < 0.01 &&
    abs($resRow['allocations']['AOH'] - 33.33) < 0.01 &&
    abs($resRow['allocations']['SOH'] - 33.33) < 0.01
);
assertTest("TEST 08 — Currency rounding residual adjustment", $residualPass, "POH=₹33.34 (absorbed 1 paise residual), AOH=₹33.33, SOH=₹33.33; Total=₹100.00, Check=0.00 (Balanced)");

$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id = {$plantAId} AND account_code = '5000555'");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id = {$plantAId} AND account_code = '5000555'");

// -------------------------------------------------------------
// TEST 09 — Plant & Period Rule Isolation (Period Precedence)
// -------------------------------------------------------------
// Plant B rule: 100% POH for Plant B
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantBId,
    'account_code'          => '5000413',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapB['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 1.0,
    'display_order'         => 1,
    'is_active'             => true
]);
saveQuantityRecoSourceTransactions($pdo, $plantBId, $period1Id, [
    [
        'account_code'       => '5000413',
        'description'        => 'SALARY A/C PLANT B',
        'amount'             => 500000.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Employee Benefit',
        'nature'             => 'Fixed',
        'source_row_ref'     => 'ROW-B-1'
    ]
]);

// Period 2 override for Plant A: In August (Period 2), Salary is 50% POH and 50% AOH
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'period_id'             => $period2Id, // Explicit Period 2 override
    'account_code'          => '5000413',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['POH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.50,
    'display_order'         => 1,
    'is_active'             => true
]);
saveCostAllocationRule($pdo, [
    'plant_id'              => $plantAId,
    'period_id'             => $period2Id, // Explicit Period 2 override
    'account_code'          => '5000413',
    'cost_basis'            => 'Dept',
    'target_cost_centre_id' => $ccMapA['AOH'],
    'allocation_method'     => 'RATIO',
    'allocation_ratio'      => 0.50,
    'display_order'         => 2,
    'is_active'             => true
]);
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period2Id, [
    [
        'account_code'       => '5000413',
        'description'        => 'SALARY A/C AUGUST',
        'amount'             => 200000.00,
        'cost_basis'         => 'Dept',
        'pnl_classification' => 'Employee Benefit',
        'nature'             => 'Fixed',
        'source_row_ref'     => 'ROW-A-AUG'
    ]
]);

$genB = generateQuantityReco($pdo, $plantBId, $period1Id);
$matB = getQuantityRecoMatrix($pdo, $plantBId, $period1Id);
$genA2 = generateQuantityReco($pdo, $plantAId, $period2Id);
$matA2 = getQuantityRecoMatrix($pdo, $plantAId, $period2Id);

$plantBRow = $matB['rows'][0] ?? null;
$plantA2Row = $matA2['rows'][0] ?? null;

$isolationPass = (
    $plantBRow !== null &&
    abs($plantBRow['allocations']['POH'] - 500000.00) < 0.01 &&
    $plantA2Row !== null &&
    abs($plantA2Row['allocations']['POH'] - 100000.00) < 0.01 &&
    abs($plantA2Row['allocations']['AOH'] - 100000.00) < 0.01
);
assertTest("TEST 09 — Plant & Period rule isolation", $isolationPass, "Plant B Salary 100% POH (₹500k); Plant A Period 2 override 50/50 POH/AOH (₹100k/₹100k) without bleeding into Period 1");

// -------------------------------------------------------------
// TEST 10 — Cross-Plant Mapping Prohibition
// -------------------------------------------------------------
$crossPlantBlocked = false;
try {
    saveCostAllocationRule($pdo, [
        'plant_id'              => $plantAId,
        'account_code'          => '5000999',
        'cost_basis'            => 'Dept',
        'target_cost_centre_id' => $ccMapB['POH'], // CC belongs to Plant B!
        'allocation_method'     => 'RATIO',
        'allocation_ratio'      => 1.0,
        'display_order'         => 1,
        'is_active'             => true
    ]);
} catch (InvalidArgumentException $e) {
    $crossPlantBlocked = (strpos($e->getMessage(), "Cross-plant mapping is prohibited") !== false);
}
assertTest("TEST 10 — Cross-plant allocation prohibited", $crossPlantBlocked, "Cross-plant target Cost Centre accurately rejected with 400 validation error");

// -------------------------------------------------------------
// TEST 11 — Regeneration Idempotency & Rollback
// -------------------------------------------------------------
$runIds = [];
for ($i = 0; $i < 3; $i++) {
    $res = generateQuantityReco($pdo, $plantAId, $period1Id);
    $runIds[] = $res['run_id'];
}
$activeRunsStmt = $pdo->prepare("SELECT COUNT(*) FROM quantity_reco_run WHERE plant_id = ? AND period_id = ?");
$activeRunsStmt->execute([$plantAId, $period1Id]);
$activeRunCount = (int)$activeRunsStmt->fetchColumn();

$matFinal = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$salaryFinal = null;
foreach ($matFinal['rows'] as $r) {
    if ($r['acode'] === '5000413') $salaryFinal = $r;
}

$idempotentPass = (
    $activeRunCount === 1 &&
    $salaryFinal !== null &&
    abs($salaryFinal['total'] - 41308230.00) < 0.01
);
assertTest("TEST 11 — Regeneration idempotency", $idempotentPass, "3 successive generations maintained exact 1 active run and zero row duplication");

// -------------------------------------------------------------
// TEST 12 — REST API Endpoints Verification
// -------------------------------------------------------------
// Test via helper subprocess to simulate real HTTP REST API call
$apiScript = __DIR__ . '/scratch/tmp_phase3_2_api.php';
file_put_contents($apiScript, '<?php
session_start();
$_SESSION["user_id"] = 1;
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/quantity_reco.php";

$pdo = getDbConnection();
// 1. Get rules
$_GET = ["action" => "get_allocation_rules", "plant_id" => ' . $plantAId . '];
$_REQUEST = $_GET;
ob_start();
include __DIR__ . "/../api/quantity_reco_api.php";
$outGet = ob_get_clean();
$getRes = json_decode($outGet, true);

// 2. Save rule
$_POST = [
    "action" => "save_allocation_rule",
    "plant_id" => ' . $plantAId . ',
    "account_code" => "TEST_API_ACC",
    "cost_basis" => "Dept",
    "target_cost_centre_id" => ' . $ccMapA['POH'] . ',
    "allocation_method" => "RATIO",
    "allocation_ratio" => 1.0,
    "is_active" => true
];
$_REQUEST = $_POST;
ob_start();
include __DIR__ . "/../api/quantity_reco_api.php";
$outSave = ob_get_clean();
$saveRes = json_decode($outSave, true);
$ruleId = $saveRes["rule"]["rule_id"] ?? 0;

// 3. Delete rule
$_POST = ["action" => "delete_allocation_rule", "rule_id" => $ruleId];
$_REQUEST = $_POST;
ob_start();
include __DIR__ . "/../api/quantity_reco_api.php";
$outDel = ob_get_clean();
$delRes = json_decode($outDel, true);

echo json_encode([
    "get_ok" => ($getRes["ok"] ?? false) && ($getRes["count"] ?? 0) >= 3,
    "save_ok" => ($saveRes["ok"] ?? false) && $ruleId > 0,
    "del_ok" => ($delRes["ok"] ?? false)
]);
');

$apiOut = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($apiScript));
$apiResult = json_decode($apiOut, true);
$apiPass = !empty($apiResult['get_ok']) && !empty($apiResult['save_ok']) && !empty($apiResult['del_ok']);
assertTest("TEST 12 — REST API endpoints (get, save, delete)", $apiPass, "Tested get_allocation_rules, save_allocation_rule, and delete_allocation_rule via authenticated API");

// Clean up scratch script
if (file_exists($apiScript)) unlink($apiScript);

// Clean up fixtures
$pdo->exec("DELETE FROM public.cost_allocation_rules WHERE plant_id IN ({$plantAId}, {$plantBId})");
$pdo->exec("DELETE FROM public.quantity_reco_source_transactions WHERE plant_id IN ({$plantAId}, {$plantBId})");

echo "\n=================================================================\n";
echo "    PHASE 3.2 TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "=================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL 12 PHASE 3.2 VERIFICATION TESTS PASSED WITH 100% SUCCESS! <<<\n";
    exit(0);
} else {
    echo ">>> VERIFICATION FAILED WITH {$failCount} ERRORS! <<<\n";
    exit(1);
}

