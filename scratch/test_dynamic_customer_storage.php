<?php
/**
 * Cost360 - Comprehensive Dynamic Customer Database Storage & Isolation Test Suite
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$globalPdo = getDbConnection();

echo "=================================================================\n";
echo "COST360 DYNAMIC CUSTOMER DB ISOLATION & STORAGE VERIFICATION\n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition($testNum, $title, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] Test $testNum: $title\n";
        if ($details) echo "       $details\n";
    } else {
        $failCount++;
        echo "[FAIL] Test $testNum: $title\n";
        if ($details) echo "       $details\n";
    }
}

// Setup Test Customers 991 and 992
$cids = [991, 992];
foreach ($cids as $cid) {
    // Clean up mapping and customer DB if exists
    $dbName = "cust" . $cid;
    try {
        $globalPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
    } catch (Throwable $e) {}
    $globalPdo->exec("DELETE FROM customer_databases WHERE customer_id = {$cid}");
    $globalPdo->exec("DELETE FROM user_organization_access WHERE customer_id = {$cid}");
    $globalPdo->exec("DELETE FROM customers WHERE customer_id = {$cid}");

    $insCust = $globalPdo->prepare("
        INSERT INTO customers (customer_id, customer_code, customer_name, status)
        VALUES (:cid, :ccode, :cname, 'Active')
        ON CONFLICT (customer_id) DO NOTHING
    ");
    $insCust->execute([
        ':cid' => $cid,
        ':ccode' => "TESTCUST_{$cid}",
        ':cname' => "Test Customer {$cid}"
    ]);
}

// -------------------------------------------------------------
// TEST 1: Customer Creation ? Database provisioned & initially EMPTY
// -------------------------------------------------------------
$provResult991 = provisionCustomerDatabase($globalPdo, 991, 'TESTCUST_991');
assertCondition(1, "Customer 991 DB Provisioning", $provResult991['success'] === true, "Database name: " . ($provResult991['database_name'] ?? 'none'));

$custPdo991 = getCustomerDbConnection("cust991");
$tables991 = $custPdo991->query("
    SELECT table_name FROM information_schema.tables 
    WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
")->fetchAll(PDO::FETCH_COLUMN);

assertCondition(2, "Customer 991 DB is 100% EMPTY on creation", count($tables991) === 0, "Tables in cust991: " . json_encode($tables991) . " (Count: " . count($tables991) . ")");

// -------------------------------------------------------------
// TEST 2: Dynamic Table Creation on Upload for Customer 991
// -------------------------------------------------------------
$mockUser991 = [
    'user_id' => 9910,
    'username' => 'testuser991@example.com',
    'email' => 'testuser991@example.com',
    'customer_id' => 991
];

// Set active session for user 991
$_SESSION['user_id'] = 9910;
$_SESSION['active_customer_id'] = 991;

// Ensure target table 'items' exists dynamically via helper
$createdDynamic = ensureTargetTableExistsInCustomerDb($custPdo991, 'items');
assertCondition(3, "Dynamic table creation for 'items'", $createdDynamic === true);

$tables991After = $custPdo991->query("
    SELECT table_name FROM information_schema.tables 
    WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
")->fetchAll(PDO::FETCH_COLUMN);

assertCondition(4, "Only 'items' table exists in cust991", in_array('items', $tables991After, true) && !in_array('bom', $tables991After, true), "Tables in cust991: " . json_encode($tables991After));

// Insert test items for Customer 991
$ins991 = $custPdo991->prepare("INSERT INTO public.items (item_code, item_name, rate, status) VALUES (:code, :name, :rate, 'Active')");
$ins991->execute([':code' => 'ITEM-991-A', ':name' => 'Widget 991 Alpha', ':rate' => 100.50]);
$ins991->execute([':code' => 'ITEM-991-B', ':name' => 'Widget 991 Beta', ':rate' => 250.00]);

$count991 = (int)$custPdo991->query("SELECT count(*) FROM public.items")->fetchColumn();
assertCondition(5, "Customer 991 data stored in cust991.items", $count991 === 2, "Items count in cust991: $count991");

// -------------------------------------------------------------
// TEST 3: Customer 992 DB is initially EMPTY and completely isolated
// -------------------------------------------------------------
$provResult992 = provisionCustomerDatabase($globalPdo, 992, 'TESTCUST_992');
$custPdo992 = getCustomerDbConnection("cust992");
$tables992 = $custPdo992->query("
    SELECT table_name FROM information_schema.tables 
    WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
")->fetchAll(PDO::FETCH_COLUMN);

assertCondition(6, "Customer 992 DB is 100% EMPTY on creation", count($tables992) === 0, "Tables in cust992: " . json_encode($tables992));

// Customer 992 now uploads 1 item
ensureTargetTableExistsInCustomerDb($custPdo992, 'items');
$ins992 = $custPdo992->prepare("INSERT INTO public.items (item_code, item_name, rate, status) VALUES (:code, :name, :rate, 'Active')");
$ins992->execute([':code' => 'ITEM-992-X', ':name' => 'Gizmo 992 X', ':rate' => 500.00]);

$count992 = (int)$custPdo992->query("SELECT count(*) FROM public.items")->fetchColumn();
$count991Check = (int)$custPdo991->query("SELECT count(*) FROM public.items")->fetchColumn();

assertCondition(7, "Customer 992 items count in cust992.items is 1", $count992 === 1);
assertCondition(8, "Customer 991 items count in cust991.items remains 2 (unaffected)", $count991Check === 2);

// Check that Item codes are not visible across customer databases
$itemFrom992In991 = $custPdo991->query("SELECT 1 FROM public.items WHERE item_code = 'ITEM-992-X'")->fetch();
$itemFrom991In992 = $custPdo992->query("SELECT 1 FROM public.items WHERE item_code = 'ITEM-991-A'")->fetch();

assertCondition(9, "Strict Data Isolation: No cross-customer visibility", !$itemFrom992In991 && !$itemFrom991In992, "cust991 has ITEM-992-X: " . ($itemFrom992In991 ? 'YES' : 'NO') . " | cust992 has ITEM-991-A: " . ($itemFrom991In992 ? 'YES' : 'NO'));

// -------------------------------------------------------------
// TEST 4: Global DB (cost360-neon-local) stores NO customer business data
// -------------------------------------------------------------
$globalItemsExist = false;
$chkGlobalItems = $globalPdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'items'")->fetch();
if ($chkGlobalItems) {
    $gItem991 = $globalPdo->query("SELECT 1 FROM public.items WHERE item_code IN ('ITEM-991-A', 'ITEM-992-X')")->fetch();
    $globalItemsExist = (bool)$gItem991;
}
assertCondition(10, "Global DB stores NO customer business rows", $globalItemsExist === false);

// -------------------------------------------------------------
// Clean up test databases
// -------------------------------------------------------------
foreach ($cids as $cid) {
    $dbName = "cust" . $cid;
    try {
        $globalPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
    } catch (Throwable $e) {}
    $globalPdo->exec("DELETE FROM customer_databases WHERE customer_id = {$cid}");
    $globalPdo->exec("DELETE FROM customers WHERE customer_id = {$cid}");
}

echo "\n=================================================================\n";
echo "TEST RESULTS: Total: " . ($passCount + $failCount) . " | Passed: $passCount | Failed: $failCount\n";
echo "=================================================================\n";
