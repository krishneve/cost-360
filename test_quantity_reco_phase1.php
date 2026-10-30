<?php
/**
 * Cost360 — Quantity Reco Phase 1 Verification Suite
 *
 * Validates the Quantity Reco Calculation Engine, Schema, and APIs:
 * - Deterministic scenario from specification
 * - Multi-source row aggregation into single Cost Centre
 * - Configurable mapping resolution & alias mappings
 * - Unmapped Cost Basis handling (isolated to exceptions, never silently dropped)
 * - Inactive Cost Centre isolation
 * - Cross-Plant isolation (preventing cross-plant pollution)
 * - Multi-Plant distinct dynamic headers (Plant A vs Plant B)
 * - Zero and negative amounts handling
 * - Regeneration idempotency (no duplicate amounts)
 * - Source traceability (Utility 70,000 drills down to 50,000 and 20,000)
 * - Period isolation
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/quantity_reco.php';

$pdo = getDbConnection();

echo "====================================================================\n";
echo "COST360 — QUANTITY RECO ENGINE FULL PHASE 1 VERIFICATION SUITE\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(int $testNum, string $title, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] TEST " . sprintf("%02d", $testNum) . ": {$title}\n";
        if ($details) echo "        Details: {$details}\n";
    } else {
        $failCount++;
        echo " [FAIL] TEST " . sprintf("%02d", $testNum) . ": {$title}\n";
        if ($details) echo "        Failure details: {$details}\n";
    }
}

// Setup dedicated test fixtures in database
echo "--> Setting up test fixtures (Plant A, Plant B, Period 1, Period 2)...\n";

// Ensure division exists for company 1
$divStmt = $pdo->prepare("SELECT division_id FROM divisions WHERE company_id = 1 LIMIT 1");
$divStmt->execute();
$divisionId = (int)$divStmt->fetchColumn();
if (!$divisionId) {
    $insDiv = $pdo->query("INSERT INTO divisions (company_id, division_code, division_name, status) VALUES (1, 'DIV_TEST', 'Testing Division', 'Active') RETURNING division_id");
    $divisionId = (int)$insDiv->fetchColumn();
}

// 1. Setup Test Plant A
$pAStmt = $pdo->prepare("SELECT plant_id FROM plants WHERE division_id = ? AND plant_code = 'PLANT_TEST_A' LIMIT 1");
$pAStmt->execute([$divisionId]);
$plantAId = (int)$pAStmt->fetchColumn();
if (!$plantAId) {
    $insPA = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, location, status) VALUES (?, 'PLANT_TEST_A', 'Plant A (Test)', 'Nashik Unit A', 'Active') RETURNING plant_id");
    $insPA->execute([$divisionId]);
    $plantAId = (int)$insPA->fetchColumn();
}

// 2. Setup Test Plant B
$pBStmt = $pdo->prepare("SELECT plant_id FROM plants WHERE division_id = ? AND plant_code = 'PLANT_TEST_B' LIMIT 1");
$pBStmt->execute([$divisionId]);
$plantBId = (int)$pBStmt->fetchColumn();
if (!$plantBId) {
    $insPB = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, location, status) VALUES (?, 'PLANT_TEST_B', 'Plant B (Test)', 'Pune Unit B', 'Active') RETURNING plant_id");
    $insPB->execute([$divisionId]);
    $plantBId = (int)$insPB->fetchColumn();
}

// 3. Setup Test Periods
$periodStmt = $pdo->query("SELECT period_id FROM periods WHERE period_name = 'July 2026' LIMIT 1");
$period1Id = (int)$periodStmt->fetchColumn();
if (!$period1Id) {
    $insP1 = $pdo->query("INSERT INTO periods (period_name, start_date, end_date, status) VALUES ('July 2026', '2026-07-01', '2026-07-31', 'Active') RETURNING period_id");
    $period1Id = (int)$insP1->fetchColumn();
}

$period2Stmt = $pdo->query("SELECT period_id FROM periods WHERE period_name = 'August 2026' LIMIT 1");
$period2Id = (int)$period2Stmt->fetchColumn();
if (!$period2Id) {
    $insP2 = $pdo->query("INSERT INTO periods (period_name, start_date, end_date, status) VALUES ('August 2026', '2026-08-01', '2026-08-31', 'Active') RETURNING period_id");
    $period2Id = (int)$insP2->fetchColumn();
}

// Clean previous test data for Plant A and Plant B
$pdo->exec("DELETE FROM quantity_reco_detail WHERE run_id IN (SELECT run_id FROM quantity_reco_run WHERE plant_id IN ({$plantAId}, {$plantBId}))");
$pdo->exec("DELETE FROM quantity_reco_exception WHERE plant_id IN ({$plantAId}, {$plantBId})");
$pdo->exec("DELETE FROM quantity_reco_run WHERE plant_id IN ({$plantAId}, {$plantBId})");
$pdo->exec("DELETE FROM quantity_reco_source_transactions WHERE plant_id IN ({$plantAId}, {$plantBId})");
$pdo->exec("DELETE FROM cost_basis_mapping WHERE plant_id IN ({$plantAId}, {$plantBId})");
$pdo->exec("DELETE FROM cost_center_master WHERE plant_id IN ({$plantAId}, {$plantBId})");

echo "Test fixtures initialized. Plant A ID: {$plantAId}, Plant B ID: {$plantBId}, Period 1 ID: {$period1Id}, Period 2 ID: {$period2Id}\n\n";

// =====================================================================
// TEST 1: Cost Centre Master Dynamic Setup for Plant A
// =====================================================================
$plantACostCentres = [
    ['code' => 'SALES', 'name' => 'Sales', 'basis' => 'Sales', 'order' => 1],
    ['code' => 'RMC', 'name' => 'RMC', 'basis' => 'RMC', 'order' => 2],
    ['code' => 'QC', 'name' => 'QC', 'basis' => 'QC', 'order' => 3],
    ['code' => 'UTILITY', 'name' => 'Utility', 'basis' => 'Utility', 'order' => 4],
    ['code' => 'REPAIRS', 'name' => 'Repairs & Maint', 'basis' => 'Repairs & Maint', 'order' => 5],
];

$savedCCMapPlantA = [];
foreach ($plantACostCentres as $cc) {
    $res = saveCostCentre($pdo, [
        'plant_id' => $plantAId,
        'cost_center_code' => $cc['code'],
        'cost_center_name' => $cc['name'],
        'cost_basis_code' => $cc['basis'],
        'display_order' => $cc['order']
    ]);
    $savedCCMapPlantA[$cc['code']] = (int)$res['id'];
}

$retrievedCCA = getPlantCostCentres($pdo, $plantAId, true);
assertTest(
    1,
    "Cost Centre Master for Plant A supports dynamic headers",
    count($retrievedCCA) === 5 && isset($savedCCMapPlantA['UTILITY']),
    "Created 5 Cost Centres: Sales, RMC, QC, Utility, Repairs & Maint"
);

// =====================================================================
// TEST 2: Different Plants have different Cost Centres (Plant B Setup)
// =====================================================================
$plantBCostCentres = [
    ['code' => 'SALES', 'name' => 'Sales', 'basis' => 'Sales', 'order' => 1],
    ['code' => 'EXPORT_SALES', 'name' => 'Export Sales', 'basis' => 'Export Sales', 'order' => 2],
    ['code' => 'RMC', 'name' => 'RMC', 'basis' => 'RMC', 'order' => 3],
    ['code' => 'UTILITY', 'name' => 'Utility', 'basis' => 'Utility', 'order' => 4],
    ['code' => 'AOH', 'name' => 'AOH', 'basis' => 'AOH', 'order' => 5],
];

$savedCCMapPlantB = [];
foreach ($plantBCostCentres as $cc) {
    $res = saveCostCentre($pdo, [
        'plant_id' => $plantBId,
        'cost_center_code' => $cc['code'],
        'cost_center_name' => $cc['name'],
        'cost_basis_code' => $cc['basis'],
        'display_order' => $cc['order']
    ]);
    $savedCCMapPlantB[$cc['code']] = (int)$res['id'];
}

$retrievedCCB = getPlantCostCentres($pdo, $plantBId, true);
$plantBHeaders = array_column($retrievedCCB, 'cost_center_name');
assertTest(
    2,
    "Plant B has distinct dynamic Cost Centres without hard-coding",
    count($retrievedCCB) === 5 && in_array('Export Sales', $plantBHeaders) && in_array('AOH', $plantBHeaders) && !in_array('QC', $plantBHeaders),
    "Plant B headers: " . implode(', ', $plantBHeaders)
);

// =====================================================================
// TEST 3: Configurable Cost Basis Mapping for Plant A
// =====================================================================
// Aliases:
// "Electricity" -> Utility
// "Power"       -> Utility
// "Raw Material"-> RMC
// "QC Testing"  -> QC
// "Machine Repair" -> Repairs & Maint
// "Product Sale"-> Sales
$mappingsPlantA = [
    ['basis' => 'Electricity', 'cc_id' => $savedCCMapPlantA['UTILITY'], 'priority' => 10],
    ['basis' => 'Power', 'cc_id' => $savedCCMapPlantA['UTILITY'], 'priority' => 20],
    ['basis' => 'Raw Material', 'cc_id' => $savedCCMapPlantA['RMC'], 'priority' => 10],
    ['basis' => 'QC Testing', 'cc_id' => $savedCCMapPlantA['QC'], 'priority' => 10],
    ['basis' => 'Machine Repair', 'cc_id' => $savedCCMapPlantA['REPAIRS'], 'priority' => 10],
    ['basis' => 'Product Sale', 'cc_id' => $savedCCMapPlantA['SALES'], 'priority' => 10],
];

foreach ($mappingsPlantA as $m) {
    saveCostBasisMapping($pdo, [
        'plant_id' => $plantAId,
        'source_cost_basis' => $m['basis'],
        'cost_centre_id' => $m['cc_id'],
        'priority' => $m['priority']
    ]);
}

$retrievedMappingsA = getPlantCostBasisMappings($pdo, $plantAId, true);
assertTest(
    3,
    "Cost Basis Mapping correctly configured in database (no hardcoding)",
    count($retrievedMappingsA) === 6,
    "6 mappings configured: Electricity->Utility, Power->Utility, Raw Material->RMC, etc."
);

// =====================================================================
// TEST 4: Controlled Sample Dataset Ingestion (Specification Scenario)
// =====================================================================
$specRecords = [
    ['description' => 'Electricity Invoice July', 'amount' => 50000.00, 'cost_basis' => 'Electricity', 'source' => 'TB', 'account_code' => '5001', 'source_row_ref' => 'TB-001'],
    ['description' => 'Power & Generator Charges', 'amount' => 20000.00, 'cost_basis' => 'Power', 'source' => 'TB', 'account_code' => '5002', 'source_row_ref' => 'TB-002'],
    ['description' => 'Raw Material Steel Ingot', 'amount' => 500000.00, 'cost_basis' => 'Raw Material', 'source' => 'TB', 'account_code' => '4001', 'source_row_ref' => 'TB-003'],
    ['description' => 'QC Testing Equipment Reagents', 'amount' => 25000.00, 'cost_basis' => 'QC Testing', 'source' => 'TB', 'account_code' => '6001', 'source_row_ref' => 'TB-004'],
    ['description' => 'Machine Repair Lathe 01', 'amount' => 30000.00, 'cost_basis' => 'Machine Repair', 'source' => 'TB', 'account_code' => '7001', 'source_row_ref' => 'TB-005'],
    ['description' => 'Product Sale Automotive Line', 'amount' => 200000.00, 'cost_basis' => 'Product Sale', 'source' => 'TB', 'account_code' => '1001', 'source_row_ref' => 'TB-006']
];

$insertedCount = saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, $specRecords);
assertTest(
    4,
    "Source transactions inserted into normalized storage",
    $insertedCount === 6,
    "6 source transactions stored for Plant A, Period 1"
);

// =====================================================================
// TEST 5: Quantity Reco Generation & Aggregation (Core Mathematical Proof)
// =====================================================================
$genResult = generateQuantityReco($pdo, $plantAId, $period1Id);
$recoMatrix = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);

$aggs = $recoMatrix['aggregates'];
$utilityAmt = $aggs[$savedCCMapPlantA['UTILITY']]['total_amount'] ?? 0;
$rmcAmt = $aggs[$savedCCMapPlantA['RMC']]['total_amount'] ?? 0;
$qcAmt = $aggs[$savedCCMapPlantA['QC']]['total_amount'] ?? 0;
$repairsAmt = $aggs[$savedCCMapPlantA['REPAIRS']]['total_amount'] ?? 0;
$salesAmt = $aggs[$savedCCMapPlantA['SALES']]['total_amount'] ?? 0;

$mathMatches = ($salesAmt == 200000.00) &&
               ($rmcAmt == 500000.00) &&
               ($qcAmt == 25000.00) &&
               ($utilityAmt == 70000.00) &&
               ($repairsAmt == 30000.00);

assertTest(
    5,
    "Specification Expected Result matches exactly: Sales=200k, RMC=500k, QC=25k, Utility=70k, Repairs=30k",
    $mathMatches,
    "Generated: Sales={$salesAmt}, RMC={$rmcAmt}, QC={$qcAmt}, Utility={$utilityAmt}, Repairs={$repairsAmt}"
);

// =====================================================================
// TEST 6: Multiple Source Rows Aggregated into Single Cost Centre
// =====================================================================
$utilityRecordCount = $aggs[$savedCCMapPlantA['UTILITY']]['record_count'] ?? 0;
assertTest(
    6,
    "Multiple source rows aggregate into single Cost Centre (Electricity 50k + Power 20k = Utility 70k)",
    $utilityRecordCount === 2 && $utilityAmt == 70000.00,
    "Utility record_count is {$utilityRecordCount}, aggregated sum is {$utilityAmt}"
);

// =====================================================================
// TEST 7: Source-Level Traceability / Audit Drill-Down
// =====================================================================
$runId = (int)$genResult['run_id'];
$traceUtility = traceCostCentreAmountToSource($pdo, $runId, $savedCCMapPlantA['UTILITY']);
$hasElec = false;
$hasPower = false;
foreach ($traceUtility as $item) {
    if ($item['source_cost_basis'] === 'Electricity' && (float)$item['amount'] == 50000.00) $hasElec = true;
    if ($item['source_cost_basis'] === 'Power' && (float)$item['amount'] == 20000.00) $hasPower = true;
}

assertTest(
    7,
    "Source-level auditability traces 70,000 back to 50,000 and 20,000",
    count($traceUtility) === 2 && $hasElec && $hasPower,
    "Traced records: " . json_encode(array_column($traceUtility, 'source_description'))
);

// =====================================================================
// TEST 8: Unmapped Cost Basis Isolation (Never Dropped, Never Randomly Assigned)
// =====================================================================
// Add an unmapped record "Factory Security" = 45,000
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    ['description' => 'Factory Security Guard Service', 'amount' => 45000.00, 'cost_basis' => 'Factory Security', 'source' => 'TB', 'source_row_ref' => 'TB-UNMAPPED-1']
]);

// Regenerate
$genResult2 = generateQuantityReco($pdo, $plantAId, $period1Id);
$recoMatrix2 = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$exceptions = $recoMatrix2['unmapped_exceptions'];

$foundUnmapped = false;
foreach ($exceptions as $exc) {
    if ($exc['cost_basis'] === 'Factory Security' && (float)$exc['amount'] == 45000.00) {
        $foundUnmapped = true;
        break;
    }
}

// Ensure the 45k was NOT added into any Cost Centre
$utilityAmt2 = $recoMatrix2['aggregates'][$savedCCMapPlantA['UTILITY']]['total_amount'] ?? 0;
$salesAmt2 = $recoMatrix2['aggregates'][$savedCCMapPlantA['SALES']]['total_amount'] ?? 0;

assertTest(
    8,
    "Unmapped Cost Basis ('Factory Security') captured in exceptions table with reason, not dropped or forced",
    $foundUnmapped && $recoMatrix2['exceptions_count'] === 1 && $utilityAmt2 == 70000.00 && $salesAmt2 == 200000.00,
    "Found in exception table: cost_basis='{$exceptions[0]['cost_basis']}', amount={$exceptions[0]['amount']}, reason='{$exceptions[0]['reason']}'"
);

// =====================================================================
// TEST 9: Inactive Cost Centre Rejection
// =====================================================================
// Create an inactive Cost Centre in Plant A and map a Cost Basis to it
$inactiveCC = saveCostCentre($pdo, [
    'plant_id' => $plantAId,
    'cost_center_code' => 'INACTIVE_CC',
    'cost_center_name' => 'Discontinued Dept',
    'cost_basis_code' => 'DISCONTINUED',
    'is_active' => false
]);

// Attempt to add mapping
saveCostBasisMapping($pdo, [
    'plant_id' => $plantAId,
    'source_cost_basis' => 'Old Printing Cost',
    'cost_centre_id' => $inactiveCC['id'],
    'is_active' => true
]);

saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    ['description' => 'Old Printing Cost Record', 'amount' => 12000.00, 'cost_basis' => 'Old Printing Cost', 'source' => 'TB', 'source_row_ref' => 'TB-INACTIVE-1']
]);

generateQuantityReco($pdo, $plantAId, $period1Id);
$recoMatrix3 = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);

$inactiveExceptionFound = false;
foreach ($recoMatrix3['unmapped_exceptions'] as $exc) {
    if ($exc['cost_basis'] === 'Old Printing Cost' && stripos($exc['reason'], 'inactive') !== false) {
        $inactiveExceptionFound = true;
        break;
    }
}

assertTest(
    9,
    "Inactive Cost Centre correctly rejected and recorded in exceptions",
    $inactiveExceptionFound,
    "Exception reason: " . ($recoMatrix3['unmapped_exceptions'][0]['reason'] ?? 'None')
);

// =====================================================================
// TEST 10: Cross-Plant Cost Centre Mapping Prohibited
// =====================================================================
// Attempt to map Plant A source basis to Plant B's Cost Centre
$crossPlantBlocked = false;
$crossPlantError = '';
try {
    saveCostBasisMapping($pdo, [
        'plant_id' => $plantAId,
        'source_cost_basis' => 'Export Shipping',
        'cost_centre_id' => $savedCCMapPlantB['EXPORT_SALES'] // belongs to Plant B!
    ]);
} catch (InvalidArgumentException $e) {
    $crossPlantBlocked = true;
    $crossPlantError = $e->getMessage();
}

assertTest(
    10,
    "Cross-Plant Cost Centre mapping is strictly prohibited by validation",
    $crossPlantBlocked,
    "Expected exception caught: {$crossPlantError}"
);

// =====================================================================
// TEST 11: Plant Data Isolation (Plant A records must not appear in Plant B)
// =====================================================================
// Ingest records for Plant B
saveQuantityRecoSourceTransactions($pdo, $plantBId, $period1Id, [
    ['description' => 'Plant B Machine Export Sale', 'amount' => 350000.00, 'cost_basis' => 'Export Sales', 'source' => 'TB', 'source_row_ref' => 'PB-001'],
    ['description' => 'Plant B Power Bills', 'amount' => 40000.00, 'cost_basis' => 'Utility', 'source' => 'TB', 'source_row_ref' => 'PB-002']
]);

generateQuantityReco($pdo, $plantBId, $period1Id);
$recoMatrixB = getQuantityRecoMatrix($pdo, $plantBId, $period1Id);

$plantBExportSales = $recoMatrixB['aggregates'][$savedCCMapPlantB['EXPORT_SALES']]['total_amount'] ?? 0;
$plantBUtility = $recoMatrixB['aggregates'][$savedCCMapPlantB['UTILITY']]['total_amount'] ?? 0;

// Verify Plant A does not have Plant B's 350k or 40k
$recoMatrixA_Fresh = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$plantAUtilityFresh = $recoMatrixA_Fresh['aggregates'][$savedCCMapPlantA['UTILITY']]['total_amount'] ?? 0;

assertTest(
    11,
    "Strict Plant Data Isolation: Plant B has separate 350k export & 40k utility, Plant A remains 70k",
    $plantBExportSales == 350000.00 && $plantBUtility == 40000.00 && $plantAUtilityFresh == 70000.00,
    "Plant B Export Sales: {$plantBExportSales}, Plant B Utility: {$plantBUtility}, Plant A Utility: {$plantAUtilityFresh}"
);

// =====================================================================
// TEST 12: Period Isolation (Period 1 data must not appear in Period 2)
// =====================================================================
// Ingest record for Period 2 in Plant A
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period2Id, [
    ['description' => 'August Electricity', 'amount' => 88000.00, 'cost_basis' => 'Electricity', 'source' => 'TB', 'source_row_ref' => 'P2-001']
]);

generateQuantityReco($pdo, $plantAId, $period2Id);
$recoP2 = getQuantityRecoMatrix($pdo, $plantAId, $period2Id);
$recoP1 = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);

$p2Utility = $recoP2['aggregates'][$savedCCMapPlantA['UTILITY']]['total_amount'] ?? 0;
$p1Utility = $recoP1['aggregates'][$savedCCMapPlantA['UTILITY']]['total_amount'] ?? 0;

assertTest(
    12,
    "Strict Period Isolation: July Utility is 70k, August Utility is 88k (no bleed-through)",
    $p1Utility == 70000.00 && $p2Utility == 88000.00,
    "Period 1 Utility: {$p1Utility}, Period 2 Utility: {$p2Utility}"
);

// =====================================================================
// TEST 13: Zero and Negative Amounts Handling
// =====================================================================
// Add zero amount and negative discount/credit
saveQuantityRecoSourceTransactions($pdo, $plantAId, $period1Id, [
    ['description' => 'Zero Amount Free Sample', 'amount' => 0.00, 'cost_basis' => 'Raw Material', 'source' => 'TB', 'source_row_ref' => 'TB-ZERO-1'],
    ['description' => 'RMC Supplier Credit Note', 'amount' => -15000.00, 'cost_basis' => 'Raw Material', 'source' => 'TB', 'source_row_ref' => 'TB-NEG-1']
]);

generateQuantityReco($pdo, $plantAId, $period1Id);
$recoWithNeg = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
$rmcAdjusted = $recoWithNeg['aggregates'][$savedCCMapPlantA['RMC']]['total_amount'] ?? 0;

// Original 500k + 0 + (-15k) = 485,000
assertTest(
    13,
    "Zero and Negative amounts handled correctly: 500k - 15k credit = 485k",
    $rmcAdjusted == 485000.00,
    "Adjusted RMC: {$rmcAdjusted}"
);

// =====================================================================
// TEST 14: Regeneration Idempotency (Regenerating 5 times produces same result)
// =====================================================================
$idempotent = true;
for ($i = 0; $i < 5; $i++) {
    $r = generateQuantityReco($pdo, $plantAId, $period1Id);
    $m = getQuantityRecoMatrix($pdo, $plantAId, $period1Id);
    $curRmc = $m['aggregates'][$savedCCMapPlantA['RMC']]['total_amount'] ?? 0;
    if ($curRmc != 485000.00) {
        $idempotent = false;
        break;
    }
}

// Verify no duplicate runs exist for this plant + period
$runCountStmt = $pdo->prepare("SELECT COUNT(*) FROM quantity_reco_run WHERE plant_id = ? AND period_id = ?");
$runCountStmt->execute([$plantAId, $period1Id]);
$activeRunCount = (int)$runCountStmt->fetchColumn();

assertTest(
    14,
    "Regeneration idempotency: 5 successive generations produce exact same amounts without duplicate rows",
    $idempotent && $activeRunCount === 1,
    "Active runs for Plant A, Period 1: {$activeRunCount}, RMC amount preserved: {$curRmc}"
);

// =====================================================================
// Summary
// =====================================================================
echo "\n====================================================================\n";
echo "QUANTITY RECO PHASE 1 TEST SUITE SUMMARY\n";
echo "Total Tests Executed : " . ($passCount + $failCount) . "\n";
echo "Passed               : {$passCount}\n";
echo "Failed               : {$failCount}\n";
echo "====================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL PHASE 1 TESTS PASSED WITH 100% SUCCESS! <<<\n\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED. PLEASE REVIEW FAILURE LOGS. <<<\n\n";
    exit(1);
}
