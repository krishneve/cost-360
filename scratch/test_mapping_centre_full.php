<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

echo "=======================================================\n";
echo "MAPPING CENTRE & CUSTOMER DB INTEGRATION VERIFICATION\n";
echo "=======================================================\n\n";

$adminUser = ['user_id' => 1, 'username' => 'admin'];
$_SESSION['active_company_code'] = 'ABC02';
$globalPdo = getDbConnection();
$coId = $globalPdo->query("SELECT company_id FROM public.companies WHERE company_code = 'ABC02' LIMIT 1")->fetchColumn();
$_SESSION['active_company_id'] = (int)$coId;

// 1. Test getMappingConfigurationsData()
echo "1. Testing getMappingConfigurationsData()...\n";
$data = getMappingConfigurationsData($adminUser);
$mappings = $data['mappings'] ?? [];
echo "   Database Name: {$data['database_name']}\n";
echo "   Active Company: {$data['company_code']}\n";
echo "   Can Edit: " . ($data['can_edit'] ? 'YES' : 'NO') . "\n";
echo "   Total Datasets: " . count($mappings) . "\n\n";

assert(count($mappings) === 11, "Must have exactly 11 datasets mapped for company ABC02!");

$expectedTables = [
    'items', 'work_centers', 'party_master', 'cost_center_master',
    'standard_cost', 'bom', 'routing', 'material_transaction',
    'downtime', 'finance_transaction', 'trial_balance'
];

$foundTables = array_column($mappings, 'target_table');
foreach ($expectedTables as $exp) {
    $found = in_array($exp, $foundTables, true);
    echo "   - Table [{$exp}]: " . ($found ? "FOUND (PASS)" : "MISSING (FAIL)") . "\n";
    assert($found, "Table {$exp} must exist in customer saved_mappings!");
}

// 2. Test Editing a Mapping via updateCustomerSavedMapping()
echo "\n2. Testing updateCustomerSavedMapping() for 'standard_cost'...\n";
$customStdCostMap = [
    'product_code'            => 'product_code',
    'std_material'            => 'std_material',
    'std_conversion'          => 'std_conversion',
    'std_total_cost'          => 'std_total_cost',
    'std_rev_date'            => 'std_revision_date',
    'custom_user_revision'    => 'std_revision_date',
    'processed_material'      => 'processed_material',
    'processed_conversion'    => 'processed_conversion',
    'processed_total_cost'    => 'processed_total_cost'
];

$updRes = updateCustomerSavedMapping('standard_cost', $customStdCostMap, '05. STANDARD_COST', 'ABC02', $adminUser);
echo "   Update response: " . json_encode($updRes) . "\n";
assert($updRes['ok'] === true, "updateCustomerSavedMapping must succeed!");

// 3. Verify that the update exists in cost360_customer_cust001.saved_mappings
echo "\n3. Verifying updated mapping directly in Customer DB (cost360_customer_cust001)...\n";
$ctx = getUserCustomerDbContext($adminUser);
$custPdo = $ctx['pdo'];
$dbRow = $custPdo->query("
    SELECT mapping, updated_at 
    FROM public.saved_mappings 
    WHERE LOWER(company_code) = 'abc02' AND LOWER(target_table) = 'standard_cost' 
    ORDER BY updated_at DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$savedJson = json_decode($dbRow['mapping'], true);
assert(isset($savedJson['custom_user_revision']), "Customer DB mapping must contain 'custom_user_revision'!");
echo "   Customer DB mapping verified: 'custom_user_revision' &rarr; '{$savedJson['custom_user_revision']}'\n";
echo "   => DB Persistence PASS!\n";

// 4. Verify Upload Centre auto-recognition (Next time user uploads)
echo "\n4. Verifying Upload Centre auto-recognition via getSavedMapping()...\n";
$autoRetrieved = getSavedMapping(1, 'standard_cost', '06_standard_cost.csv', null, 'ABC02', $adminUser);
echo "   Upload Centre retrieved: " . json_encode($autoRetrieved) . "\n";
assert(isset($autoRetrieved['custom_user_revision']), "Upload Centre must see the updated mapping!");
echo "   => Upload Centre Auto-Recognition PASS!\n";

// 5. Test Company Access Security Guard (Unauthorized user should be blocked)
echo "\n5. Testing Security Guard: Unauthorized user trying to edit ABC02...\n";
$unauth = ['user_id' => 88888, 'username' => 'unauth_user'];
$_SESSION['active_company_code'] = 'OTHER_CO';
$_SESSION['active_company_id'] = 88888;
$blockRes = updateCustomerSavedMapping('standard_cost', ['a' => 'b'], '05. STANDARD_COST', 'ABC02', $unauth);
echo "   Unauthorized update result: " . json_encode($blockRes) . "\n";
assert($blockRes['ok'] === false, "Unauthorized user must be blocked!");
echo "   => Security Authorization Guard PASS!\n";

echo "\n=======================================================\n";
echo "ALL TESTS PASSED: MAPPING CENTRE IS 100% FUNCTIONAL & INTEGRATED!\n";
echo "=======================================================\n";
