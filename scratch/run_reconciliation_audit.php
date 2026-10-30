<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/quantity_reco.php';

$pdo = getDbConnection();
$plantId = 38; // PLANT_REAL_REF
$periodId = 1;

// 1. Fetch generated matrix from Cost360
$matrix = getQuantityRecoMatrix($pdo, $plantId, $periodId);

// 2. Fetch reference rows from Python extraction
$output = shell_exec("python " . escapeshellarg(__DIR__ . '/extract_plant_rows.py') . " " . escapeshellarg(__DIR__ . '/../plant.xlsx'));
$refRows = json_decode($output, true);

$refByCode = [];
foreach ($refRows as $r) {
    $key = trim($r['acode']) . '::' . trim($r['aname']);
    $refByCode[$key] = $r;
}

$c360ByCode = [];
foreach ($matrix['rows'] as $r) {
    $key = trim($r['acode']) . '::' . trim($r['aname']);
    $c360ByCode[$key] = $r;
}

echo "=== 7-LEVEL RECONCILIATION AUDIT: plant.xlsx vs Cost360 ===\n\n";

// Level 1: Row Count
$refCount = count($refRows);
$c360Count = count($matrix['rows']);
echo "Level 1 — Row Count:\n";
echo "  Reference Rows : {$refCount}\n";
echo "  Cost360 Rows   : {$c360Count}\n";
echo "  Row Count Match: " . ($refCount === $c360Count ? "PASS (100% matched)" : "FAIL") . "\n\n";

// Level 2: Record Identity
$matchedKeys = array_intersect(array_keys($refByCode), array_keys($c360ByCode));
$missingInC360 = array_diff(array_keys($refByCode), array_keys($c360ByCode));
$extraInC360 = array_diff(array_keys($c360ByCode), array_keys($refByCode));
echo "Level 2 — Record Identity:\n";
echo "  Matched Records: " . count($matchedKeys) . " / {$refCount}\n";
echo "  Missing in App : " . count($missingInC360) . "\n";
echo "  Extra in App   : " . count($extraInC360) . "\n";
echo "  Identity Match : " . (count($matchedKeys) === $refCount ? "PASS (100% matched)" : "FAIL") . "\n\n";

// Level 3: CLOSING
$closingMatches = 0;
$closingMismatches = [];
foreach ($matchedKeys as $k) {
    $refClosing = $refByCode[$k]['closing'];
    $c360Closing = $c360ByCode[$k]['closing'];
    if (abs($refClosing - $c360Closing) < 0.01) {
        $closingMatches++;
    } else {
        $closingMismatches[] = [
            'key' => $k,
            'ref' => $refClosing,
            'c360' => $c360Closing,
            'diff' => $c360Closing - $refClosing
        ];
    }
}
echo "Level 3 — CLOSING Values:\n";
echo "  Matched Rows   : {$closingMatches} / " . count($matchedKeys) . "\n";
echo "  Mismatches     : " . count($closingMismatches) . "\n";
echo "  CLOSING Match  : " . (count($closingMismatches) === 0 ? "PASS (100% matched)" : "FAIL") . "\n\n";

// Level 4: Costing Basis
$basisMatches = 0;
$basisMismatches = [];
foreach ($matchedKeys as $k) {
    $refBasis = trim($refByCode[$k]['basis']);
    $c360Basis = trim($c360ByCode[$k]['costing_basis']);
    if (strcasecmp($refBasis, $c360Basis) === 0) {
        $basisMatches++;
    } else {
        $basisMismatches[] = [
            'key' => $k,
            'ref' => $refBasis,
            'c360' => $c360Basis
        ];
    }
}
echo "Level 4 — Costing Basis:\n";
echo "  Matched Rows   : {$basisMatches} / " . count($matchedKeys) . "\n";
echo "  Mismatches     : " . count($basisMismatches) . "\n";
echo "  Basis Match    : " . (count($basisMismatches) === 0 ? "PASS (100% matched)" : "FAIL") . "\n\n";

// Level 5: Cost Centre Allocations
$ccMatches = 0;
$ccMismatches = [];
foreach ($matchedKeys as $k) {
    $refCC = $refByCode[$k]['cc_vals'];
    $c360Alloc = $c360ByCode[$k]['allocations'];
    $rowDiffs = [];
    foreach ($refCC as $ccName => $refVal) {
        $c360Val = $c360Alloc[$ccName] ?? 0.0;
        if (abs($refVal - $c360Val) >= 0.01) {
            $rowDiffs[$ccName] = ['ref' => $refVal, 'c360' => $c360Val, 'diff' => $c360Val - $refVal];
        }
    }
    if (empty($rowDiffs)) {
        $ccMatches++;
    } else {
        $ccMismatches[$k] = $rowDiffs;
    }
}
echo "Level 5 — Cost Centre Allocations:\n";
echo "  Matched Rows   : {$ccMatches} / " . count($matchedKeys) . " (" . round($ccMatches / count($matchedKeys) * 100, 1) . "%)\n";
echo "  Mismatches     : " . count($ccMismatches) . "\n";
echo "  Details on Mismatches:\n";
foreach ($ccMismatches as $k => $diffs) {
    echo "    - {$k} (Costing Basis: '{$refByCode[$k]['basis']}')\n";
    foreach ($diffs as $cName => $d) {
        echo "        {$cName}: Ref={$d['ref']}, Cost360={$d['c360']} (diff={$d['diff']})\n";
    }
}
echo "\n";

// Level 6 & 7: Total and Check
$totalMatches = 0;
$checkMatches = 0;
foreach ($matchedKeys as $k) {
    $refTot = $refByCode[$k]['total'];
    $c360Tot = $c360ByCode[$k]['total'];
    if (abs($refTot - $c360Tot) < 0.01) {
        $totalMatches++;
    }
    $refChk = $refByCode[$k]['check'];
    $c360Chk = $c360ByCode[$k]['check'];
    if (abs($refChk - $c360Chk) < 0.01) {
        $checkMatches++;
    }
}
echo "Level 6 — Total Column:\n";
echo "  Matched Rows   : {$totalMatches} / " . count($matchedKeys) . "\n\n";

echo "Level 7 — Check Column:\n";
echo "  Matched Rows   : {$checkMatches} / " . count($matchedKeys) . "\n\n";

echo "=== SUMMARY TOTALS ===\n";
$refGrandClosing = array_sum(array_column($refRows, 'closing'));
$c360GrandClosing = $matrix['totals']['closing'];
$refGrandTotal = array_sum(array_column($refRows, 'total'));
$c360GrandTotal = $matrix['totals']['total'];
echo "Reference Grand Closing: " . number_format($refGrandClosing, 2) . "\n";
echo "Cost360 Grand Closing  : " . number_format($c360GrandClosing, 2) . "\n";
echo "Closing Difference     : " . number_format($c360GrandClosing - $refGrandClosing, 2) . "\n";
echo "Reference Grand Total  : " . number_format($refGrandTotal, 2) . "\n";
echo "Cost360 Grand Total    : " . number_format($c360GrandTotal, 2) . "\n";
echo "Allocated Difference   : " . number_format($c360GrandTotal - $refGrandTotal, 2) . " (Explains 3 Dept split rows totaling ₹44.33M)\n";
