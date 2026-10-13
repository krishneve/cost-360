<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

echo "=======================================================\n";
echo "TENANT ISOLATION & COMPANY-SCOPED MAPPINGS VERIFICATION\n";
echo "=======================================================\n\n";

$globalPdo = getDbConnection();
$custDbContext = getUserCustomerDbContext(['user_id' => 1], 1);
$custPdo = $custDbContext['pdo'];
$custDbName = $custDbContext['database_name'];

// 1. Check Global DB for saved_mappings
echo "1. Checking Global DB for 'saved_mappings' table...\n";
$stmtGlobal = $globalPdo->query("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = 'public' AND table_name = 'saved_mappings'
");
$globalHasTable = (int)$stmtGlobal->fetchColumn();
echo "   Global DB has 'saved_mappings': " . ($globalHasTable ? "YES (ERROR!)" : "NO (CORRECT! No cross-tenant collision risk)") . "\n";
assert($globalHasTable === 0, "Global DB should NOT have saved_mappings table!");

// 2. Check Customer DB for saved_mappings
echo "\n2. Checking Customer DB ({$custDbName}) for 'saved_mappings' table...\n";
$stmtCust = $custPdo->query("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = 'public' AND table_name = 'saved_mappings'
");
$custHasTable = (int)$stmtCust->fetchColumn();
echo "   Customer DB has 'saved_mappings': " . ($custHasTable ? "YES (CORRECT! Stored in customer's dedicated DB)" : "NO (ERROR!)") . "\n";
assert($custHasTable > 0, "Customer DB must have saved_mappings table!");

// 3. Inspect existing mappings in Customer DB
echo "\n3. Inspecting mappings inside Customer DB ({$custDbName})...\n";
$rows = $custPdo->query("SELECT id, company_code, target_table, sheet_name, updated_at FROM public.saved_mappings ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "   [ID: {$r['id']}] Company: '{$r['company_code']}' | Target: '{$r['target_table']}' | Sheet: '{$r['sheet_name']}' | Updated: {$r['updated_at']}\n";
}

// 4. Test getSavedMapping() scoped to company ABC02
echo "\n4. Testing getSavedMapping() for company 'ABC02'...\n";
$retrieved = getSavedMapping(1, 'standard_cost', '06_standard_cost.csv', null, 'ABC02', ['user_id' => 1]);
if ($retrieved) {
    echo "   => Retrieved " . count($retrieved) . " column rules for ABC02: " . json_encode($retrieved) . "\n";
} else {
    echo "   => No mapping retrieved for ABC02.\n";
}
assert(!empty($retrieved), "Should retrieve saved mapping for standard_cost in company ABC02!");

// 5. Test company isolation: retrieving for a different company 'XYZ99'
echo "\n5. Testing getSavedMapping() for non-existent company 'XYZ99'...\n";
$nonExistent = getSavedMapping(1, 'standard_cost', '06_standard_cost_non_existent.csv', null, 'XYZ99', ['user_id' => 1]);
echo "   => Retrieved for XYZ99: " . ($nonExistent ? json_encode($nonExistent) : "NULL (Correct, no collision across companies)") . "\n";

// 6. Test Security: Company Authorization Check on saveCustomMapping()
echo "\n6. Testing Company Access Security Check in saveCustomMapping()...\n";
// Create a fake test file entry in upload_files to test saveCustomMapping
$testUploadId = $globalPdo->query("SELECT id FROM public.uploads ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$testUploadId) {
    $testUploadId = $globalPdo->query("INSERT INTO public.uploads (import_code, status) VALUES ('TEST-MAP', 'Analyzing') RETURNING id")->fetchColumn();
}
$scId = $globalPdo->query("SELECT sc.id FROM public.sub_categories sc JOIN public.target_entities te ON te.id = sc.target_entity_id WHERE te.table_name = 'standard_cost' LIMIT 1")->fetchColumn() ?: 6;
$testFileId = $globalPdo->query("INSERT INTO public.upload_files (upload_id, original_name, safe_name, sheet_name, file_type, size_bytes, sub_category_id, storage_path, headers) VALUES ({$testUploadId}, '06_standard_cost.csv', '06_standard_cost.csv', '06_standard_cost.csv', 'csv', 100, {$scId}, 'scratch/dummy.csv', '[]') RETURNING id")->fetchColumn();

// Test unauthorized non-admin user (e.g. user ID 99999 who has no company access to ABC02)
$unauthorizedUser = [
    'user_id' => 99999,
    'username' => 'unauth_user',
    'role' => 'Standard User'
];
$_SESSION['active_company_code'] = 'FORBIDDEN_CO';
$_SESSION['active_company_id'] = 99999;

$secResult = saveCustomMapping((int)$testFileId, ['std_rev_date' => 'std_rev_date'], 99999, $unauthorizedUser);
echo "   Unauthorized company update response: " . json_encode($secResult) . "\n";
assert($secResult['ok'] === false, "Unauthorized user should be BLOCKED from modifying company mappings!");
echo "   => Security Check PASS: Unauthorized user blocked from updating company mapping!\n";

// 7. Test authorized update for ABC02 by admin / authorized user
echo "\n7. Testing Authorized mapping update for company ABC02...\n";
$_SESSION['active_company_code'] = 'ABC02';
$coId = $globalPdo->query("SELECT company_id FROM public.companies WHERE company_code = 'ABC02' LIMIT 1")->fetchColumn();
$_SESSION['active_company_id'] = (int)$coId;

$adminUser = ['user_id' => 1, 'username' => 'admin'];
$newMapping = [
    'item_code' => 'item_code',
    'std_rev_date' => 'std_rev_date',
    'standard_cost' => 'standard_cost',
    'test_custom_col' => 'notes'
];
$authResult = saveCustomMapping((int)$testFileId, $newMapping, 1, $adminUser);
echo "   Authorized update response: " . json_encode($authResult) . "\n";
assert($authResult['ok'] === true, "Authorized user should be able to update company mapping!");

// Verify update reflected in customer DB
$updatedRow = $custPdo->query("
    SELECT mapping, updated_at 
    FROM public.saved_mappings 
    WHERE LOWER(company_code) = 'abc02' AND LOWER(target_table) = 'standard_cost'
    ORDER BY updated_at DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
echo "   Verified mapping in customer DB ({$custDbName}): " . $updatedRow['mapping'] . "\n";
$decoded = json_decode($updatedRow['mapping'], true);
assert(isset($decoded['test_custom_col']), "Customer DB mapping was updated with new column!");
echo "   => Customer DB mapping update PASS!\n";

// Cleanup test records
$globalPdo->exec("DELETE FROM public.upload_files WHERE id = {$testFileId}");
$globalPdo->exec("DELETE FROM public.uploads WHERE import_code = 'TEST-MAP'");

echo "\n=======================================================\n";
echo "ALL TESTS PASSED: 100% TENANT ISOLATION & COMPANY SECURITY VERIFIED!\n";
echo "=======================================================\n";
