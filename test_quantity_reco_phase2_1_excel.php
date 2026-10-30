<?php
/**
 * COST360 — QUANTITY RECO PHASE 2.1 VERIFICATION SUITE
 * plant.xlsx Excel-Style Structure and Behavior Tests
 * 
 * Tests:
 * - TEST A: Fixed Columns (ANAME, ACODE, P&L, CLOSING, Costing Basis, Nature)
 * - TEST B: Dynamic Cost Centre Columns (sourced from API)
 * - TEST C: Allocation (CLOSING placed in matching CC, all other CCs = 0)
 * - TEST D: Total Column (Row Total = sum of CC allocations)
 * - TEST E: Check Column (Check = CLOSING - Total = 0 for mapped rows)
 * - TEST F: Unmapped Rows (Check != 0, unmapped isolated in grid, not silently allocated)
 * - TEST G: Negative Value Preservation (CLOSING = -500k -> CC = -500k, Total = -500k, Check = 0)
 * - TEST H: Plant Switching (Plant A vs Plant B dynamic column sets)
 * - TEST I: Period Switching (Period 1 vs Period 2 data isolation)
 * - TEST J: Traceability (Allocated amount drills down to contributing source records)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/quantity_reco.php';

$pdo = getDbConnection();

echo "=================================================================\n";
echo "    COST360 — QUANTITY RECO PHASE 2.1 (plant.xlsx) TEST SUITE\n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertExcelTest(string $testName, bool $condition, string $detail = '') {
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

$plantAId = 35;
$plantBId = 36;
$period1Id = 1;
$period2Id = 2;

function callApiExcel(string $action, array $params = [], string $method = 'GET'): array {
    $script = __DIR__ . '/scratch/tmp_excel_api_runner.php';
    $code = '<?php' . "\n";
    $code .= 'session_start();' . "\n";
    $code .= '$_SESSION["user_id"] = 1;' . "\n";
    $code .= '$_SESSION["user"] = ["user_id" => 1, "role_code" => "PLATFORM_ADMIN"];' . "\n";
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

// -------------------------------------------------------------
// SEED TEST TRANSACTION FOR TESTS C, D, E, F, G
// -------------------------------------------------------------
// C, D, E: 100k RMC
// G: -500k Utility
$pdo->prepare("
    DELETE FROM public.quantity_reco_source_transactions 
    WHERE plant_id = :pId AND period_id = :prId AND source_row_ref IN ('EXCEL-C-01', 'EXCEL-G-01')
")->execute([':pId' => $plantAId, ':prId' => $period1Id]);

$pdo->prepare("
    INSERT INTO public.quantity_reco_source_transactions
        (plant_id, period_id, source_row_ref, description, account_code, pnl_classification, nature, cost_basis, amount, source)
    VALUES
        (:pId, :prId, 'EXCEL-C-01', 'Special RMC Batch', '2000073', 'COMS', 'Variable', 'Raw Material', 100000, 'TB'),
        (:pId, :prId, 'EXCEL-G-01', 'Power Rebate Credit', '5000099', 'Other Exp', 'Fixed', 'Utility', -500000, 'TB')
")->execute([':pId' => $plantAId, ':prId' => $period1Id]);

// Generate fresh run
$genResp = callApiExcel('generate', ['plant_id' => $plantAId, 'period_id' => $period1Id], 'POST');
assertExcelTest("Preparation: Generate Plant A with Excel test records", $genResp['ok'] ?? false);

// Fetch grid data
$gridData = callApiExcel('get_reco', ['plant_id' => $plantAId, 'period_id' => $period1Id]);
$rows = $gridData['rows'] ?? [];
$columns = $gridData['columns'] ?? [];
$totals = $gridData['totals'] ?? [];

// -------------------------------------------------------------
// TEST A: Fixed Columns
// Verify ANAME, ACODE, P&L, CLOSING, Costing Basis, Nature
// -------------------------------------------------------------
$firstRow = !empty($rows) ? $rows[0] : [];
$hasAname = array_key_exists('aname', $firstRow);
$hasAcode = array_key_exists('acode', $firstRow);
$hasPnl = array_key_exists('pnl', $firstRow);
$hasClosing = array_key_exists('closing', $firstRow);
$hasCostingBasis = array_key_exists('costing_basis', $firstRow);
$hasNature = array_key_exists('nature', $firstRow);

assertExcelTest(
    "TEST A — Fixed Columns (ANAME, ACODE, P&L, CLOSING, Costing Basis, Nature)",
    $hasAname && $hasAcode && $hasPnl && $hasClosing && $hasCostingBasis && $hasNature,
    "Row structure confirmed: aname='{$firstRow['aname']}', acode='{$firstRow['acode']}', pnl='{$firstRow['pnl']}', closing={$firstRow['closing']}, basis='{$firstRow['costing_basis']}', nature='{$firstRow['nature']}'"
);

// -------------------------------------------------------------
// TEST B: Dynamic Cost Centre Columns from API
// -------------------------------------------------------------
$ccCodes = array_column($columns, 'cost_centre_code');
$ccNames = array_column($columns, 'cost_centre_name');

assertExcelTest(
    "TEST B — Dynamic Cost Centre Columns Sourced from API",
    !empty($columns) && count($columns) >= 5,
    "API returned " . count($columns) . " dynamic Cost Centres: " . implode(', ', $ccNames)
);

// -------------------------------------------------------------
// TEST C: Allocation (Costing Basis = RMC -> RMC = CLOSING, all other CCs = 0)
// -------------------------------------------------------------
$rmcRow = null;
foreach ($rows as $r) {
    if (($r['source_row_ref'] ?? '') === 'EXCEL-C-01') {
        $rmcRow = $r;
        break;
    }
}

$rmcColId = null;
foreach ($columns as $c) {
    if ($c['cost_centre_name'] === 'RMC') {
        $rmcColId = $c['cost_centre_id'];
        break;
    }
}

$rmcAllocCorrect = false;
$otherCcsZero = true;
if ($rmcRow && $rmcColId) {
    $rmcAllocCorrect = (abs(($rmcRow['allocations'][$rmcColId] ?? 0) - 100000) < 0.01);
    foreach ($columns as $c) {
        $cid = $c['cost_centre_id'];
        if ($cid !== $rmcColId) {
            if (($rmcRow['allocations'][$cid] ?? 0) != 0) {
                $otherCcsZero = false;
                break;
            }
        }
    }
}

assertExcelTest(
    "TEST C — Cost Centre Allocation (CLOSING in matching CC, all other CCs = 0)",
    $rmcAllocCorrect && $otherCcsZero,
    "Row '{$rmcRow['aname']}': CLOSING=100000, RMC=100000, all other CC allocations=0"
);

// -------------------------------------------------------------
// TEST D: Total Column (Total = sum of CC allocations)
// -------------------------------------------------------------
$totalMatches = ($rmcRow && abs(($rmcRow['total'] ?? 0) - 100000) < 0.01);

assertExcelTest(
    "TEST D — Total Column",
    $totalMatches,
    "Row Total = " . ($rmcRow['total'] ?? 'null') . " (equals CLOSING amount 100,000)"
);

// -------------------------------------------------------------
// TEST E: Check Column (Check = CLOSING - Total = 0 for mapped rows)
// -------------------------------------------------------------
$checkIsZero = ($rmcRow && abs(($rmcRow['check'] ?? 999)) < 0.0001 && $rmcRow['is_balanced']);

assertExcelTest(
    "TEST E — Check Column (Check = CLOSING - Total = 0 -> Balanced)",
    $checkIsZero,
    "Check = " . ($rmcRow['check'] ?? 'null') . ", status: " . ($rmcRow['check_status'] ?? 'null')
);

// -------------------------------------------------------------
// TEST F: Unmapped Rows (Check != 0, not silently allocated)
// -------------------------------------------------------------
$unmappedRow = null;
foreach ($rows as $r) {
    if ($r['type'] === 'UNMAPPED' || ($r['costing_basis'] ?? '') === 'Factory Security') {
        $unmappedRow = $r;
        break;
    }
}

$unmappedNotAllocated = false;
if ($unmappedRow) {
    $allAllocZero = true;
    foreach ($columns as $c) {
        if (($unmappedRow['allocations'][$c['cost_centre_id']] ?? 0) != 0) {
            $allAllocZero = false;
            break;
        }
    }
    $unmappedNotAllocated = ($allAllocZero && $unmappedRow['total'] == 0 && $unmappedRow['check'] == $unmappedRow['closing'] && !$unmappedRow['is_balanced']);
}

assertExcelTest(
    "TEST F — Unmapped Rows Isolated (Check != 0, not silently allocated into any CC)",
    $unmappedNotAllocated,
    "Unmapped '{$unmappedRow['aname']}': CLOSING={$unmappedRow['closing']}, Total=0, Check={$unmappedRow['check']} (Attention / Unbalanced)"
);

// -------------------------------------------------------------
// TEST G: Negative Value Preservation
// CLOSING = -500,000 -> Utility = -500,000, Total = -500,000, Check = 0
// -------------------------------------------------------------
$negRow = null;
foreach ($rows as $r) {
    if (($r['source_row_ref'] ?? '') === 'EXCEL-G-01') {
        $negRow = $r;
        break;
    }
}

$utilColId = null;
foreach ($columns as $c) {
    if ($c['cost_centre_name'] === 'Utility') {
        $utilColId = $c['cost_centre_id'];
        break;
    }
}

$negCorrect = false;
if ($negRow && $utilColId) {
    $negClosing = abs(($negRow['closing'] ?? 0) - (-500000)) < 0.01;
    $negUtil = abs(($negRow['allocations'][$utilColId] ?? 0) - (-500000)) < 0.01;
    $negTotal = abs(($negRow['total'] ?? 0) - (-500000)) < 0.01;
    $negCheck = abs(($negRow['check'] ?? 999)) < 0.0001;
    $negCorrect = ($negClosing && $negUtil && $negTotal && $negCheck);
}

assertExcelTest(
    "TEST G — Negative Value Preservation",
    $negCorrect,
    "Negative row '{$negRow['aname']}': CLOSING={$negRow['closing']}, Utility={$negRow['allocations'][$utilColId]}, Total={$negRow['total']}, Check={$negRow['check']}"
);

// -------------------------------------------------------------
// TEST H: Plant Switching Dynamic Column Sets
// -------------------------------------------------------------
$gridDataB = callApiExcel('get_reco', ['plant_id' => $plantBId, 'period_id' => $period1Id]);
$colsB = array_column($gridDataB['columns'] ?? [], 'cost_centre_name');
$colsA = array_column($columns, 'cost_centre_name');

assertExcelTest(
    "TEST H — Plant Switching (Distinct Cost Centre Columns)",
    $colsA !== $colsB && in_array('Export Sales', $colsB) && !in_array('QC', $colsB),
    "Plant A CCs (" . count($colsA) . ") vs Plant B CCs (" . count($colsB) . "): Dynamically separate"
);

// -------------------------------------------------------------
// TEST I: Period Switching Data Isolation
// -------------------------------------------------------------
$gridDataAug = callApiExcel('get_reco', ['plant_id' => $plantAId, 'period_id' => $period2Id]);
$augTotal = (float)($gridDataAug['totals']['total'] ?? 0);
$julTotal = (float)($totals['total'] ?? 0);

assertExcelTest(
    "TEST I — Period Switching Isolation",
    $augTotal !== $julTotal && $augTotal > 0,
    "July Total = ₹" . number_format($julTotal) . " | August Total = ₹" . number_format($augTotal)
);

// -------------------------------------------------------------
// TEST J: Source Traceability Drilldown
// -------------------------------------------------------------
$runIdA = (int)($gridData['run']['run_id'] ?? 0);
$traceResp = callApiExcel('trace_source', ['run_id' => $runIdA, 'cost_centre_id' => $utilColId]);
$traceCount = count($traceResp['source_records'] ?? []);

assertExcelTest(
    "TEST J — Source Traceability Drilldown",
    $traceResp['ok'] && $traceCount >= 2,
    "Traceability modal returns {$traceCount} contributing source transactions for Utility"
);

// -------------------------------------------------------------
// FINAL SUMMARY
// -------------------------------------------------------------
echo "\n=================================================================\n";
echo "    PHASE 2.1 TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "=================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL 10 PHASE 2.1 (plant.xlsx) TESTS PASSED WITH 100% SUCCESS! <<<\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED. PLEASE INVESTIGATE.\n";
    exit(1);
}
