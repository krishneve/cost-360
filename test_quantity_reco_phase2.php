<?php
/**
 * COST360 — QUANTITY RECO PHASE 2 AUTOMATED TEST SUITE
 * 
 * Verifies all 12 Phase 2 Frontend & Integration Specifications:
 * - TEST 01: Dynamic Headers (Plant A)
 * - TEST 02: Different Plant (Plant B column distinctness)
 * - TEST 03: Correct Values (Matrix amounts match Phase 1 dataset)
 * - TEST 04: Multiple Source Rows Aggregation (50k + 20k -> 70k)
 * - TEST 05: Traceability API & Drilldown Data (Utility 70k -> Electricity + Power)
 * - TEST 06: Unmapped Exceptions (Factory Security isolated, not in CC matrix)
 * - TEST 07: Plant Isolation (Plant A vs Plant B data isolation)
 * - TEST 08: Period Isolation (July 2026 vs August 2026)
 * - TEST 09: Regeneration Idempotency (No duplicated records or amounts)
 * - TEST 10: Loading & Error States (Validation errors, 400/404 handling)
 * - TEST 11: Large Number of Cost Centres & Zero Hardcoded Columns (Source code audit)
 * - TEST 12: Permissions & RBAC Access Control (Role & menu checks)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/quantity_reco.php';

$pdo = getDbConnection();

echo "=================================================================\n";
echo "    COST360 — QUANTITY RECO PHASE 2 TEST SUITE\n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTestPhase2(string $testName, bool $condition, string $detail = '') {
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
// SETUP / SEED TEST SCOPES
// -------------------------------------------------------------
$plantAId = 35;
$plantBId = 36;
$period1Id = 1; // July 2026
$period2Id = 2; // August 2026

// Helper to invoke API via clean subprocess
function callApiSubprocess(string $action, array $params = [], string $method = 'GET', int $userId = 1): array {
    $script = __DIR__ . '/scratch/tmp_api_runner.php';
    $code = '<?php' . "\n";
    $code .= 'session_start();' . "\n";
    $code .= '$_SESSION["user_id"] = ' . $userId . ';' . "\n";
    $code .= '$_SESSION["user"] = ["user_id" => ' . $userId . ', "role_code" => "PLATFORM_ADMIN"];' . "\n";
    if ($method === 'GET') {
        $code .= '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n";
        $code .= '$_GET = ' . var_export(array_merge(['action' => $action], $params), true) . ';' . "\n";
        $code .= '$_POST = [];' . "\n";
    } else {
        $code .= '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n";
        $code .= '$_POST = ' . var_export(array_merge(['action' => $action], $params), true) . ';' . "\n";
        $code .= '$_GET = [];' . "\n";
    }
    $code .= '$_REQUEST = array_merge($_GET, $_POST);' . "\n";
    $code .= 'ob_start();' . "\n";
    $code .= 'require ' . var_export(__DIR__ . '/api/quantity_reco_api.php', true) . ';' . "\n";
    $code .= '$out = ob_get_clean();' . "\n";
    $code .= 'echo $out;' . "\n";

    file_put_contents($script, $code);
    $output = shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);

    $json = json_decode(trim((string)$output), true);
    return is_array($json) ? $json : ['ok' => false, 'raw' => $output];
}

// Ensure Phase 1 seed data is refreshed
shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg(__DIR__ . '/test_quantity_reco_phase1.php'));
$genA = callApiSubprocess('generate', ['plant_id' => $plantAId, 'period_id' => $period1Id], 'POST');
assertTestPhase2("Preparation: Generate Plant A (July 2026)", $genA['ok'] ?? false, "Mapped: ₹" . number_format((float)($genA['summary']['mapped_amount'] ?? 0)));

// -------------------------------------------------------------
// TEST 01: Dynamic Headers for Plant A
// -------------------------------------------------------------
$ccA = callApiSubprocess('get_cost_centres', ['plant_id' => $plantAId]);
$ccANames = array_column($ccA['cost_centres'] ?? [], 'cost_centre_name');
$hasSalesA = in_array('Sales', $ccANames);
$hasRmcA = in_array('RMC', $ccANames);
$hasQcA = in_array('QC', $ccANames);
$hasUtilityA = in_array('Utility', $ccANames);
$hasRepairsA = in_array('Repairs & Maint', $ccANames);

assertTestPhase2(
    "TEST 01 — Dynamic Headers (Plant A)",
    $ccA['ok'] && $hasSalesA && $hasRmcA && $hasQcA && $hasUtilityA && $hasRepairsA,
    "Columns dynamically resolved: " . implode(', ', $ccANames)
);

// -------------------------------------------------------------
// TEST 02: Different Plant Column Distinctness (Plant B)
// -------------------------------------------------------------
// Configure Plant B with distinct Cost Centres
// Plant B has: Sales, Export Sales, RMC, Utility, AOH (no QC, no Repairs & Maint)
$ccB = callApiSubprocess('get_cost_centres', ['plant_id' => $plantBId]);
$ccBNames = array_column($ccB['cost_centres'] ?? [], 'cost_centre_name');
$hasExportSalesB = in_array('Export Sales', $ccBNames);
$noQcB = !in_array('QC', $ccBNames);
$noRepairsB = !in_array('Repairs & Maint', $ccBNames);

assertTestPhase2(
    "TEST 02 — Different Plant (Plant B Column Distinctness)",
    $ccB['ok'] && $hasExportSalesB && $noQcB && $noRepairsB,
    "Plant B columns: " . implode(', ', $ccBNames) . " (Correctly excludes QC & Repairs)"
);

// -------------------------------------------------------------
// TEST 03: Correct Values Matching Specification
// -------------------------------------------------------------
// Expected for Plant A (Period 1):
// Sales: 200,000 | RMC: 485,000 (500k - 15k credit) | QC: 25,000 | Utility: 70,000 | Repairs: 30,000
$recoA = callApiSubprocess('get_reco', ['plant_id' => $plantAId, 'period_id' => $period1Id]);
$aggregatesA = $recoA['aggregates'] ?? [];

$aggByName = [];
foreach ($recoA['columns'] ?? [] as $col) {
    $cId = $col['cost_centre_id'];
    $aggByName[$col['cost_centre_name']] = (float)($aggregatesA[$cId]['total_amount'] ?? 0);
}

$salesCorrect = abs(($aggByName['Sales'] ?? 0) - 200000) < 0.01;
$rmcCorrect = abs(($aggByName['RMC'] ?? 0) - 485000) < 0.01;
$qcCorrect = abs(($aggByName['QC'] ?? 0) - 25000) < 0.01;
$utilCorrect = abs(($aggByName['Utility'] ?? 0) - 70000) < 0.01;
$repairCorrect = abs(($aggByName['Repairs & Maint'] ?? 0) - 30000) < 0.01;

assertTestPhase2(
    "TEST 03 — Correct Values (Matrix Amounts)",
    $salesCorrect && $rmcCorrect && $qcCorrect && $utilCorrect && $repairCorrect,
    sprintf("Sales: ₹%s, RMC: ₹%s, QC: ₹%s, Utility: ₹%s, Repairs: ₹%s", 
        number_format($aggByName['Sales'] ?? 0), number_format($aggByName['RMC'] ?? 0), 
        number_format($aggByName['QC'] ?? 0), number_format($aggByName['Utility'] ?? 0), 
        number_format($aggByName['Repairs & Maint'] ?? 0))
);

// -------------------------------------------------------------
// TEST 04: Multiple Source Rows Aggregation
// -------------------------------------------------------------
// Electricity (50,000) + Power (20,000) -> Utility (70,000)
$utilCol = null;
foreach ($recoA['columns'] ?? [] as $c) {
    if ($c['cost_centre_name'] === 'Utility') {
        $utilCol = $c;
        break;
    }
}
$utilAgg = $aggregatesA[$utilCol['cost_centre_id'] ?? 0] ?? null;
$utilRecordsCount = (int)($utilAgg['record_count'] ?? 0);
$utilAmount = (float)($utilAgg['total_amount'] ?? 0);

assertTestPhase2(
    "TEST 04 — Multiple Source Rows Aggregation",
    $utilRecordsCount === 2 && abs($utilAmount - 70000) < 0.01,
    "Utility aggregated from {$utilRecordsCount} records = ₹" . number_format($utilAmount)
);

// -------------------------------------------------------------
// TEST 05: Traceability API & Audit Drilldown
// -------------------------------------------------------------
$runIdA = $recoA['run']['run_id'] ?? 0;
$traceUtil = callApiSubprocess('trace_source', [
    'run_id' => $runIdA,
    'cost_centre_id' => $utilCol['cost_centre_id']
]);
$traceRecords = $traceUtil['source_records'] ?? [];
$traceCount = count($traceRecords);
$traceSubtotal = array_sum(array_column($traceRecords, 'amount'));

assertTestPhase2(
    "TEST 05 — Source Traceability Drilldown",
    $traceUtil['ok'] && $traceCount === 2 && abs($traceSubtotal - 70000) < 0.01,
    "Returned {$traceCount} source records totaling ₹" . number_format($traceSubtotal) . " (" . implode(' + ', array_map(fn($r) => '₹' . number_format((float)$r['amount']) . ' ' . ($r['source_description'] ?? 'Txn'), $traceRecords)) . ")"
);

// -------------------------------------------------------------
// TEST 06: Unmapped Exceptions Isolated
// -------------------------------------------------------------
$exceptionsResp = callApiSubprocess('get_exceptions', [
    'run_id' => $runIdA,
    'plant_id' => $plantAId,
    'period_id' => $period1Id
]);
$exceptions = $exceptionsResp['exceptions'] ?? [];
$hasFactorySecurity = false;
foreach ($exceptions as $exc) {
    if (($exc['cost_basis'] ?? '') === 'Factory Security') {
        $hasFactorySecurity = true;
        break;
    }
}

// Ensure Factory Security is NOT present in detailed matrix allocation
$detailsA = $recoA['details'] ?? [];
$unmappedInDetails = false;
foreach ($detailsA as $d) {
    if (($d['source_cost_basis'] ?? '') === 'Factory Security') {
        $unmappedInDetails = true;
        break;
    }
}

assertTestPhase2(
    "TEST 06 — Unmapped Exceptions",
    $exceptionsResp['ok'] && $hasFactorySecurity && !$unmappedInDetails,
    "Factory Security exception safely isolated in exceptions table and excluded from Cost Centre matrix"
);

// -------------------------------------------------------------
// TEST 07: Plant Isolation
// -------------------------------------------------------------
// Plant B has separate generation (350k export & 40k utility from Phase 1 Test 11)
$genB = callApiSubprocess('generate', ['plant_id' => $plantBId, 'period_id' => $period1Id], 'POST');
$recoB = callApiSubprocess('get_reco', ['plant_id' => $plantBId, 'period_id' => $period1Id]);

$recoACheck = callApiSubprocess('get_reco', ['plant_id' => $plantAId, 'period_id' => $period1Id]);
$grandTotalA = (float)($recoACheck['grand_total'] ?? 0);
$grandTotalB = (float)($recoB['grand_total'] ?? 0);

assertTestPhase2(
    "TEST 07 — Plant Isolation",
    abs($grandTotalA - 810000) < 0.01 && $grandTotalB > 0,
    "Plant A Total = ₹" . number_format($grandTotalA) . " | Plant B Total = ₹" . number_format($grandTotalB) . " (Strict separation maintained)"
);

// -------------------------------------------------------------
// TEST 08: Period Isolation
// -------------------------------------------------------------
// Period 1 (July 2026) Utility = 70k vs Period 2 (August 2026) Utility = 88k
$recoAAug = callApiSubprocess('get_reco', ['plant_id' => $plantAId, 'period_id' => $period2Id]);
$augAggs = $recoAAug['aggregates'] ?? [];
$augUtilAmt = (float)($augAggs[$utilCol['cost_centre_id']]['total_amount'] ?? 0);

$recoAJul = callApiSubprocess('get_reco', ['plant_id' => $plantAId, 'period_id' => $period1Id]);
$julAggs = $recoAJul['aggregates'] ?? [];
$julUtilAmt = (float)($julAggs[$utilCol['cost_centre_id']]['total_amount'] ?? 0);

assertTestPhase2(
    "TEST 08 — Period Isolation",
    abs($julUtilAmt - 70000) < 0.01 && abs($augUtilAmt - 88000) < 0.01,
    "July Utility = ₹" . number_format($julUtilAmt) . " | August Utility = ₹" . number_format($augUtilAmt) . " (Zero period bleed-through)"
);

// -------------------------------------------------------------
// TEST 09: Regeneration Idempotency
// -------------------------------------------------------------
$genRepeat = callApiSubprocess('generate', ['plant_id' => $plantAId, 'period_id' => $period1Id], 'POST');
$recoRepeat = callApiSubprocess('get_reco', ['plant_id' => $plantAId, 'period_id' => $period1Id]);

$repeatTotal = (float)($recoRepeat['grand_total'] ?? 0);
$repeatMappedCount = (int)($recoRepeat['run']['mapped_records_count'] ?? 0);

assertTestPhase2(
    "TEST 09 — Regeneration Idempotency",
    abs($repeatTotal - 810000) < 0.01 && $repeatMappedCount === 8,
    "Regeneration maintained exact ₹" . number_format($repeatTotal) . " across {$repeatMappedCount} records with zero duplication"
);

// -------------------------------------------------------------
// TEST 10: Loading & Error States
// -------------------------------------------------------------
$errNoParams = callApiSubprocess('get_reco', []);
$errInvalidPlant = callApiSubprocess('get_reco', ['plant_id' => 999999, 'period_id' => 1]);
$errUnauth = callApiSubprocess('get_reco', ['plant_id' => $plantAId, 'period_id' => 1], 'GET', 0);

assertTestPhase2(
    "TEST 10 — Loading & Error States",
    !$errNoParams['ok'] && !$errInvalidPlant['ok'] && !$errUnauth['ok'],
    "API correctly returned structured errors for missing params, invalid plant, and unauthenticated sessions"
);

// -------------------------------------------------------------
// TEST 11: Source Code Audit — Zero Hardcoded Column Names
// -------------------------------------------------------------
$jsFile = file_get_contents(__DIR__ . '/assets/js/quantity_reco.js');
$cssFile = file_get_contents(__DIR__ . '/assets/css/cost360-core.css');

$forbiddenPatterns = [
    'Sales', 'Export Sales', 'RMC', 'QC', 'POH', 'AOH', 'SOH'
];

$jsViolations = [];
foreach ($forbiddenPatterns as $p) {
    if (preg_match('/<th[^>]*>\s*' . preg_quote($p, '/') . '\s*<\/th>/i', $jsFile) ||
        preg_match('/columns\s*:\s*\[[^\]]*' . preg_quote($p, '/') . '[^\]]*\]/i', $jsFile)) {
        $jsViolations[] = $p;
    }
}

$hasScrollCss = strpos($cssFile, 'overflow-x: auto') !== false || strpos($cssFile, 'qr-scrollable-table-wrap') !== false;
$hasStickyCss = strpos($cssFile, 'position: sticky') !== false || strpos($cssFile, 'qr-sticky-col') !== false;

assertTestPhase2(
    "TEST 11 — Responsive Table & Zero Hardcoded Columns",
    empty($jsViolations) && $hasScrollCss && $hasStickyCss,
    "No hard-coded CC columns found in quantity_reco.js. Responsive scrollable & sticky column CSS verified."
);

// -------------------------------------------------------------
// TEST 12: Security & Permissions
// -------------------------------------------------------------
$wrapperFile = file_get_contents(__DIR__ . '/quantity-reco.php');
$hasRequireAuth = strpos($wrapperFile, 'requireAuth()') !== false;
$hasMenuCheck = strpos($wrapperFile, "userHasMenuAccess('QUANTITY_RECO'") !== false;
$has403 = strpos($wrapperFile, '403 Forbidden') !== false;

$menuStmt = $pdo->query("SELECT menu_id, menu_code, menu_name FROM public.menus WHERE menu_code = 'QUANTITY_RECO'");
$menuItem = $menuStmt->fetch(PDO::FETCH_ASSOC);

assertTestPhase2(
    "TEST 12 — Permissions & RBAC Access Control",
    $hasRequireAuth && $hasMenuCheck && $has403 && !empty($menuItem),
    "Menu '{$menuItem['menu_code']}' registered; wrapper enforces requireAuth() and RBAC checks with 403 Forbidden"
);

// -------------------------------------------------------------
// FINAL SUMMARY
// -------------------------------------------------------------
echo "\n=================================================================\n";
echo "    PHASE 2 TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "=================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL 12 PHASE 2 FRONTEND & INTEGRATION TESTS PASSED WITH 100% SUCCESS! <<<\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED. PLEASE INVESTIGATE.\n";
    exit(1);
}
