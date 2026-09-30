<?php
/**
 * COST360 — Customer Master Subscription End-to-End Integration Test
 * Step 3: Validates that when a Project Admin creates a new customer via Customer Master:
 *   1. Customer is created normally
 *   2. Automatically assigned FREE subscription
 *   3. FREE max_users = 0 (retrieved from subscription_plans)
 *   4. Start date, ACTIVE status, and assigner recorded
 *   5. Subsequent new user creation blocked under FREE plan
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/subscription.php';

$pdo = getDbConnection();

echo "=====================================================================\n";
echo "COST360 — CUSTOMER MASTER SUBSCRIPTION INTEGRATION TEST\n";
echo "=====================================================================\n\n";

$pass = 0;
$fail = 0;

function runTest(string $title, bool $cond, string $msg = ''): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo " [PASS] {$title}" . ($msg !== '' ? " ({$msg})" : "") . "\n";
    } else {
        $fail++;
        echo " [FAIL] {$title}" . ($msg !== '' ? " -> {$msg}" : "") . "\n";
    }
}

$cookieFile = __DIR__ . '/_test_session_cookie.txt';
$testCode = 'CUST_AUTO_' . time();
$testEmail = 'admin_' . time() . '@test-auto-sub.example';
$testName = 'Automated Tenant ' . time();

try {
    // 1. Authenticate as Platform Admin via HTTP
    echo "1. Authenticating as Platform Administrator via HTTP ...\n";
    $loginCmd = 'curl.exe -s -c "' . $cookieFile . '" -X POST -d "email=admin@cost360.com&password=12345678" -H "X-Requested-With: XMLHttpRequest" http://localhost/cost-360-main/login.php';
    $loginResp = shell_exec($loginCmd);
    $loginData = json_decode($loginResp, true);
    runTest("Login as Platform Admin", ($loginData['success'] ?? false) === true);

    // 2. Submit Create Customer Wizard via API
    echo "\n2. Submitting Customer Creation Wizard (Code: {$testCode}) ...\n";
    $postFields = http_build_query([
        'action'          => 'create_customer',
        'customer_code'   => $testCode,
        'customer_name'   => $testName,
        'contact_person'  => 'Alexander Wright',
        'contact_email'   => 'alex.wright@example.com',
        'contact_phone'   => '+91 98765 43210',
        'address'         => 'Plot 42, Sector 8, Tech Zone',
        'status'          => 'Active',
        'admin_full_name' => 'Alexander Wright Admin',
        'admin_email'     => $testEmail,
        'admin_phone'     => '+91 98765 43210',
        'admin_password'  => 'SecurePass123!',
        'company_code'    => 'COMP_' . substr($testCode, -4),
        'company_name'    => 'Auto Enterprise Ltd',
        'division_code'   => 'DIV_HQ',
        'division_name'   => 'Headquarters Division'
    ]);

    $createCmd = 'curl.exe -s -b "' . $cookieFile . '" -X POST -d "' . $postFields . '" http://localhost/cost-360-main/api/customers_api.php';
    $createResp = shell_exec($createCmd);
    $createData = json_decode($createResp, true);

    runTest("Customer Master API returns valid response", is_array($createData), substr($createResp, 0, 100));
    runTest("Customer successfully created", ($createData['success'] ?? false) === true, $createData['message'] ?? ($createData['error'] ?? ''));

    $createdCustomerId = (int)($createData['customer_id'] ?? 0);
    runTest("Valid Customer ID returned", $createdCustomerId > 0, "Customer ID: {$createdCustomerId}");

    // Check response payload for automatically assigned subscription
    $respSub = $createData['subscription'] ?? [];
    runTest("API response reports automatic FREE subscription", ($respSub['plan_code'] ?? '') === 'FREE', "Plan: " . ($respSub['plan_code'] ?? 'NONE'));
    runTest("API response reports max_users = 0", ($respSub['max_users'] ?? -1) === 0, "max_users: " . ($respSub['max_users'] ?? -1));
    runTest("API response reports status = ACTIVE", ($respSub['status'] ?? '') === 'ACTIVE', "status: " . ($respSub['status'] ?? 'NONE'));

    // 3. Database Verification
    echo "\n3. Direct Database Verification for Customer #{$createdCustomerId}:\n";
    $stmtC = $pdo->prepare("SELECT customer_id, customer_code, customer_name, status FROM customers WHERE customer_id = ?");
    $stmtC->execute([$createdCustomerId]);
    $dbCust = $stmtC->fetch(PDO::FETCH_ASSOC);
    runTest("Customer record persisted in 'customers'", $dbCust !== false && $dbCust['customer_code'] === $testCode);

    // Verify Customer table does NOT have subscription columns
    $stmtCheckCols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'customers' AND column_name LIKE '%subscr%'");
    $subColsInCustomers = $stmtCheckCols->fetchAll(PDO::FETCH_COLUMN);
    runTest("No subscription columns inside 'customers' table", count($subColsInCustomers) === 0);

    // 4. Verify Active Subscription Record
    echo "\n4. Verifying customer_subscriptions Table Record:\n";
    $activeSub = getCustomerActiveSubscription($createdCustomerId);
    runTest("Active subscription record exists in DB", $activeSub !== null);
    runTest("Assigned plan is 'FREE'", ($activeSub['plan_code'] ?? '') === 'FREE', "Plan Code: {$activeSub['plan_code']}");
    runTest("Plan max_users is 0", ($activeSub['max_users'] ?? -1) === 0, "max_users: {$activeSub['max_users']}");
    runTest("Status is 'ACTIVE'", strtoupper($activeSub['status'] ?? '') === 'ACTIVE', "Status: {$activeSub['status']}");
    runTest("Start date is set to CURRENT_DATE", ($activeSub['start_date'] ?? '') === date('Y-m-d'), "start_date: {$activeSub['start_date']}");
    runTest("Assigned_by is recorded as Project Admin (ID: 1)", (int)($activeSub['assigned_by'] ?? 0) === 1, "assigned_by: {$activeSub['assigned_by']}");

    // 5. Verify Plan FK links to subscription_plans
    $stmtPlanCheck = $pdo->prepare("
        SELECT sp.plan_code, sp.max_users 
        FROM customer_subscriptions cs 
        JOIN subscription_plans sp ON cs.plan_id = sp.plan_id 
        WHERE cs.subscription_id = ?
    ");
    $stmtPlanCheck->execute([$activeSub['subscription_id']]);
    $dbPlan = $stmtPlanCheck->fetch(PDO::FETCH_ASSOC);
    runTest("Linked foreign key points to subscription_plans", $dbPlan !== false && $dbPlan['plan_code'] === 'FREE');

    // 6. User Limit Enforcement Check
    echo "\n5. Verifying User Limit Enforcement on this Customer:\n";
    $limitCheck = checkUserLimit($createdCustomerId);
    runTest("New user creation is BLOCKED under FREE plan", $limitCheck['allowed'] === false, "Allowed: false");
    runTest("Reason specifically cites FREE plan 0 user limit", strpos($limitCheck['reason'], 'limit of 0 users') !== false, $limitCheck['reason']);
    runTest("Available user slots equals 0", $limitCheck['available_users'] === 0, "Available: {$limitCheck['available_users']}");

    // 7. Verify Attempt to Create New User is Rejected
    echo "\n6. Verifying API User Creation Block under FREE Plan:\n";
    $userPostFields = http_build_query([
        'action'      => 'create_customer_user',
        'customer_id' => $createdCustomerId,
        'full_name'   => 'Blocked User',
        'email'       => 'blocked_' . time() . '@example.com',
        'password'    => 'Pass1234!',
        'role_id'     => 4
    ]);
    $addUserCmd = 'curl.exe -s -b "' . $cookieFile . '" -X POST -d "' . $userPostFields . '" http://localhost/cost-360-main/admin/customers/api.php';
    $addUserResp = shell_exec($addUserCmd);
    $addUserData = json_decode($addUserResp, true);
    $apiErr = (string)($addUserData['error'] ?? ($addUserData['message'] ?? $addUserResp));
    runTest("API rejects adding new user to FREE customer", ($addUserData['success'] ?? true) === false, $apiErr);
    runTest("API rejection message mentions user limit", stripos($apiErr, 'limit') !== false || stripos($apiErr, 'FREE') !== false, $apiErr);

    // 8. Clean Up
    echo "\n7. Cleaning Up Test Customer & Session Cookie ...\n";
    $pdo->prepare("DELETE FROM customer_subscriptions WHERE customer_id = ?")->execute([$createdCustomerId]);
    $pdo->prepare("DELETE FROM user_organization_access WHERE customer_id = ?")->execute([$createdCustomerId]);
    $pdo->prepare("DELETE FROM warehouses WHERE plant_id IN (SELECT plant_id FROM plants WHERE division_id IN (SELECT division_id FROM divisions WHERE company_id IN (SELECT company_id FROM companies WHERE customer_id = ?)))")->execute([$createdCustomerId]);
    $pdo->prepare("DELETE FROM plants WHERE division_id IN (SELECT division_id FROM divisions WHERE company_id IN (SELECT company_id FROM companies WHERE customer_id = ?))")->execute([$createdCustomerId]);
    $pdo->prepare("DELETE FROM divisions WHERE company_id IN (SELECT company_id FROM companies WHERE customer_id = ?)")->execute([$createdCustomerId]);
    $pdo->prepare("DELETE FROM companies WHERE customer_id = ?")->execute([$createdCustomerId]);
    $pdo->prepare("DELETE FROM user_roles WHERE user_id IN (SELECT user_id FROM users WHERE email = ?)")->execute([$testEmail]);
    $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$testEmail]);
    $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$createdCustomerId]);

    // Also clean up Customer 9 from earlier run if still present
    $pdo->prepare("DELETE FROM customer_subscriptions WHERE customer_id = 9")->execute();
    $pdo->prepare("DELETE FROM user_organization_access WHERE customer_id = 9")->execute();
    $pdo->prepare("DELETE FROM companies WHERE customer_id = 9")->execute();
    $pdo->prepare("DELETE FROM users WHERE email LIKE 'admin_%@test-auto-sub.example'")->execute();
    $pdo->prepare("DELETE FROM customers WHERE customer_id = 9")->execute();

    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
    echo " Cleanup completed.\n";

    echo "\n=====================================================================\n";
    echo "SUMMARY: {$pass} PASSED, {$fail} FAILED\n";
    if ($fail === 0) {
        echo "ALL CUSTOMER MASTER INTEGRATION TESTS PASSED PERFECTLY!\n";
    }
    echo "=====================================================================\n";

} catch (Throwable $e) {
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
    echo "\nFATAL TEST ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
