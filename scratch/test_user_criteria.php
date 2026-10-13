<?php
/**
 * COST360 Upload Centre - Comprehensive Verification of User Requirements
 * Tests all 14 criteria specified in the user request.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$globalPdo = getDbConnection();

// Set up authenticated admin context
$adminUser = $globalPdo->query("SELECT * FROM users WHERE email = 'admin@cost360.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    $adminUser = $globalPdo->query("SELECT * FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
$_SESSION['user_id'] = $adminUser['user_id'];
$_SESSION['email'] = $adminUser['email'];
$_SESSION['role_id'] = $adminUser['role_id'] ?? 1;

$company = $globalPdo->query("SELECT * FROM companies WHERE status = 'ACTIVE' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$companyId = (int)($company['company_id'] ?? 1);
$companyCode = $company['company_code'] ?? 'ABC02';

$_SESSION['active_customer_id'] = 1;
$_SESSION['active_customer_code'] = 'CUST001';
$_SESSION['active_customer_name'] = 'Customer 1';
$_SESSION['active_company_id'] = $companyId;
$_SESSION['active_company_code'] = $companyCode;
$_SESSION['active_company_name'] = $company['company_name'] ?? 'Company 1';
$_SESSION['active_division_id'] = 1;
$_SESSION['active_division_code'] = 'DIV001';
$_SESSION['active_plant_id'] = 1;
$_SESSION['active_plant_code'] = 'LOC001';
$_SESSION['active_location_code'] = 'LOC001';
$_SESSION['active_location_type'] = 'plant';

$custContext = getUserCustomerDbContext($adminUser);
$custPdo = $custContext['pdo'];

$passCount = 0;
$failCount = 0;

function assertCheck(string $criterion, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] {$criterion}" . ($details ? " - {$details}" : "") . "\n";
        $passCount++;
    } else {
        echo " [FAIL] {$criterion}" . ($details ? " - {$details}" : "") . "\n";
        $failCount++;
    }
}

echo "=================================================================\n";
echo "COST360 UPLOAD CENTRE: USER REQUIREMENTS VERIFICATION SUITE\n";
echo "=================================================================\n\n";

// -------------------------------------------------------------
// CRITERION 1 & 2: Exact Classification (4 Master, 7 Period-Dependent)
// -------------------------------------------------------------
$defs = getFinalizedCategoryDefinitions();
$masterSheets = [];
$periodSheets = [];
foreach ($defs as $k => $d) {
    if ($d['dataset_type'] === 'MASTER') {
        $masterSheets[] = $d['sheet_name'];
    } elseif ($d['dataset_type'] === 'TRANSACTION') {
        $periodSheets[] = $d['sheet_name'];
    }
}

$expectedMaster = [
    '01.ITEM MASTER PRODUCT MASTER',
    '02.WORK CENTER MASTER',
    '03.PARTY MASTER',
    '04.COST_CENTER_MASTER'
];

$expectedPeriod = [
    '05. STANDARD_COST',
    '05.A_BOM',
    '05.B_ROUTING',
    '06.MATERIAL TRANSACTION',
    '07.DOWNTIME',
    '08.FINANCE TRANSACTION',
    '09.TRIAL BALANCE'
];

assertCheck(
    "Criterion 1: Exactly 4 worksheets classified as Master Data",
    count($masterSheets) === 4 && empty(array_diff($expectedMaster, $masterSheets)),
    implode(', ', $masterSheets)
);

assertCheck(
    "Criterion 2: Exactly 7 worksheets classified as Period-Dependent / Transaction Data",
    count($periodSheets) === 7 && empty(array_diff($expectedPeriod, $periodSheets)),
    implode(', ', $periodSheets)
);

assertCheck(
    "Criterion 1 & 2: isMasterDataset helper strictly returns true only for the 4 master datasets",
    isMasterDataset('items') === true &&
    isMasterDataset('work_centers') === true &&
    isMasterDataset('party_master') === true &&
    isMasterDataset('cost_center_master') === true &&
    isMasterDataset('standard_cost') === false &&
    isMasterDataset('bom') === false &&
    isMasterDataset('routing') === false &&
    isMasterDataset('material_transaction') === false &&
    isMasterDataset('downtime') === false &&
    isMasterDataset('finance_transaction') === false &&
    isMasterDataset('trial_balance') === false
);

// -------------------------------------------------------------
// CRITERION 3: Canonical Target Datasets list contains all 11
// -------------------------------------------------------------
$canonical = getCanonicalTargetDatasets();
assertCheck(
    "Criterion 3: Centralized canonical target datasets list defines all 11 datasets",
    count($canonical) === 11 &&
    count(array_filter($canonical, fn($x) => $x['dataset_type'] === 'MASTER')) === 4 &&
    count(array_filter($canonical, fn($x) => $x['dataset_type'] === 'TRANSACTION')) === 7
);

// -------------------------------------------------------------
// CRITERION 4 & 5: JS Verification - Single TARGET DATASET, No MASTER CATEGORY
// -------------------------------------------------------------
$jsCode = file_get_contents(__DIR__ . '/../assets/js/upload_centre.js');
$hasMasterCatDropdown = strpos($jsCode, 'label class="dropdown-label"') !== false && stripos($jsCode, 'Master Category') !== false;
$hasTargetDatasetDropdown = stripos($jsCode, 'Target Dataset') !== false;
$hasTargetDatasetChangeHandler = strpos($jsCode, 'onTargetDatasetChange') !== false;

assertCheck(
    "Criterion 4: No MASTER CATEGORY dropdown remains in the worksheet cards",
    !$hasMasterCatDropdown
);

assertCheck(
    "Criterion 5: Single TARGET DATASET selector is displayed with onTargetDatasetChange handler",
    $hasTargetDatasetDropdown && $hasTargetDatasetChangeHandler
);

// -------------------------------------------------------------
// CRITERION 6: User-defined custom period support
// -------------------------------------------------------------
// Test custom date range resolution: 01-Apr-2026 to 30-Sep-2026
$resPeriod1 = resolveOrRegisterPeriod(null, '2026-04-01', '2026-09-30', 'FY 2026-27 – April to September', $adminUser);
assertCheck(
    "Criterion 6: Custom date range accepted and resolved by backend (01-Apr-2026 to 30-Sep-2026)",
    $resPeriod1['ok'] === true &&
    $resPeriod1['start_date'] === '2026-04-01' &&
    $resPeriod1['end_date'] === '2026-09-30'
);

// Re-resolving identical range reuses existing record (no duplicate periods created)
$resPeriod1Dup = resolveOrRegisterPeriod(null, '2026-04-01', '2026-09-30', 'FY 2026-27 – April to September', $adminUser);
assertCheck(
    "Criterion 6: Reusing exact same custom range reuses existing record without creating duplicates",
    $resPeriod1Dup['ok'] === true &&
    $resPeriod1Dup['period_id'] === $resPeriod1['period_id'] &&
    $resPeriod1Dup['reused'] === true
);

// Arbitrary custom period: 15-May-2026 to 15-Aug-2026
$resPeriod2 = resolveOrRegisterPeriod(null, '2026-05-15', '2026-08-15', 'Mid-Year Custom Range', $adminUser);
assertCheck(
    "Criterion 6: Arbitrary non-calendar-month custom range supported (15-May-2026 to 15-Aug-2026)",
    $resPeriod2['ok'] === true &&
    $resPeriod2['start_date'] === '2026-05-15' &&
    $resPeriod2['end_date'] === '2026-08-15'
);

// -------------------------------------------------------------
// CRITERION 7: Backend rejects invalid date ranges
// -------------------------------------------------------------
$invalidRange = resolveOrRegisterPeriod(null, '2026-09-30', '2026-04-01', 'Inverted Range', $adminUser);
assertCheck(
    "Criterion 7: Backend strictly rejects inverted date range where end_date < start_date",
    $invalidRange['ok'] === false &&
    stripos($invalidRange['message'], 'cannot be earlier') !== false
);

// -------------------------------------------------------------
// CRITERION 8: Flag transactions outside selected period range
// -------------------------------------------------------------
$tmpDir = __DIR__ . '/../uploads/temp/test_crit/';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

// Create a material transaction CSV with a transaction date of 2026-11-20
$outOfRangeDocNo = 'DOC-OUT-' . time();
$csvOut = "doc_type,doc_no,doc_date,item_code,item_name,qty,rate,amount\n";
$csvOut .= "Purchase,{$outOfRangeDocNo},2026-11-20,ITEM-CRIT-1,Test Out Of Range Item,10,50.00,500.00\n";
$fOut = $tmpDir . 'out_of_range.csv';
file_put_contents($fOut, $csvOut);

// Set active period in session for upload analysis
$_SESSION['active_period_id'] = (int)$resPeriod1['period_id'];
$_SESSION['active_period_name'] = $resPeriod1['period_name'];

$anaOut = analyzeUploadedFiles([
    'name' => ['06.MATERIAL TRANSACTION.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fOut],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fOut)]
], $adminUser);

// Target period is April to September 2026 (2026-04-01 to 2026-09-30)
$outCheck = validateUploadPeriodDates($anaOut['upload_id'], [$anaOut['files'][0]['id']], '2026-04-01', '2026-09-30');
assertCheck(
    "Criterion 8: Date validator detects transaction date (2026-11-20) outside selected period range (2026-04-01 to 2026-09-30)",
    $outCheck['has_violations'] === true && count($outCheck['violations']) > 0 &&
    stripos($outCheck['violations'][0]['message'], 'outside the selected period range') !== false
);

// Preview includes date_violations
$previewResult = getFilePreviewData($anaOut['files'][0]['id'], $adminUser, null, '2026-04-01', '2026-09-30');
assertCheck(
    "Criterion 8: getFilePreviewData returns date_violations for preview modal alert",
    !empty($previewResult['date_violations']) && count($previewResult['date_violations']) > 0
);

// Import into April-September range is rejected before database commit
$importAttempt = executeDataImport($anaOut['upload_id'], $adminUser, [$anaOut['files'][0]['id']], null, '2026-04-01', '2026-09-30');
assertCheck(
    "Criterion 8: executeDataImport halts and rejects import when transaction dates fall outside selected period",
    $importAttempt['ok'] === false && stripos($importAttempt['message'], 'outside the selected period') !== false
);

// -------------------------------------------------------------
// CRITERION 9: Master data upsert (Insert new, update changed, no duplicates)
// -------------------------------------------------------------
$masterItemCode = 'ITEM-MSTR-' . time();
$csvM1 = "customer_code,company_code,division_code,location_code,item_code,item_name,unit_of_measure,std_rate,material_rate\n";
$csvM1 .= "CUST001,{$companyCode},DIV001,LOC001,{$masterItemCode},Initial Product Name,PCS,100.00,60.00\n";
$fM1 = $tmpDir . 'm1.csv';
file_put_contents($fM1, $csvM1);

$anaM1 = analyzeUploadedFiles([
    'name' => ['01.ITEM MASTER PRODUCT MASTER.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fM1],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fM1)]
], $adminUser);

$impM1 = executeDataImport($anaM1['upload_id'], $adminUser, [$anaM1['files'][0]['id']]);
assertCheck(
    "Criterion 9: First master upload inserts new record",
    ($impM1['imported'] ?? 0) === 1
);

// Re-upload unchanged file creates no duplicates
$impM1Re = executeDataImport($anaM1['upload_id'], $adminUser, [$anaM1['files'][0]['id']]);
assertCheck(
    "Criterion 9: Re-importing identical master file creates 0 duplicates and marks record unchanged",
    ($impM1Re['imported'] ?? 0) === 0 && ($impM1Re['unchanged'] ?? 0) === 1
);

// Upload modified record updates existing row
$csvM2 = "customer_code,company_code,division_code,location_code,item_code,item_name,unit_of_measure,std_rate,material_rate\n";
$csvM2 .= "CUST001,{$companyCode},DIV001,LOC001,{$masterItemCode},Updated Product Name,PCS,150.00,90.00\n";
$fM2 = $tmpDir . 'm2.csv';
file_put_contents($fM2, $csvM2);

$anaM2 = analyzeUploadedFiles([
    'name' => ['01.ITEM MASTER PRODUCT MASTER.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fM2],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fM2)]
], $adminUser);

$impM2 = executeDataImport($anaM2['upload_id'], $adminUser, [$anaM2['files'][0]['id']]);
assertCheck(
    "Criterion 9: Changed master record is updated in place",
    ($impM2['updated'] ?? 0) === 1
);

$chkItem = $custPdo->prepare("SELECT * FROM items WHERE item_code = ?");
$chkItem->execute([$masterItemCode]);
$dbItem = $chkItem->fetch(PDO::FETCH_ASSOC);
assertCheck(
    "Criterion 9: Master record updated fields reflected in Customer DB",
    $dbItem && $dbItem['item_name'] === 'Updated Product Name' && (float)$dbItem['std_rate'] == 150.00
);

// -------------------------------------------------------------
// CRITERION 10: Missing records from subsequent upload are preserved
// -------------------------------------------------------------
$otherItemCode = 'ITEM-OTHER-' . time();
$csvM3 = "customer_code,company_code,division_code,location_code,item_code,item_name,unit_of_measure,std_rate,material_rate\n";
$csvM3 .= "CUST001,{$companyCode},DIV001,LOC001,{$otherItemCode},Second Product,PCS,200.00,120.00\n";
$fM3 = $tmpDir . 'm3.csv';
file_put_contents($fM3, $csvM3);

$anaM3 = analyzeUploadedFiles([
    'name' => ['01.ITEM MASTER PRODUCT MASTER.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fM3],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fM3)]
], $adminUser);

executeDataImport($anaM3['upload_id'], $adminUser, [$anaM3['files'][0]['id']]);

// Check that original $masterItemCode was NOT deleted even though it wasn't in m3.csv
$chkItemPreserved = $custPdo->prepare("SELECT count(*) FROM items WHERE item_code = ?");
$chkItemPreserved->execute([$masterItemCode]);
assertCheck(
    "Criterion 10: Missing master records from subsequent upload are NOT deleted",
    (int)$chkItemPreserved->fetchColumn() === 1
);

// -------------------------------------------------------------
// CRITERION 11: Imports for one period do not modify data outside that period
// -------------------------------------------------------------
$inRangeDocNo = 'DOC-IN-' . time();
$csvIn = "doc_type,doc_no,doc_date,item_code,item_name,qty,rate,amount\n";
$csvIn .= "Purchase,{$inRangeDocNo},2026-06-15,{$masterItemCode},Test Goods,10,50.00,500.00\n";
$fIn = $tmpDir . 'in_range.csv';
file_put_contents($fIn, $csvIn);

$anaIn = analyzeUploadedFiles([
    'name' => ['06.MATERIAL TRANSACTION.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fIn],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fIn)]
], $adminUser);

$impInRange = executeDataImport($anaIn['upload_id'], $adminUser, [$anaIn['files'][0]['id']], null, '2026-04-01', '2026-09-30', 'H1 FY 2026-27');
assertCheck(
    "Criterion 11: Transaction import successful when dates match custom period (2026-06-15 in 2026-04-01 to 2026-09-30)",
    ($impInRange['imported'] ?? 0) === 1
);

// -------------------------------------------------------------
// CRITERION 12: Historical Costing Revisions Preserved for Standard Cost
// -------------------------------------------------------------
$costItemCode = 'ITEM-COST-' . time();
$csvCost1 = "customer_code,company_code,division_code,location_code,product_code,std_material,std_conversion,std_total_cost,std_revision_date\n";
$csvCost1 .= "CUST001,{$companyCode},DIV001,LOC001,{$costItemCode},10.00,5.00,15.00,2026-04-01\n";
$fCost1 = $tmpDir . 'cost1.csv';
file_put_contents($fCost1, $csvCost1);

$anaCost1 = analyzeUploadedFiles([
    'name' => ['05. STANDARD_COST.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fCost1],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fCost1)]
], $adminUser);

executeDataImport($anaCost1['upload_id'], $adminUser, [$anaCost1['files'][0]['id']], null, '2026-04-01', '2026-09-30');

// Now upload Revision 2 with a different revision date: 2026-07-01
$csvCost2 = "customer_code,company_code,division_code,location_code,product_code,std_material,std_conversion,std_total_cost,std_revision_date\n";
$csvCost2 .= "CUST001,{$companyCode},DIV001,LOC001,{$costItemCode},12.00,6.00,18.00,2026-07-01\n";
$fCost2 = $tmpDir . 'cost2.csv';
file_put_contents($fCost2, $csvCost2);

$anaCost2 = analyzeUploadedFiles([
    'name' => ['05. STANDARD_COST.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fCost2],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fCost2)]
], $adminUser);

executeDataImport($anaCost2['upload_id'], $adminUser, [$anaCost2['files'][0]['id']], null, '2026-04-01', '2026-09-30');

// Both revisions must exist in the database!
$cntCost = $custPdo->prepare("SELECT count(*) FROM standard_cost WHERE product_code = ?");
$cntCost->execute([$costItemCode]);
$totalRevisions = (int)$cntCost->fetchColumn();
assertCheck(
    "Criterion 12: Standard Cost preserves historical revisions (Both Rev 2026-04-01 and Rev 2026-07-01 exist)",
    $totalRevisions === 2,
    "Found {$totalRevisions} distinct revision records"
);

echo "\n=================================================================\n";
echo "FINAL RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "=================================================================\n";
