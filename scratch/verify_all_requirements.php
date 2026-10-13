<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

echo "====================================================\n";
echo "COST360 MULTI-DATABASE & UPLOAD ISOLATION TEST SUITE\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition($desc, $cond, $detail = '') {
    global $passCount, $failCount;
    if ($cond) {
        $passCount++;
        echo "[PASS] {$desc}\n";
    } else {
        $failCount++;
        echo "[FAIL] {$desc}" . ($detail ? " ($detail)" : "") . "\n";
    }
}

// -------------------------------------------------------------
// TEST 1: Provision Customer A (ID 1) and Customer B (ID 2)
// -------------------------------------------------------------
echo "--- TEST 1: Database Provisioning ---\n";
try {
    $res1 = provisionCustomerDatabase(1);
    assertCondition("Customer 1 DB Provisioned", $res1['success'] === true && $res1['database_name'] === 'cust_1');
} catch (Throwable $e) {
    assertCondition("Customer 1 DB Provisioned", false, $e->getMessage());
}

try {
    $res2 = provisionCustomerDatabase(2);
    assertCondition("Customer 2 DB Provisioned", $res2['success'] === true && $res2['database_name'] === 'cust_2');
} catch (Throwable $e) {
    assertCondition("Customer 2 DB Provisioned", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 2: Schema Inspection (empty periods, uoms, items)
// -------------------------------------------------------------
echo "\n--- TEST 2: Schema & Initial Emptiness ---\n";
try {
    $pdo1 = getCustomerDbConnection(1);
    $periodsCnt1 = (int)$pdo1->query("SELECT COUNT(*) FROM periods")->fetchColumn();
    $uomsCnt1 = (int)$pdo1->query("SELECT COUNT(*) FROM uoms")->fetchColumn();
    $itemsCnt1 = (int)$pdo1->query("SELECT COUNT(*) FROM items")->fetchColumn();

    assertCondition("cust_1.periods exists and is empty", $periodsCnt1 === 0, "Count: $periodsCnt1");
    assertCondition("cust_1.uoms exists and is empty", $uomsCnt1 === 0, "Count: $uomsCnt1");
    assertCondition("cust_1.items exists and is empty", $itemsCnt1 === 0, "Count: $itemsCnt1");
} catch (Throwable $e) {
    assertCondition("cust_1 Schema Check", false, $e->getMessage());
}

try {
    $pdo2 = getCustomerDbConnection(2);
    $periodsCnt2 = (int)$pdo2->query("SELECT COUNT(*) FROM periods")->fetchColumn();
    $uomsCnt2 = (int)$pdo2->query("SELECT COUNT(*) FROM uoms")->fetchColumn();
    $itemsCnt2 = (int)$pdo2->query("SELECT COUNT(*) FROM items")->fetchColumn();

    assertCondition("cust_2.periods exists and is empty", $periodsCnt2 === 0, "Count: $periodsCnt2");
    assertCondition("cust_2.uoms exists and is empty", $uomsCnt2 === 0, "Count: $uomsCnt2");
    assertCondition("cust_2.items exists and is empty", $itemsCnt2 === 0, "Count: $itemsCnt2");
} catch (Throwable $e) {
    assertCondition("cust_2 Schema Check", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 3: customer_databases Source of Truth Mapping Check
// -------------------------------------------------------------
echo "\n--- TEST 3: customer_databases Table Mapping ---\n";
try {
    $centralPdo = getDbConnection();
    $m1 = $centralPdo->query("SELECT * FROM customer_databases WHERE customer_id = 1")->fetch(PDO::FETCH_ASSOC);
    $m2 = $centralPdo->query("SELECT * FROM customer_databases WHERE customer_id = 2")->fetch(PDO::FETCH_ASSOC);

    assertCondition("customer_database record for Customer 1 is cust_1", $m1 && $m1['database_name'] === 'cust_1');
    assertCondition("customer_database record for Customer 2 is cust_2", $m2 && $m2['database_name'] === 'cust_2');
} catch (Throwable $e) {
    assertCondition("customer_databases check", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 4: Idempotency (Re-running provisionCustomerDatabase)
// -------------------------------------------------------------
echo "\n--- TEST 4: Idempotency Check ---\n";
try {
    $reProv1 = provisionCustomerDatabase(1);
    assertCondition("Re-provisioning cust_1 is safe & idempotent", $reProv1['success'] === true);
} catch (Throwable $e) {
    assertCondition("Re-provisioning cust_1", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 5: Security / RBAC Cross-Tenant Isolation
// -------------------------------------------------------------
echo "\n--- TEST 5: Security & Cross-Tenant Access Rejection ---\n";
// User 7 (rohit) is Customer 3 administrator. Should NOT have access to Customer 1 DB.
$canAccess = userCanAccessCustomerDb(7, 1);
assertCondition("Customer 3 user is rejected from accessing Customer 1 DB", $canAccess === false);

$rejected = false;
try {
    getCustomerDbConnection(1, 7); // Customer ID 1, User ID 7
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'Unauthorized access') !== false) {
        $rejected = true;
    }
}
assertCondition("getCustomerDbConnection throws Unauthorized exception on cross-tenant access", $rejected);

// -------------------------------------------------------------
// TEST 6: Upload Import Isolation (Customer 1 vs Customer 2)
// -------------------------------------------------------------
echo "\n--- TEST 6: Upload Import Isolation ---\n";
try {
    // 1. Insert an Item into Customer 1 DB directly simulating upload engine
    $cust1Pdo = getCustomerDbConnection(1);
    
    // Ensure UOM
    $cust1Pdo->exec("INSERT INTO uoms (uom_code, uom_name, status) VALUES ('PCS', 'Pieces', 'Active') ON CONFLICT DO NOTHING");
    $uomId1 = $cust1Pdo->query("SELECT uom_id FROM uoms WHERE uom_code = 'PCS'")->fetchColumn();

    $cust1Pdo->exec("INSERT INTO items (item_code, item_name, item_type, uom_id, status) VALUES ('TEST-CUST1-ITEM', 'Customer 1 Special Motor', 'Finished Good', {$uomId1}, 'Active') ON CONFLICT DO NOTHING");

    $cust1Items = $cust1Pdo->query("SELECT COUNT(*) FROM items WHERE item_code = 'TEST-CUST1-ITEM'")->fetchColumn();
    assertCondition("Customer 1 DB contains Customer 1 item", $cust1Items > 0);

    // 2. Check Customer 2 DB (MUST NOT contain Customer 1 item)
    $cust2Pdo = getCustomerDbConnection(2);
    $cust2Items = $cust2Pdo->query("SELECT COUNT(*) FROM items WHERE item_code = 'TEST-CUST1-ITEM'")->fetchColumn();
    assertCondition("Customer 2 DB DOES NOT contain Customer 1 item (Strict Isolation)", (int)$cust2Items === 0);

    // 3. Check Global DB items table (MUST NOT contain customer item)
    $globalItems = $centralPdo->query("SELECT COUNT(*) FROM items WHERE item_code = 'TEST-CUST1-ITEM'")->fetchColumn();
    assertCondition("Global DB DOES NOT contain Customer 1 item (Clean separation)", (int)$globalItems === 0);

} catch (Throwable $e) {
    assertCondition("Upload Isolation Test", false, $e->getMessage());
}

echo "\n====================================================\n";
echo "TEST RESULTS SUMMARY: {$passCount} PASSED | {$failCount} FAILED\n";
echo "====================================================\n";
