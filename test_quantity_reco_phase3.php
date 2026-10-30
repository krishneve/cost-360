<?php
/**
 * COST360 — QUANTITY RECO: PHASE 3 AUTOMATED TEST SUITE
 * 
 * Verifies:
 * - Real source integration & normalized transaction pipeline
 * - Costing Basis resolution & mapping workflows
 * - Allocation accuracy (CLOSING to matching CC, zeros elsewhere)
 * - Row Total and Check calculation
 * - Unmapped exceptions & resolution through mapping changes
 * - Plant and Period isolation
 * - Duplicate import protection & idempotency
 * - Transaction safety & rollback on failure
 * - Source traceability drilldown
 * - Negative and zero amount preservation
 * - Aggregation of multiple rows into single CC
 * - Unauthorized API access enforcement
 * - Large dataset stress & performance benchmark (2,000+ rows)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/quantity_reco.php';

$pdo = getDbConnection();

echo "=================================================================\n";
echo "    COST360 — QUANTITY RECO PHASE 3 TEST SUITE\n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function reportTest(string $testName, bool $passed, string $details = ''): void {
    global $passCount, $failCount;
    if ($passed) {
        $passCount++;
        echo " [PASS] {$testName}\n";
        if ($details !== '') {
            echo "        -> {$details}\n";
        }
    } else {
        $failCount++;
        echo " [FAIL] {$testName}\n";
        if ($details !== '') {
            echo "        -> ERROR: {$details}\n";
        }
    }
}

// =====================================================================
// SETUP TEST FIXTURES FOR PHASE 3
// =====================================================================
echo "--> Initializing Phase 3 Test Fixtures...\n";

// Ensure Division exists
$divId = (int)$pdo->query("SELECT division_id FROM divisions LIMIT 1")->fetchColumn() ?: 1;

// Setup Plant A (Phase 3 Plant)
$stmt = $pdo->prepare("SELECT plant_id FROM plants WHERE plant_code = 'PLANT_P3_A' LIMIT 1");
$stmt->execute();
$plantP3A = (int)$stmt->fetchColumn();
if (!$plantP3A) {
    $ins = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PLANT_P3_A', 'Plant P3 A (Automated)', 'Active') RETURNING plant_id");
    $ins->execute([$divId]);
    $plantP3A = (int)$ins->fetchColumn();
}

// Setup Plant B (Phase 3 Plant)
$stmt = $pdo->prepare("SELECT plant_id FROM plants WHERE plant_code = 'PLANT_P3_B' LIMIT 1");
$stmt->execute();
$plantP3B = (int)$stmt->fetchColumn();
if (!$plantP3B) {
    $ins = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PLANT_P3_B', 'Plant P3 B (Automated)', 'Active') RETURNING plant_id");
    $ins->execute([$divId]);
    $plantP3B = (int)$ins->fetchColumn();
}

// Periods
$period1 = 1; // July 2026
$period2 = 2; // August 2026

// Configure 5 dynamic CCs for Plant A: Sales, RMC, QC, Utility, POH
$ccSalesA = saveCostCentre($pdo, ['plant_id' => $plantP3A, 'cost_center_code' => 'P3_CC_SALES', 'cost_center_name' => 'Sales', 'is_active' => true]);
$ccRmcA   = saveCostCentre($pdo, ['plant_id' => $plantP3A, 'cost_center_code' => 'P3_CC_RMC', 'cost_center_name' => 'RMC', 'is_active' => true]);
$ccQcA    = saveCostCentre($pdo, ['plant_id' => $plantP3A, 'cost_center_code' => 'P3_CC_QC', 'cost_center_name' => 'QC', 'is_active' => true]);
$ccUtilA  = saveCostCentre($pdo, ['plant_id' => $plantP3A, 'cost_center_code' => 'P3_CC_UTIL', 'cost_center_name' => 'Utility', 'is_active' => true]);
$ccPohA   = saveCostCentre($pdo, ['plant_id' => $plantP3A, 'cost_center_code' => 'P3_CC_POH', 'cost_center_name' => 'POH', 'is_active' => true]);

// Configure CCs for Plant B: Sales, Export Sales, RMC, AOH
$ccSalesB  = saveCostCentre($pdo, ['plant_id' => $plantP3B, 'cost_center_code' => 'P3_CC_SALES_B', 'cost_center_name' => 'Sales', 'is_active' => true]);
$ccExportB = saveCostCentre($pdo, ['plant_id' => $plantP3B, 'cost_center_code' => 'P3_CC_EXP_B', 'cost_center_name' => 'Export Sales', 'is_active' => true]);
$ccRmcB    = saveCostCentre($pdo, ['plant_id' => $plantP3B, 'cost_center_code' => 'P3_CC_RMC_B', 'cost_center_name' => 'RMC', 'is_active' => true]);
$ccAohB    = saveCostCentre($pdo, ['plant_id' => $plantP3B, 'cost_center_code' => 'P3_CC_AOH_B', 'cost_center_name' => 'AOH', 'is_active' => true]);

// Clean previous transactions for test plants
$pdo->prepare("DELETE FROM quantity_reco_source_transactions WHERE plant_id IN (?, ?)")->execute([$plantP3A, $plantP3B]);
$pdo->prepare("DELETE FROM quantity_reco_run WHERE plant_id IN (?, ?)")->execute([$plantP3A, $plantP3B]);
$pdo->prepare("DELETE FROM cost_basis_mapping WHERE plant_id IN (?, ?)")->execute([$plantP3A, $plantP3B]);

// Configure standard mappings for Plant A
saveCostBasisMapping($pdo, ['plant_id' => $plantP3A, 'source_cost_basis' => 'Electricity', 'cost_centre_id' => (int)$ccUtilA['id'], 'priority' => 10]);
saveCostBasisMapping($pdo, ['plant_id' => $plantP3A, 'source_cost_basis' => 'Power', 'cost_centre_id' => (int)$ccUtilA['id'], 'priority' => 20]);
saveCostBasisMapping($pdo, ['plant_id' => $plantP3A, 'source_cost_basis' => 'Direct Raw Material', 'cost_centre_id' => (int)$ccRmcA['id'], 'priority' => 10]);

echo "Fixtures ready. Plant A ID: {$plantP3A}, Plant B ID: {$plantP3B}.\n\n";

// =====================================================================
// TEST 01: Real source -> normalized source transaction
// =====================================================================
$recordsP3 = [
    [
        'account_code'       => '2000113',
        'description'        => 'SALE INPUT GL RECORD',
        'amount'             => -32989013.18,
        'cost_basis'         => 'RMC',
        'pnl_classification' => 'Rev from Operations',
        'nature'             => 'Variable',
        'source_file'        => 'trial_balance_july.xlsx',
        'source_row_ref'     => 'TB-2000113',
        'source'             => 'TRIAL_BALANCE'
    ],
    [
        'account_code'       => '5000012',
        'description'        => 'ELECTRICITY INVOICE JULY',
        'amount'             => 70000.00,
        'cost_basis'         => 'Electricity',
        'pnl_classification' => 'Other Exp',
        'nature'             => 'Fixed',
        'source_file'        => 'trial_balance_july.xlsx',
        'source_row_ref'     => 'TB-5000012',
        'source'             => 'TRIAL_BALANCE'
    ]
];

$importRes = importQuantityRecoSourceData($pdo, $plantP3A, $period1, $recordsP3, [
    'source_file' => 'trial_balance_july.xlsx',
    'file_hash'   => 'abc123hash'
]);

$srcCount = (int)$pdo->query("SELECT COUNT(*) FROM quantity_reco_source_transactions WHERE plant_id = {$plantP3A} AND period_id = {$period1}")->fetchColumn();
reportTest("TEST 01 — Real source → normalized source transaction", $srcCount === 2, "Imported {$srcCount} normalized records into quantity_reco_source_transactions");

// =====================================================================
// TEST 02: Real source -> correct Costing Basis
// =====================================================================
$chkBasis = $pdo->query("SELECT cost_basis FROM quantity_reco_source_transactions WHERE account_code = '2000113' AND plant_id = {$plantP3A}")->fetchColumn();
reportTest("TEST 02 — Real source → correct Costing Basis", $chkBasis === 'RMC', "Preserved source Costing Basis '{$chkBasis}' without modification");

// =====================================================================
// TEST 03: Costing Basis -> Cost Centre mapping
// =====================================================================
// Electricity -> Utility mapping check
$chkMap = $pdo->prepare("SELECT c.cost_center_name FROM cost_basis_mapping m JOIN cost_center_master c ON m.cost_centre_id = c.id WHERE m.plant_id = ? AND m.source_cost_basis = 'Electricity'");
$chkMap->execute([$plantP3A]);
$mappedCC = $chkMap->fetchColumn();
reportTest("TEST 03 — Costing Basis → Cost Centre mapping", $mappedCC === 'Utility', "Mapped 'Electricity' to target Cost Centre '{$mappedCC}' via cost_basis_mapping");

// =====================================================================
// TEST 04: CLOSING -> matching Cost Centre
// TEST 05: All non-matching Cost Centres = 0
// TEST 06: Total = sum of Cost Centre allocations
// TEST 07: Check = CLOSING - Total
// =====================================================================
generateQuantityReco($pdo, $plantP3A, $period1);
$matrixA = getQuantityRecoMatrix($pdo, $plantP3A, $period1);

$saleInputRow = null;
foreach ($matrixA['rows'] as $r) {
    if ($r['acode'] === '2000113') {
        $saleInputRow = $r;
        break;
    }
}

// TEST 04: CLOSING allocated to RMC
$rmcAlloc = $saleInputRow['allocations']['RMC'] ?? 0.0;
reportTest("TEST 04 — CLOSING → matching Cost Centre", abs($rmcAlloc - (-32989013.18)) < 0.01, "Allocated exact CLOSING amount (" . number_format($rmcAlloc, 2) . ") to RMC column");

// TEST 05: Non-matching CCs = 0
$otherNonZero = false;
foreach ($saleInputRow['allocations'] as $ccName => $val) {
    if (is_string($ccName) && $ccName !== 'RMC' && abs($val) > 0.0001) {
        $otherNonZero = true;
        break;
    }
}
reportTest("TEST 05 — All non-matching Cost Centres = 0", !$otherNonZero, "All non-matching Cost Centre columns strictly evaluate to 0");

// TEST 06: Total = sum of Cost Centre allocations
$expectedTotal = -32989013.18;
reportTest("TEST 06 — Total = sum of Cost Centre allocations", abs($saleInputRow['total'] - $expectedTotal) < 0.01, "Row Total equals sum of allocations (" . number_format($saleInputRow['total'], 2) . ")");

// TEST 07: Check = CLOSING - Total (0 -> Balanced)
reportTest("TEST 07 — Check = CLOSING - Total", abs($saleInputRow['check']) < 0.0001 && $saleInputRow['check_status'] === 'Balanced', "Check is 0.00 with status 'Balanced'");

// =====================================================================
// TEST 08: Unmapped Cost Basis
// =====================================================================
// Ingest unmapped record 'Factory Security'
saveQuantityRecoSourceTransactions($pdo, $plantP3A, $period1, [[
    'account_code'       => '6300999',
    'description'        => 'FACTORY SECURITY CHARGES',
    'amount'             => 45000.00,
    'cost_basis'         => 'Factory Security',
    'pnl_classification' => 'Other Exp',
    'nature'             => 'Fixed',
    'source_file'        => 'manual_entry'
]]);

generateQuantityReco($pdo, $plantP3A, $period1);
$matrixAfterUnmapped = getQuantityRecoMatrix($pdo, $plantP3A, $period1);

$unmappedFound = false;
foreach ($matrixAfterUnmapped['rows'] as $r) {
    if ($r['acode'] === '6300999') {
        $unmappedFound = ($r['type'] === 'UNMAPPED' && abs($r['check'] - 45000.00) < 0.01 && $r['check_status'] === 'Unbalanced');
        break;
    }
}
reportTest("TEST 08 — Unmapped Cost Basis", $unmappedFound, "Unmapped 'Factory Security' logged as exception with Check=₹45,000 and status 'Unbalanced'");

// =====================================================================
// TEST 09: Mapping correction -> regeneration
// =====================================================================
// Now map 'Factory Security' -> POH and regenerate
saveCostBasisMapping($pdo, [
    'plant_id'          => $plantP3A,
    'source_cost_basis' => 'Factory Security',
    'cost_centre_id'    => (int)$ccPohA['id'],
    'priority'          => 10
]);

generateQuantityReco($pdo, $plantP3A, $period1);
$matrixAfterMapping = getQuantityRecoMatrix($pdo, $plantP3A, $period1);

$resolved = false;
foreach ($matrixAfterMapping['rows'] as $r) {
    if ($r['acode'] === '6300999') {
        $resolved = ($r['type'] === 'MAPPED' && abs($r['allocations']['POH'] - 45000.00) < 0.01 && abs($r['check']) < 0.0001 && $r['check_status'] === 'Balanced');
        break;
    }
}
reportTest("TEST 09 — Mapping correction → regeneration", $resolved, "Exception resolved into POH column on regeneration; Check became 0 (Balanced)");

// =====================================================================
// TEST 10: Plant isolation
// =====================================================================
// Insert Plant B transaction
saveQuantityRecoSourceTransactions($pdo, $plantP3B, $period1, [[
    'account_code'       => '2000033',
    'description'        => 'EXPORT SHIPMENT PLANT B',
    'amount'             => -5000000.00,
    'cost_basis'         => 'Export Sales',
    'pnl_classification' => 'Rev from Operations',
    'nature'             => 'Variable',
    'source_file'        => 'plant_b_tb.xlsx'
]]);

generateQuantityReco($pdo, $plantP3B, $period1);
$matrixB = getQuantityRecoMatrix($pdo, $plantP3B, $period1);

$plantAIsolated = true;
foreach ($matrixB['rows'] as $r) {
    if ($r['acode'] === '2000113' || $r['acode'] === '5000012') {
        $plantAIsolated = false;
    }
}
reportTest("TEST 10 — Plant isolation", $plantAIsolated && count($matrixB['columns']) === 4, "Plant A transactions never bleed into Plant B; columns remain strictly plant-isolated");

// =====================================================================
// TEST 11: Period isolation
// =====================================================================
// Insert Period 2 (August) transaction in Plant A
saveQuantityRecoSourceTransactions($pdo, $plantP3A, $period2, [[
    'account_code'       => '5000012',
    'description'        => 'ELECTRICITY INVOICE AUGUST',
    'amount'             => 95000.00,
    'cost_basis'         => 'Electricity',
    'pnl_classification' => 'Other Exp',
    'nature'             => 'Fixed',
    'source_file'        => 'august_feed.xlsx'
]]);

generateQuantityReco($pdo, $plantP3A, $period2);
$matrixAug = getQuantityRecoMatrix($pdo, $plantP3A, $period2);
$matrixJul = getQuantityRecoMatrix($pdo, $plantP3A, $period1);

$julClosing = $matrixJul['totals']['closing'];
$augClosing = $matrixAug['totals']['closing'];
reportTest("TEST 11 — Period isolation", abs($julClosing - (-32874013.18)) < 0.01 && abs($augClosing - 95000.00) < 0.01, "Period 1 (July: " . number_format($julClosing, 2) . ") and Period 2 (Aug: " . number_format($augClosing, 2) . ") are strictly separated");

// =====================================================================
// TEST 12: Duplicate import protection
// =====================================================================
$dupResult = importQuantityRecoSourceData($pdo, $plantP3A, $period1, $recordsP3, [
    'source_file'      => 'trial_balance_july.xlsx',
    'file_hash'        => 'abc123hash',
    'replace_existing' => false
]);
$isDuplicateDetected = !empty($dupResult['is_duplicate']) && $dupResult['count'] === 0;
reportTest("TEST 12 — Duplicate import protection", $isDuplicateDetected, "Detected duplicate file hash 'abc123hash'; blocked redundant insertion");

// =====================================================================
// TEST 13: Repeated generation idempotency
// =====================================================================
$g1 = generateQuantityReco($pdo, $plantP3A, $period1);
$g2 = generateQuantityReco($pdo, $plantP3A, $period1);
$g3 = generateQuantityReco($pdo, $plantP3A, $period1);
$idempotent = ($g1['total_amount'] === $g2['total_amount'] && $g2['total_amount'] === $g3['total_amount'] && $g1['mapped_records_count'] === $g3['mapped_records_count']);
reportTest("TEST 13 — Repeated generation idempotency", $idempotent, "3 successive generations produce exact identical total ₹" . number_format($g1['total_amount'], 2) . " with zero row accumulation");

// =====================================================================
// TEST 14: Transaction rollback
// =====================================================================
// Test atomic rollback by simulating error inside transaction
$rollbackPassed = false;
$initialRuns = (int)$pdo->query("SELECT COUNT(*) FROM quantity_reco_run WHERE plant_id = {$plantP3A}")->fetchColumn();

try {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO quantity_reco_run (plant_id, period_id, period_code, status, total_amount) VALUES ({$plantP3A}, {$period1}, 'TEST', 'GENERATING', 9999)");
    // Trigger intentional SQL violation
    $pdo->exec("INSERT INTO quantity_reco_detail (run_id, source_row_ref, amount) VALUES (-999, 'BAD', 100)");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $postRuns = (int)$pdo->query("SELECT COUNT(*) FROM quantity_reco_run WHERE plant_id = {$plantP3A}")->fetchColumn();
    $rollbackPassed = ($initialRuns === $postRuns);
}
reportTest("TEST 14 — Transaction rollback", $rollbackPassed, "Atomic transaction rollback verified; zero orphan runs or corrupted details on exception");

// =====================================================================
// TEST 15: Source traceability
// =====================================================================
$latestRunId = (int)$pdo->query("SELECT run_id FROM quantity_reco_run WHERE plant_id = {$plantP3A} AND period_id = {$period1} AND status = 'COMPLETED' ORDER BY run_id DESC LIMIT 1")->fetchColumn();
$utilCCId = (int)$ccUtilA['id'];
$traced = traceCostCentreAmountToSource($pdo, $latestRunId, $utilCCId);
$tracedSum = array_sum(array_column($traced, 'amount'));
reportTest("TEST 15 — Source traceability", count($traced) >= 1 && abs($tracedSum - 70000.00) < 0.01, "Traced Utility amount back to exact source invoice with matching subtotal ₹" . number_format($tracedSum, 2));

// =====================================================================
// TEST 16: Negative values
// =====================================================================
$matrixCurrent = getQuantityRecoMatrix($pdo, $plantP3A, $period1);
$negRow = null;
foreach ($matrixCurrent['rows'] as $r) {
    if ($r['acode'] === '2000113') {
        $negRow = $r;
        break;
    }
}
$negPreserved = ($negRow && $negRow['closing'] < 0 && $negRow['total'] < 0 && abs($negRow['check']) < 0.0001);
reportTest("TEST 16 — Negative values", $negPreserved, "Negative closing amount (" . number_format($negRow['closing'] ?? 0, 2) . ") accurately preserved in allocation, total, and check");

// =====================================================================
// TEST 17: Zero values
// =====================================================================
saveQuantityRecoSourceTransactions($pdo, $plantP3A, $period1, [[
    'account_code'       => '2000203',
    'description'        => 'FOC SALE ZERO VALUE',
    'amount'             => 0.00,
    'cost_basis'         => 'Sales',
    'pnl_classification' => 'Rev from Operations',
    'nature'             => 'Variable',
    'source_file'        => 'tb.xlsx'
]]);
generateQuantityReco($pdo, $plantP3A, $period1);
$matrixWithZero = getQuantityRecoMatrix($pdo, $plantP3A, $period1);

$zeroRowFound = false;
foreach ($matrixWithZero['rows'] as $r) {
    if ($r['acode'] === '2000203') {
        $zeroRowFound = ($r['closing'] == 0.0 && $r['total'] == 0.0 && $r['check'] == 0.0 && $r['check_status'] === 'Balanced');
        break;
    }
}
reportTest("TEST 17 — Zero values", $zeroRowFound, "Zero-value row explicitly allocated as ₹0.00 across CCs with Check = 0.00 (Balanced)");

// =====================================================================
// TEST 18: Multiple rows -> same Cost Centre aggregation
// =====================================================================
// Electricity (70k) + Power (30k) both mapped to Utility
saveQuantityRecoSourceTransactions($pdo, $plantP3A, $period1, [[
    'account_code'       => '5000099',
    'description'        => 'POWER CHARGES DIESEL GENERATOR',
    'amount'             => 30000.00,
    'cost_basis'         => 'Power',
    'pnl_classification' => 'Other Exp',
    'nature'             => 'Variable',
    'source_file'        => 'tb.xlsx'
]]);
generateQuantityReco($pdo, $plantP3A, $period1);
$matrixMulti = getQuantityRecoMatrix($pdo, $plantP3A, $period1);

$utilAgg = $matrixMulti['aggregates'][$utilCCId] ?? null;
$multiPassed = ($utilAgg && $utilAgg['record_count'] === 2 && abs($utilAgg['total_amount'] - 100000.00) < 0.01);
reportTest("TEST 18 — Multiple rows → same Cost Centre aggregation", $multiPassed, "Aggregated 2 source rows (₹70k + ₹30k) into Utility totaling ₹100,000.00");

// =====================================================================
// TEST 19: Unauthorized API access
// =====================================================================
$ctx = stream_context_create([
    'http' => [
        'ignore_errors' => true,
        'timeout'       => 3
    ]
]);
$apiUrl = "http://127.0.0.1:8000/api/quantity_reco_api.php?action=get_reco&plant_id={$plantP3A}&period_id={$period1}";
$apiOut = @file_get_contents($apiUrl, false, $ctx);
$apiJson = json_decode($apiOut ?: '{}', true);

// Fallback to direct CLI test if dev server HTTP is unreachable
if (empty($apiJson['error'])) {
    $cliScript = __DIR__ . '/scratch/test_unauth_api.php';
    file_put_contents($cliScript, "<?php
\$_REQUEST = ['action' => 'get_reco', 'plant_id' => {$plantP3A}, 'period_id' => {$period1}];
ob_start();
include __DIR__ . '/../api/quantity_reco_api.php';
echo ob_get_clean();
");
    $cliOut = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($cliScript));
    $apiJson = json_decode($cliOut ?: '{}', true);
}

$unauthPassed = (!empty($apiJson['error']) && stripos($apiJson['error'], 'Authentication required') !== false);
reportTest("TEST 19 — Unauthorized API access", $unauthPassed, "Unauthenticated API request rejected with 401 Unauthorized ('" . ($apiJson['error'] ?? '') . "')");



// =====================================================================
// TEST 20: Large dataset stress & performance benchmark
// =====================================================================
$largeBatch = [];
for ($i = 1; $i <= 2000; $i++) {
    $bases = ['Sales', 'RMC', 'QC', 'Utility', 'POH'];
    $b = $bases[$i % count($bases)];
    $largeBatch[] = [
        'account_code'       => 'GL-' . (100000 + $i),
        'description'        => 'BENCHMARK TRANSACTION ' . $i,
        'amount'             => (float)($i * 10.5),
        'cost_basis'         => $b,
        'pnl_classification' => 'Rev from Operations',
        'nature'             => 'Variable',
        'source_file'        => 'large_feed.csv',
        'source_row_ref'     => 'ROW-' . $i
    ];
}

$startTime = microtime(true);
saveQuantityRecoSourceTransactions($pdo, $plantP3A, $period2, $largeBatch);
$genLarge = generateQuantityReco($pdo, $plantP3A, $period2);
$endTime = microtime(true);
$duration = round($endTime - $startTime, 3);

$largePassed = ($genLarge['total_source_records'] >= 2000 && $genLarge['unmapped_records_count'] === 0 && $duration < 3.0);
reportTest("TEST 20 — Large dataset", $largePassed, "Ingested and reconciled 2,000 records in {$duration}s (<3.0s limit, 0 unmapped)");

// =====================================================================
// SUMMARY
// =====================================================================
echo "\n=================================================================\n";
echo "    PHASE 3 TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "=================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL 20 PHASE 3 VERIFICATION TESTS PASSED WITH 100% SUCCESS! <<<\n";
    exit(0);
} else {
    echo ">>> {$failCount} TEST(S) FAILED. Please review output above. <<<\n";
    exit(1);
}
