<?php
/**
 * Comprehensive Automated Verification Suite for COST360 Upload Centre
 * Validates the finalized 11-category Excel structure from EXCEL For Upload center (1).xlsx.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

session_start();

$passCount = 0;
$failCount = 0;

function assertCondition($desc, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$desc}" . ($details ? " - {$details}" : "") . "\n";
        $failCount++;
    }
}

echo "====================================================================\n";
echo "COST360 UPLOAD CENTRE: FINALIZED EXCEL VERIFICATION SUITE\n";
echo "====================================================================\n\n";

// 1. Setup Mock Authenticated Session Context
$globalPdo = getDbConnection();
$adminUser = $globalPdo->query("SELECT user_id, email, username FROM users ORDER BY user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    die("No user found in database to execute tests.\n");
}

$userId = (int)$adminUser['user_id'];
$_SESSION['user_id'] = $userId;
$_SESSION['email'] = $adminUser['email'];
$_SESSION['username'] = $adminUser['username'] ?? 'admin';
$_SESSION['category_id'] = 1; // Platform Admin

// Set Authorized Organization Scope Context
$_SESSION['active_customer_id'] = 1;
$_SESSION['active_customer_code'] = 'CUST001';
$_SESSION['active_customer_name'] = 'ABC Manufacturing Pvt Ltd';
$_SESSION['active_company_id'] = 1;
$_SESSION['active_company_code'] = 'COMP001';
$_SESSION['active_company_name'] = 'ABC Industries Corp';
$_SESSION['active_division_id'] = 1;
$_SESSION['active_division_code'] = 'DIV001';
$_SESSION['active_division_name'] = 'Manufacturing Div';
$_SESSION['active_plant_id'] = 1;
$_SESSION['active_plant_code'] = 'PLANT001';
$_SESSION['active_plant_name'] = 'Pune Heavy Machinery Plant';
$_SESSION['active_location_type'] = 'plant';
$_SESSION['active_period_id'] = 1;
$_SESSION['active_period_name'] = 'July 2026';

$testUser = [
    'user_id' => $userId,
    'email'   => $adminUser['email'],
    'username'=> $adminUser['username'] ?? 'admin'
];

echo "--> Step 1: Ingesting & Analyzing Template EXCEL For Upload center (1).xlsx\n";
$templateFile = __DIR__ . '/../Document/EXCEL For Upload center (1).xlsx';
assertCondition("Template workbook exists", file_exists($templateFile));

$mockFiles = [
    'name'     => ['EXCEL For Upload center (1).xlsx'],
    'type'     => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'tmp_name' => [$templateFile],
    'error'    => [UPLOAD_ERR_OK],
    'size'     => [filesize($templateFile)]
];

$analysis = analyzeUploadedFiles($mockFiles, $testUser);
assertCondition("Analysis API returns success (ok = true)", $analysis['ok'] === true);
assertCondition("Detected exactly 11 worksheets", count($analysis['files']) === 11, "Count was: " . count($analysis['files']));

$expectedWorksheetNames = [
    '01.ITEM MASTER PRODUCT MASTER',
    '02.WORK CENTER MASTER',
    '03.PARTY MASTER',
    '04.COST_CENTER_MASTER',
    '05. STANDARD_COST',
    '05.A_BOM',
    '05.B_ROUTING',
    '06.MATERIAL TRANSACTION',
    '07.DOWNTIME',
    '08.FINANCE TRANSACTION',
    '09.TRIAL BALANCE'
];

$detectedNames = array_column($analysis['files'], 'sheet_name');
foreach ($expectedWorksheetNames as $idx => $sName) {
    assertCondition("Worksheet #".($idx+1)." '{$sName}' recognized", in_array($sName, $detectedNames, true));
}

$expectedTables = [
    'items', 'work_centers', 'party_master', 'cost_center_master',
    'standard_cost', 'bom', 'routing', 'material_transaction',
    'downtime', 'finance_transaction', 'trial_balance'
];
$detectedTables = array_column($analysis['files'], 'target_table');
foreach ($expectedTables as $tName) {
    assertCondition("Target table '{$tName}' mapped", in_array($tName, $detectedTables, true));
}

echo "\n--> Step 2: Testing Preview & Column Mapping API for Worksheets\n";
$firstFileId = $analysis['files'][0]['id'];
$preview = getFilePreviewData($firstFileId, $testUser);
assertCondition("Preview API returns success", $preview['ok'] === true);
assertCondition("Preview returns headers count >= 20", count($preview['headers']) >= 20, "Headers count: " . count($preview['headers']));
assertCondition("Auto-mapping generated for headers", !empty($preview['auto_mapping']));
assertCondition("Target columns discovered in Customer DB", !empty($preview['target_columns']));
assertCondition("Organization scope returned in preview", $preview['org_context']['customer_code'] === 'CUST001' && $preview['org_context']['company_code'] === 'COMP001');

echo "\n--> Step 3: Ingesting Populated Test Workbook with 11 Sheets\n";
$populatedFile = __DIR__ . '/test_upload_populated.xlsx';
assertCondition("Populated test file exists", file_exists($populatedFile));

$mockPopulatedFiles = [
    'name'     => ['test_upload_populated.xlsx'],
    'type'     => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'tmp_name' => [$populatedFile],
    'error'    => [UPLOAD_ERR_OK],
    'size'     => [filesize($populatedFile)]
];

$popAnalysis = analyzeUploadedFiles($mockPopulatedFiles, $testUser);
assertCondition("Populated file analyzed successfully", $popAnalysis['ok'] === true);
$uploadRunId = $popAnalysis['upload_id'];
assertCondition("Upload run ID assigned: {$uploadRunId}", $uploadRunId > 0);

echo "\n--> Step 4: Executing Atomic Data Import into Customer Database\n";
$importResult = executeDataImport($uploadRunId, $testUser);
assertCondition("Import execution returned ok = true", $importResult['ok'] === true, $importResult['message'] ?? '');
assertCondition("Records imported > 0", $importResult['imported'] > 0, "Imported: " . $importResult['imported']);
assertCondition("Target Customer Database resolved: " . $importResult['database_name'], !empty($importResult['database_name']));

echo "\n--> Step 5: Verifying Enforced Organization Fields (Anti-Spoofing)\n";
$custDbContext = getUserCustomerDbContext($testUser);
$custPdo = $custDbContext['pdo'];

// Check items table
$itemRow = $custPdo->query("SELECT * FROM public.items WHERE item_code = 'ITEM-TEST-001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Item ITEM-TEST-001 inserted in Customer DB", !empty($itemRow));
assertCondition("customer_code is CUST001 (NOT SPOOF_CUST)", $itemRow['customer_code'] === 'CUST001', "Got: " . ($itemRow['customer_code'] ?? 'null'));
assertCondition("company_code is COMP001 (NOT SPOOF_COMP)", $itemRow['company_code'] === 'COMP001', "Got: " . ($itemRow['company_code'] ?? 'null'));
assertCondition("division_code is DIV001 (NOT SPOOF_DIV)", $itemRow['division_code'] === 'DIV001', "Got: " . ($itemRow['division_code'] ?? 'null'));
assertCondition("location_code is PLANT001 (NOT SPOOF_LOC)", $itemRow['location_code'] === 'PLANT001', "Got: " . ($itemRow['location_code'] ?? 'null'));

// Check work_centers
$wcRow = $custPdo->query("SELECT * FROM public.work_centers WHERE wc_code = 'WC-TEST-01' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Work Center WC-TEST-01 inserted", !empty($wcRow));
assertCondition("Work Center location_code is PLANT001", $wcRow['location_code'] === 'PLANT001');

// Check party_master
$partyRow = $custPdo->query("SELECT * FROM public.party_master WHERE party_code = 'PARTY-TEST-01' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Party Master PARTY-TEST-01 inserted", !empty($partyRow));
assertCondition("Party Master company_code is COMP001", $partyRow['company_code'] === 'COMP001');

// Check cost_center_master
$ccRow = $custPdo->query("SELECT * FROM public.cost_center_master WHERE cc_code = 'CC-TEST-01' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Cost Center CC-TEST-01 inserted", !empty($ccRow));

// Check standard_cost
$stdRow = $custPdo->query("SELECT * FROM public.standard_cost WHERE product_code = 'ITEM-TEST-002' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Standard Cost for ITEM-TEST-002 inserted", !empty($stdRow));
assertCondition("Standard Cost std_revision_date parsed correctly", $stdRow['std_revision_date'] === '2026-07-01');

// Check bom
$bomRow = $custPdo->query("SELECT * FROM public.bom WHERE parent_item_code = 'ITEM-TEST-002' AND item_code = 'ITEM-TEST-001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("BOM parent ITEM-TEST-002 -> child ITEM-TEST-001 inserted", !empty($bomRow));

// Check routing
$routeRow = $custPdo->query("SELECT * FROM public.routing WHERE product_code = 'ITEM-TEST-002' AND cc_code = 'CC-TEST-01' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Routing for ITEM-TEST-002 inserted", !empty($routeRow));

// Check material_transaction
$matRow = $custPdo->query("SELECT * FROM public.material_transaction WHERE doc_no = 'GRN-2026-001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Material Transaction GRN-2026-001 inserted", !empty($matRow));

// Check downtime
$dtRow = $custPdo->query("SELECT * FROM public.downtime WHERE machine_code = 'WC-TEST-01' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Downtime log for WC-TEST-01 inserted", !empty($dtRow));

// Check finance_transaction
$finRow = $custPdo->query("SELECT * FROM public.finance_transaction WHERE no = 'VOUCH-001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Finance Transaction VOUCH-001 inserted", !empty($finRow));

// Check trial_balance
$tbRow = $custPdo->query("SELECT * FROM public.trial_balance WHERE acc_code = 'GL-50100' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Trial Balance GL-50100 inserted", !empty($tbRow));

echo "\n--> Step 6: Verifying Audit Fields (created_by, created_at, modified_by, modified_at)\n";
assertCondition("created_by set to authenticated user ID {$userId} (NOT 9999)", (int)$itemRow['created_by'] === $userId, "Got: " . $itemRow['created_by']);
assertCondition("modified_by set to authenticated user ID {$userId} (NOT 9999)", (int)$itemRow['modified_by'] === $userId, "Got: " . $itemRow['modified_by']);
assertCondition("created_at is recent valid timestamp (NOT SPOOF_TIME)", !empty($itemRow['created_at']) && strpos($itemRow['created_at'], '202') === 0);

echo "\n--> Step 7: Verifying Controlled Upsert & Duplicate Handling\n";
$originalCreatedAt = $itemRow['created_at'];
sleep(1); // Ensure timestamp advances

$reimportResult = executeDataImport($uploadRunId, $testUser);
assertCondition("Re-import completed successfully", $reimportResult['ok'] === true);
assertCondition("Re-import updated existing records (> 0 updated)", $reimportResult['updated'] > 0, "Updated count: " . $reimportResult['updated']);

$itemRowAfter = $custPdo->query("SELECT * FROM public.items WHERE item_code = 'ITEM-TEST-001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Original created_at preserved on update", $itemRowAfter['created_at'] === $originalCreatedAt, "Before: {$originalCreatedAt}, After: {$itemRowAfter['created_at']}");
assertCondition("modified_at updated on update", $itemRowAfter['modified_at'] >= $itemRowAfter['created_at']);

echo "\n--> Step 8: Verifying Error Handling & Row-Level Error Trace\n";
// Create invalid test CSV missing required item_code
$invalidCsv = __DIR__ . '/test_invalid_items.csv';
$csvContent = "customer_code,company_code,division_code,location_code,item_type,item_code,item_name\n" .
              "CUST001,COMP001,DIV001,PLANT001,Raw Material,,Missing Item Code Row\n";
file_put_contents($invalidCsv, $csvContent);

$mockInvalidFiles = [
    'name'     => ['test_invalid_items.csv'],
    'type'     => ['text/csv'],
    'tmp_name' => [$invalidCsv],
    'error'    => [UPLOAD_ERR_OK],
    'size'     => [strlen($csvContent)]
];

$invalidAnalysis = analyzeUploadedFiles($mockInvalidFiles, $testUser);
$invalidRunId = $invalidAnalysis['upload_id'];
$invalidImportRes = executeDataImport($invalidRunId, $testUser);

assertCondition("Invalid import flagged errors", $invalidImportRes['failed'] > 0, "Failed count: " . $invalidImportRes['failed']);
$loggedErrors = getImportErrorsByUploadId($invalidRunId);
assertCondition("Error logged in public.import_errors table", !empty($loggedErrors));
if (!empty($loggedErrors)) {
    assertCondition("Error records line number", (int)$loggedErrors[0]['row_number'] === 2, "Row was: " . $loggedErrors[0]['row_number']);
    assertCondition("Error records field 'item_code'", $loggedErrors[0]['field'] === 'item_code', "Field was: " . $loggedErrors[0]['field']);
    assertCondition("Error records error_type 'Missing Required Field'", $loggedErrors[0]['error_type'] === 'Missing Required Field');
}

echo "\n====================================================================\n";
echo "VERIFICATION SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
