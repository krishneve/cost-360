<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/quantity_reco.php';

$pdo = getDbConnection();

// Let's create a dedicated plant for the Real plant.xlsx reconciliation:
// Let's check if 'PLANT_REAL_REF' exists or create it so it never clashes with test fixtures
$stmt = $pdo->prepare("SELECT plant_id FROM plants WHERE plant_code = 'PLANT_REAL_REF' LIMIT 1");
$stmt->execute();
$plantId = (int)$stmt->fetchColumn();

if (!$plantId) {
    $divId = (int)$pdo->query("SELECT division_id FROM divisions LIMIT 1")->fetchColumn() ?: 1;
    $ins = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PLANT_REAL_REF', 'Plant Reference (plant.xlsx)', 'Active') RETURNING plant_id");
    $ins->execute([$divId]);
    $plantId = (int)$ins->fetchColumn();
}



$periodId = 1;

echo "Plant ID for Reference Reconciliation: {$plantId}\n";

// 1. Set up the 23 Cost Centres from plant.xlsx for this plant
$referenceCCs = [
    'Sales', 'Export Sales', 'Tool', 'Export Benefits', 'Export',
    'Self Consumption', 'RMC', 'MOH', 'Packing Cost', 'QC',
    'Consumable', 'Repairs & Maintenance', 'Direct Emp Cost', 'Direct Expenses', 'Utility',
    'POH', 'Depreciation', 'Change in stock', 'AOH', 'SOH',
    'Finance cost', 'Reco', 'Corporate'
];

$order = 1;
foreach ($referenceCCs as $ccName) {
    $code = 'CC_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $ccName));
    saveCostCentre($pdo, [
        'plant_id' => $plantId,
        'cost_center_code' => $code,
        'cost_center_name' => $ccName,
        'cost_basis_code'  => $ccName,
        'display_order'    => $order++,
        'is_active'        => true
    ]);
}

$excelPath = __DIR__ . '/../plant.xlsx';
$output = shell_exec("python " . escapeshellarg(__DIR__ . '/extract_plant_rows.py') . " " . escapeshellarg($excelPath));
$records = json_decode($output, true);
echo "Extracted " . count($records) . " rows from plant.xlsx\n";


// 3. Clear previous source transactions for this plant + period
$del = $pdo->prepare("DELETE FROM quantity_reco_source_transactions WHERE plant_id = ? AND period_id = ?");
$del->execute([$plantId, $periodId]);

// 4. Ingest into quantity_reco_source_transactions
$insRecords = [];
foreach ($records as $r) {
    $insRecords[] = [
        'account_code'       => $r['acode'],
        'description'        => $r['aname'],
        'amount'             => $r['closing'],
        'cost_basis'         => $r['basis'],
        'pnl_classification' => $r['pnl'],
        'nature'             => $r['nature'],
        'source_file'        => 'plant.xlsx',
        'source_row_ref'     => $r['row_ref'],
        'source'             => 'EXCEL_PLANT'
    ];
}

$saved = saveQuantityRecoSourceTransactions($pdo, $plantId, $periodId, $insRecords);
echo "Saved {$saved} source transactions into quantity_reco_source_transactions.\n";

// 5. Generate Quantity Reco
$gen = generateQuantityReco($pdo, $plantId, $periodId);
echo "Generated Quantity Reco. Status: {$gen['status']}, Total Records: {$gen['total_source_records']}, Mapped: {$gen['mapped_records_count']}, Unmapped: {$gen['unmapped_records_count']}, Mapped Amount: " . number_format($gen['mapped_amount'], 2) . "\n";

// 6. Fetch matrix
$matrix = getQuantityRecoMatrix($pdo, $plantId, $periodId);
echo "Matrix Totals:\n";
echo "  Closing: " . number_format($matrix['totals']['closing'], 2) . "\n";
echo "  Total:   " . number_format($matrix['totals']['total'], 2) . "\n";
echo "  Check:   " . number_format($matrix['totals']['check'], 2) . "\n";
echo "  Status:  " . $matrix['totals']['check_status'] . "\n";

// Check unmapped exceptions
if (!empty($matrix['unmapped_exceptions'])) {
    echo "\nUnmapped Exceptions (" . count($matrix['unmapped_exceptions']) . "):\n";
    foreach ($matrix['unmapped_exceptions'] as $ex) {
        echo "  - {$ex['source_description']} | Basis: '{$ex['cost_basis']}' | Amount: " . number_format($ex['amount'], 2) . " | Reason: {$ex['reason']}\n";
    }
}
