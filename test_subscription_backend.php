<?php
/**
 * COST360 — Subscription Management Backend Test Suite
 * Step 2: Validates all required functions, core business rules, upgrade/downgrade logic,
 * user limits, and security checks.
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/subscription.php';

$pdo = getDbConnection();

echo "=====================================================================\n";
echo "COST360 — SUBSCRIPTION MANAGEMENT BACKEND TEST SUITE\n";
echo "=====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $testName, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$testName}" . ($details !== '' ? " ({$details})" : "") . "\n";
    } else {
        $failCount++;
        echo " [FAIL] {$testName}" . ($details !== '' ? " -> {$details}" : "") . "\n";
    }
}

try {
    // -------------------------------------------------------------
    // TEST 1: getSubscriptionPlans()
    // -------------------------------------------------------------
    echo "--- 1. Testing getSubscriptionPlans() ---\n";
    $plans = getSubscriptionPlans(true);
    assertTest("Fetch active subscription plans", count($plans) >= 4, "Found " . count($plans) . " plans");

    $freePlan = null;
    $standardPlan = null;
    $profPlan = null;
    $enterprisePlan = null;

    foreach ($plans as $p) {
        if ($p['plan_code'] === 'FREE') $freePlan = $p;
        if ($p['plan_code'] === 'STANDARD') $standardPlan = $p;
        if ($p['plan_code'] === 'PROFESSIONAL') $profPlan = $p;
        if ($p['plan_code'] === 'ENTERPRISE') $enterprisePlan = $p;
    }

    assertTest("FREE plan exists with max_users = 0", $freePlan !== null && $freePlan['max_users'] === 0, "max_users = {$freePlan['max_users']}");
    assertTest("STANDARD plan exists with max_users = 15", $standardPlan !== null && $standardPlan['max_users'] === 15, "max_users = {$standardPlan['max_users']}");
    assertTest("Extensible limits_config parsed as array", is_array($freePlan['limits_config']) && isset($freePlan['limits_config']['max_companies']), "Keys: " . implode(', ', array_keys($freePlan['limits_config'])));

    // -------------------------------------------------------------
    // SETUP TEST CUSTOMER (ISOLATED IN A TRANSACTION OR TEST RECORD)
    // -------------------------------------------------------------
    echo "\n--- Setting Up Test Customer ---\n";
    $testCustomerCode = 'TEST_CUST_' . time();
    $stmtNewCust = $pdo->prepare("
        INSERT INTO customers (customer_code, customer_name, address, status, created_at)
        VALUES (:code, 'Subscription Test Customer Inc.', '100 Test Blvd', 'Active', CURRENT_TIMESTAMP)
        RETURNING customer_id
    ");
    $stmtNewCust->execute([':code' => $testCustomerCode]);
    $testCustId = (int)$stmtNewCust->fetchColumn();
    echo " Created temporary test customer with ID: {$testCustId}\n";

    // -------------------------------------------------------------
    // TEST 2: getCustomerActiveSubscription() on New Customer
    // -------------------------------------------------------------
    echo "\n--- 2. Testing getCustomerActiveSubscription() on new customer ---\n";
    $noSub = getCustomerActiveSubscription($testCustId);
    assertTest("Returns null when customer has no subscription", $noSub === null);

    // -------------------------------------------------------------
    // TEST 3: assignSubscription() with FREE Plan
    // -------------------------------------------------------------
    echo "\n--- 3. Testing assignSubscription() ---\n";
    $assignResult = assignSubscription($testCustId, $freePlan['plan_id'], [
        'notes' => 'Test initial assignment of FREE plan',
        'assigned_by' => 1
    ]);
    assertTest("Assign FREE plan to customer", $assignResult['success'] === true, $assignResult['message'] ?? '');

    $activeSub = getCustomerActiveSubscription($testCustId);
    assertTest("Active subscription retrieved", $activeSub !== null && $activeSub['plan_code'] === 'FREE', "Plan: {$activeSub['plan_name']}");
    assertTest("Single active subscription status is 'ACTIVE'", strtoupper($activeSub['status']) === 'ACTIVE');

    // Attempt second assignSubscription without force_replace (should be rejected)
    $dupAssign = assignSubscription($testCustId, $standardPlan['plan_id']);
    assertTest("Duplicate assignSubscription rejected", $dupAssign['success'] === false && $dupAssign['code'] === 'ACTIVE_SUBSCRIPTION_EXISTS', $dupAssign['error'] ?? '');

    // -------------------------------------------------------------
    // TEST 4: checkUserLimit() & calculateAvailableUserSlots() on FREE Plan
    // -------------------------------------------------------------
    echo "\n--- 4. Testing checkUserLimit() on FREE Plan (max_users = 0) ---\n";
    $limitFree = checkUserLimit($testCustId);
    assertTest("FREE plan: new user creation disallowed (max_users = 0)", $limitFree['allowed'] === false, "Allowed: false");
    assertTest("FREE plan: reason explains 0 user threshold", strpos($limitFree['reason'], 'limit of 0 users') !== false, $limitFree['reason']);

    $slotsFree = calculateAvailableUserSlots($testCustId);
    assertTest("Available slots on FREE is 0", $slotsFree['available_users'] === 0, "available: {$slotsFree['available_users']}");

    // -------------------------------------------------------------
    // TEST 5: changeSubscription() - UPGRADE to STANDARD Plan
    // -------------------------------------------------------------
    echo "\n--- 5. Testing changeSubscription() - UPGRADE ---\n";
    $upgradeResult = changeSubscription($testCustId, $standardPlan['plan_id'], [
        'notes' => 'Upgrade from FREE to STANDARD',
        'assigned_by' => 1
    ]);
    assertTest("Upgrade to STANDARD plan succeeds", $upgradeResult['success'] === true && $upgradeResult['is_upgrade'] === true, $upgradeResult['message'] ?? '');

    $newActiveSub = getCustomerActiveSubscription($testCustId);
    assertTest("New active plan is STANDARD", $newActiveSub['plan_code'] === 'STANDARD' && $newActiveSub['max_users'] === 15);

    // Verify previous FREE subscription was marked EXPIRED (History preserved)
    $history = getCustomerSubscriptionsHistory($testCustId);
    assertTest("Customer subscription history preserved (count = 2)", count($history) === 2);
    assertTest("Old subscription marked EXPIRED in history", strtoupper($history[1]['status']) === 'EXPIRED', "Old status: {$history[1]['status']}");
    assertTest("Latest subscription marked ACTIVE in history", strtoupper($history[0]['status']) === 'ACTIVE', "Latest status: {$history[0]['status']}");

    // -------------------------------------------------------------
    // TEST 6: checkUserLimit() on STANDARD Plan (max_users = 15)
    // -------------------------------------------------------------
    echo "\n--- 6. Testing checkUserLimit() on STANDARD Plan ---\n";
    $limitStandard = checkUserLimit($testCustId);
    assertTest("STANDARD plan: new user creation permitted", $limitStandard['allowed'] === true, "Allowed: true");
    assertTest("STANDARD plan: max_users = 15, current_users = 0, available = 15", 
        $limitStandard['max_users'] === 15 && $limitStandard['current_users'] === 0 && $limitStandard['available_users'] === 15,
        "Limit: {$limitStandard['max_users']}, Available: {$limitStandard['available_users']}"
    );

    // -------------------------------------------------------------
    // TEST 7: User Usage Simulation & Downgrade Business Rules
    // -------------------------------------------------------------
    echo "\n--- 7. Testing User Usage & Downgrade Rejection Rule ---\n";
    // Create 3 active users scoped to this test customer
    $createdTestUserIds = [];
    for ($i = 1; $i <= 3; $i++) {
        $uEmail = "sub_test_user_{$i}_" . time() . "@test.example";
        $stmtU = $pdo->prepare("
            INSERT INTO users (category_id, full_name, email, password_hash, is_active, status, created_at)
            VALUES (4, 'Test User {$i}', :email, 'hash', TRUE, 'ACTIVE', CURRENT_TIMESTAMP)
            RETURNING user_id
        ");
        $stmtU->execute([':email' => $uEmail]);
        $uid = (int)$stmtU->fetchColumn();
        $createdTestUserIds[] = $uid;

        // Link in user_organization_access
        $pdo->prepare("
            INSERT INTO user_organization_access (user_id, customer_id, valid_from, status)
            VALUES (?, ?, CURRENT_DATE, 'Active')
        ")->execute([$uid, $testCustId]);
    }

    $usageAfterUsers = getCustomerUserUsage($testCustId);
    assertTest("Customer active user count matches created users (3)", $usageAfterUsers['active_users'] === 3, "active_users: {$usageAfterUsers['active_users']}");

    $slotsAfterUsers = calculateAvailableUserSlots($testCustId);
    assertTest("Available slots calculated correctly (15 - 3 = 12)", $slotsAfterUsers['available_users'] === 12, "available: {$slotsAfterUsers['available_users']}");

    // DOWNGRADE TEST A: Downgrade to FREE (max_users = 0) with 3 active users -> MUST BE REJECTED
    $downgradeToFree = changeSubscription($testCustId, $freePlan['plan_id']);
    assertTest("Downgrade to FREE rejected because customer has active users", 
        $downgradeToFree['success'] === false && $downgradeToFree['code'] === 'DOWNGRADE_REJECTED_USER_LIMIT',
        $downgradeToFree['error'] ?? ''
    );

    // DOWNGRADE TEST B: Create custom limited plan with max_users = 2, attempt downgrade with 3 users -> MUST BE REJECTED
    $pdo->prepare("
        INSERT INTO subscription_plans (plan_code, plan_name, plan_type, price, max_users, status)
        VALUES ('TEST_MINI', 'Mini Plan', 'COMMERCIAL', 999.00, 2, 'ACTIVE')
        ON CONFLICT (plan_code) DO UPDATE SET max_users = 2, status = 'ACTIVE'
    ")->execute();
    $miniPlan = getSubscriptionPlanByCode('TEST_MINI');

    $downgradeToMini = changeSubscription($testCustId, $miniPlan['plan_id']);
    assertTest("Downgrade to plan with limit 2 rejected (customer has 3 active users)", 
        $downgradeToMini['success'] === false && $downgradeToMini['code'] === 'DOWNGRADE_REJECTED_USER_LIMIT',
        $downgradeToMini['error'] ?? ''
    );

    // DOWNGRADE TEST C: Upgrade to ENTERPRISE (limit 200), then Downgrade to STANDARD (limit 15) when user has 3 users -> MUST BE ALLOWED
    $upgradeEnterprise = changeSubscription($testCustId, $enterprisePlan['plan_id']);
    assertTest("Upgrade to ENTERPRISE (limit 200) succeeds", $upgradeEnterprise['success'] === true);

    $downgradeToStandard = changeSubscription($testCustId, $standardPlan['plan_id']);
    assertTest("Downgrade to STANDARD (limit 15) allowed when current users (3) <= new max_users (15)", 
        $downgradeToStandard['success'] === true && $downgradeToStandard['is_upgrade'] === false,
        $downgradeToStandard['message'] ?? ''
    );

    // -------------------------------------------------------------
    // TEST 8: validateSubscriptionStatus() & setSubscriptionStatus()
    // -------------------------------------------------------------
    echo "\n--- 8. Testing Subscription Status Validation & Suspension ---\n";
    $valActive = validateSubscriptionStatus($testCustId);
    assertTest("Active subscription validates successfully", $valActive['is_valid'] === true && $valActive['status'] === 'ACTIVE');

    $currSub = getCustomerActiveSubscription($testCustId);
    $suspendRes = setSubscriptionStatus($currSub['subscription_id'], 'SUSPENDED', ['notes' => 'Billing review']);
    assertTest("Suspend subscription succeeds", ($suspendRes['success'] ?? false) === true && ($suspendRes['status'] ?? '') === 'SUSPENDED', $suspendRes['error'] ?? '');

    $valSuspended = validateSubscriptionStatus($testCustId);
    assertTest("Validation fails when subscription is suspended", $valSuspended['is_valid'] === false && $valSuspended['status'] === 'NO_SUBSCRIPTION');

    // Reactivate subscription
    $reactivateRes = setSubscriptionStatus($currSub['subscription_id'], 'ACTIVE', ['notes' => 'Billing resolved']);
    assertTest("Reactivate subscription succeeds", $reactivateRes['success'] === true && $reactivateRes['status'] === 'ACTIVE');

    $valReactivated = validateSubscriptionStatus($testCustId);
    assertTest("Validation passes after reactivation", $valReactivated['is_valid'] === true && $valReactivated['status'] === 'ACTIVE');

    // -------------------------------------------------------------
    // TEST 9: Security & Customer Scope Access Checks
    // -------------------------------------------------------------
    echo "\n--- 9. Testing Security & Scope Authorization ---\n";
    // Platform Admin (ID 1)
    assertTest("Platform Admin has access to manage customer subscriptions", canUserManageCustomerSubscription($testCustId, 1) === true);
    assertTest("Platform Admin can access customer subscription data", canUserAccessCustomerSubscription($testCustId, 1) === true);

    // Normal customer user
    $testUserId = $createdTestUserIds[0];
    assertTest("Customer user can access their own customer subscription", canUserAccessCustomerSubscription($testCustId, $testUserId) === true);
    assertTest("Customer user cannot access other customer subscription (Customer 999999)", canUserAccessCustomerSubscription(999999, $testUserId) === false);
    assertTest("Customer user cannot manage (assign/change) subscriptions", canUserManageCustomerSubscription($testCustId, $testUserId) === false);

    // -------------------------------------------------------------
    // CLEAN UP TEST ARTIFACTS
    // -------------------------------------------------------------
    echo "\n--- Cleaning Up Temporary Test Records ---\n";
    $pdo->prepare("DELETE FROM customer_subscriptions WHERE customer_id = ?")->execute([$testCustId]);
    $pdo->prepare("DELETE FROM user_organization_access WHERE customer_id = ?")->execute([$testCustId]);
    foreach ($createdTestUserIds as $uid) {
        $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$uid]);
    }
    $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$testCustId]);
    $pdo->prepare("DELETE FROM subscription_plans WHERE plan_code = 'TEST_MINI'")->execute();
    echo " Cleaned up test customer and test users.\n";

    echo "\n=====================================================================\n";
    echo "SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
    if ($failCount === 0) {
        echo "ALL SUBSCRIPTION BACKEND & BUSINESS LOGIC TESTS PASSED SUCCESSFULLY!\n";
    }
    echo "=====================================================================\n";

} catch (Throwable $e) {
    echo "\nFATAL TEST ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
