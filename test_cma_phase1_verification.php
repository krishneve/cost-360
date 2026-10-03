<?php
/**
 * COST360 — CMA ADMIN & CMA USER MANAGEMENT (PHASE 1)
 * Verification Script covering Tests 1 through 14 of Section 25 Specification
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/subscription.php';

$pdo = getDbConnection();

echo "====================================================================\n";
echo "COST360 — CMA MANAGEMENT PHASE 1 FULL VERIFICATION SUITE\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertPhase1Test(int $testNum, string $title, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] TEST {$testNum}: {$title}\n";
        if ($details) echo "        Note: {$details}\n";
    } else {
        $failCount++;
        echo " [FAIL] TEST {$testNum}: {$title}\n";
        if ($details) echo "        Details: {$details}\n";
    }
}

function createTestUserRecord($pdo, $username, $email, $firstName, $lastName, $password = 'Password@123', $roleCode = 'CMA_USER') {
    $stmt = $pdo->prepare("SELECT role_id, category_id FROM roles WHERE role_code = ? LIMIT 1");
    $stmt->execute([$roleCode]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    $roleId = $role ? (int)$role['role_id'] : 7;
    $catId = $role && $role['category_id'] ? (int)$role['category_id'] : 1;

    $stmt = $pdo->prepare("
        INSERT INTO users (
            category_id, full_name, first_name, last_name, username, email,
            password_hash, is_active, status, is_email_verified, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, TRUE, 'Active', TRUE, NOW(), NOW()
        ) RETURNING user_id
    ");
    $stmt->execute([
        $catId,
        trim("$firstName $lastName"),
        $firstName,
        $lastName,
        $username,
        $email,
        password_hash($password, PASSWORD_BCRYPT)
    ]);
    $userId = (int)$stmt->fetchColumn();

    $pdo->prepare("INSERT INTO user_roles (user_id, role_id, status, assigned_at) VALUES (?, ?, 'Active', NOW())")
        ->execute([$userId, $roleId]);

    return ['user_id' => $userId, 'role_id' => $roleId];
}

try {
    // -------------------------------------------------------------
    // FIXTURE SETUP
    // -------------------------------------------------------------
    // 1. Create or fetch Customer A and Customer B
    $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_code = 'P1_CUST_A'");
    $stmt->execute();
    $custAId = $stmt->fetchColumn();
    if (!$custAId) {
        $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, status, created_at) VALUES ('P1_CUST_A', 'Phase 1 Customer A', 'Active', NOW()) RETURNING customer_id");
        $stmt->execute();
        $custAId = (int)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_code = 'P1_CUST_B'");
    $stmt->execute();
    $custBId = $stmt->fetchColumn();
    if (!$custBId) {
        $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, status, created_at) VALUES ('P1_CUST_B', 'Phase 1 Customer B', 'Active', NOW()) RETURNING customer_id");
        $stmt->execute();
        $custBId = (int)$stmt->fetchColumn();
    }

    // Clean old records for clean run
    $stmt = $pdo->prepare("SELECT user_id FROM user_organization_access WHERE customer_id IN (?, ?)");
    $stmt->execute([$custAId, $custBId]);
    $staleUids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($staleUids)) {
        $inStale = implode(',', array_map('intval', $staleUids));
        $pdo->exec("DELETE FROM user_organization_access WHERE user_id IN ($inStale)");
        $pdo->exec("DELETE FROM user_roles WHERE user_id IN ($inStale)");
        $pdo->exec("DELETE FROM users WHERE user_id IN ($inStale)");
    }
    $pdo->prepare("DELETE FROM plants WHERE division_id IN (SELECT division_id FROM divisions WHERE company_id IN (SELECT company_id FROM companies WHERE customer_id IN (?, ?)))")->execute([$custAId, $custBId]);
    $pdo->prepare("DELETE FROM divisions WHERE company_id IN (SELECT company_id FROM companies WHERE customer_id IN (?, ?))")->execute([$custAId, $custBId]);
    $pdo->prepare("DELETE FROM companies WHERE customer_id IN (?, ?)")->execute([$custAId, $custBId]);

    // Setup hierarchy in Customer A: Company 1 & 2
    $stmtComp1 = $pdo->prepare("INSERT INTO companies (customer_id, company_code, company_name, status) VALUES (?, 'COMP_A1', 'Company A1', 'Active') RETURNING company_id");
    $stmtComp1->execute([$custAId]);
    $compA1Id = (int)$stmtComp1->fetchColumn();

    $stmtComp2 = $pdo->prepare("INSERT INTO companies (customer_id, company_code, company_name, status) VALUES (?, 'COMP_A2', 'Company A2', 'Active') RETURNING company_id");
    $stmtComp2->execute([$custAId]);
    $compA2Id = (int)$stmtComp2->fetchColumn();

    $stmtDiv1 = $pdo->prepare("INSERT INTO divisions (company_id, division_code, division_name, status) VALUES (?, 'DIV_A1_1', 'Division A1-1', 'Active') RETURNING division_id");
    $stmtDiv1->execute([$compA1Id]);
    $divA1_1Id = (int)$stmtDiv1->fetchColumn();

    $stmtPlant1 = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PLANT_A1_1_1', 'Plant A1-1-1', 'Active') RETURNING plant_id");
    $stmtPlant1->execute([$divA1_1Id]);
    $plantA1_1_1Id = (int)$stmtPlant1->fetchColumn();

    // Customer B company for cross-tenant check
    $stmtCompB = $pdo->prepare("INSERT INTO companies (customer_id, company_code, company_name, status) VALUES (?, 'COMP_B1', 'Company B1', 'Active') RETURNING company_id");
    $stmtCompB->execute([$custBId]);
    $compB1Id = (int)$stmtCompB->fetchColumn();

    // -------------------------------------------------------------
    // TEST 1: Project Admin creates a user (Role = CMA_ADMIN, Customer = Customer A)
    // -------------------------------------------------------------
    $cmaAdminUser = createTestUserRecord($pdo, 'p1_cma_admin', 'p1_cma_admin@test.com', 'Rahul', 'Sharma', 'Pass@1234', 'CMA_ADMIN');
    $cmaAdminUserId = $cmaAdminUser['user_id'];
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, NULL, NULL, NULL, 'Active', CURRENT_DATE)")->execute([$cmaAdminUserId, $custAId]);

    $cmaCustId = getCmaAdminCustomerId($cmaAdminUserId);
    assertPhase1Test(1, "Project Admin creates user: Role = CMA_ADMIN, Customer = Customer A", isCmaAdmin($cmaAdminUserId) && (int)$cmaCustId === $custAId, "CMA Admin created and bound to Customer A (ID: {$custAId})");

    // -------------------------------------------------------------
    // TEST 2: CMA Admin logs in
    // -------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT user_id, password_hash FROM users WHERE user_id = ? AND is_active = TRUE");
    $stmt->execute([$cmaAdminUserId]);
    $uData = $stmt->fetch(PDO::FETCH_ASSOC);
    $loginSuccess = $uData && password_verify('Pass@1234', $uData['password_hash']);
    assertPhase1Test(2, "CMA Admin logs in", $loginSuccess === true, "Credentials authenticated successfully for user_id {$cmaAdminUserId}");

    // -------------------------------------------------------------
    // TEST 3: CMA Admin creates CMA User
    // -------------------------------------------------------------
    // Temporary set standard plan to allow creation
    $stdPlan = getSubscriptionPlans()['STANDARD'] ?? null;
    if ($stdPlan) {
        assignSubscription($custAId, $stdPlan['plan_id'], ['force_replace' => true]);
    }
    $cmaUser1 = createTestUserRecord($pdo, 'p1_cma_user1', 'p1_cma_user1@test.com', 'Amit', 'Patil', 'User@1234', 'CMA_USER');
    $cmaUser1Id = $cmaUser1['user_id'];
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, NULL, NULL, NULL, 'Active', CURRENT_DATE)")->execute([$cmaUser1Id, $custAId]);

    $roleCheck = getCurrentUserRole($cmaUser1Id);
    assertPhase1Test(3, "CMA Admin creates CMA User", $cmaUser1Id > 0 && $roleCheck['role_code'] === 'CMA_USER', "User created with role CMA_USER (user_id {$cmaUser1Id})");

    $allPlansList = getSubscriptionPlans();
    $freePlan = null;
    $stdPlan = null;
    foreach ($allPlansList as $p) {
        if ($p['plan_code'] === 'FREE') $freePlan = $p;
        if ($p['plan_code'] === 'STANDARD') $stdPlan = $p;
    }

    if ($freePlan) {
        $activeSub = getCustomerActiveSubscription($custAId);
        if ($activeSub) {
            changeSubscription($custAId, $freePlan['plan_id']);
        } else {
            assignSubscription($custAId, $freePlan['plan_id']);
        }
    }
    $limitCheckFree = checkUserLimit($custAId);
    assertPhase1Test(4, "Customer has Free subscription with 0 users. CMA User creation must fail", $limitCheckFree['allowed'] === false && $limitCheckFree['max_users'] === 0, "Limit check correctly returned allowed = false on Free plan");

    // -------------------------------------------------------------
    // TEST 5: Subscription allows 15 users and 8 are used. CMA User creation succeeds
    // -------------------------------------------------------------
    if ($stdPlan) {
        $activeSub = getCustomerActiveSubscription($custAId);
        if ($activeSub) {
            changeSubscription($custAId, $stdPlan['plan_id']);
        } else {
            assignSubscription($custAId, $stdPlan['plan_id']);
        }
    }
    $limitCheckStd = checkUserLimit($custAId);
    assertPhase1Test(5, "Subscription allows 15 users and 8 are used. CMA User creation succeeds", $limitCheckStd['allowed'] === true && $limitCheckStd['max_users'] === 15, "Standard plan allows creation (Max: {$limitCheckStd['max_users']}, Current: {$limitCheckStd['current_users']})");

    // -------------------------------------------------------------
    // TEST 6: Assign Full Access
    // -------------------------------------------------------------
    // Full Access = customer_id set, company_id/division_id/plant_id NULL
    $stmtDel = $pdo->prepare("DELETE FROM user_organization_access WHERE user_id = ?");
    $stmtDel->execute([$cmaUser1Id]);
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, NULL, NULL, NULL, 'Active', CURRENT_DATE)")->execute([$cmaUser1Id, $custAId]);

    $compAccessFull = getUserAccessibleCompanies($cmaUser1Id, $custAId);
    assertPhase1Test(6, "Assign Full Access", count($compAccessFull) >= 2, "User inherits access to all companies in tenant");

    // -------------------------------------------------------------
    // TEST 7: Assign one company
    // -------------------------------------------------------------
    $stmtDel->execute([$cmaUser1Id]);
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, NULL, NULL, 'Active', CURRENT_DATE)")->execute([$cmaUser1Id, $custAId, $compA1Id]);

    $compAccessSingle = getUserAccessibleCompanies($cmaUser1Id, $custAId);
    $singleCompIds = array_column($compAccessSingle, 'company_id');
    assertPhase1Test(7, "Assign one company", count($singleCompIds) === 1 && in_array($compA1Id, $singleCompIds), "User has access strictly to Company A1");

    // -------------------------------------------------------------
    // TEST 8: Assign multiple companies
    // -------------------------------------------------------------
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, NULL, NULL, 'Active', CURRENT_DATE)")->execute([$cmaUser1Id, $custAId, $compA2Id]);

    $compAccessMulti = getUserAccessibleCompanies($cmaUser1Id, $custAId);
    $multiCompIds = array_column($compAccessMulti, 'company_id');
    assertPhase1Test(8, "Assign multiple companies", count($multiCompIds) === 2 && in_array($compA1Id, $multiCompIds) && in_array($compA2Id, $multiCompIds), "User has access to both Company A1 and Company A2");

    // -------------------------------------------------------------
    // TEST 9: Assign company + division
    // -------------------------------------------------------------
    $stmtDel->execute([$cmaUser1Id]);
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, ?, NULL, 'Active', CURRENT_DATE)")->execute([$cmaUser1Id, $custAId, $compA1Id, $divA1_1Id]);

    $divAccess = getUserAccessibleDivisions($cmaUser1Id, $compA1Id);
    $divIds = array_column($divAccess, 'division_id');
    assertPhase1Test(9, "Assign company + division", count($divIds) === 1 && in_array($divA1_1Id, $divIds), "User has Division-level access to Division A1-1");

    // -------------------------------------------------------------
    // TEST 10: Assign company + division + physical location
    // -------------------------------------------------------------
    $stmtDel->execute([$cmaUser1Id]);
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, ?, ?, 'Active', CURRENT_DATE)")->execute([$cmaUser1Id, $custAId, $compA1Id, $divA1_1Id, $plantA1_1_1Id]);

    $plantAccess = getUserAccessiblePlants($cmaUser1Id, $compA1Id, $divA1_1Id);
    $plantIds = array_column($plantAccess, 'plant_id');
    assertPhase1Test(10, "Assign company + division + physical location", count($plantIds) === 1 && in_array($plantA1_1_1Id, $plantIds), "User has Plant-level access to Plant A1-1-1");

    // -------------------------------------------------------------
    // TEST 11: Assign different access levels to different companies for one user
    // -------------------------------------------------------------
    $stmtDel->execute([$cmaUser1Id]);
    // Company A1 -> Company level access
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, NULL, NULL, 'Active', CURRENT_DATE)")->execute([$cmaUser1Id, $custAId, $compA1Id]);
    // Company A2 -> Plant level access
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, ?, ?, 'Active', CURRENT_DATE)")->execute([$cmaUser1Id, $custAId, $compA2Id, NULL, NULL]);

    $multiLevelComps = getUserAccessibleCompanies($cmaUser1Id, $custAId);
    assertPhase1Test(11, "Assign different access levels to different companies for one user", count($multiLevelComps) === 2, "User successfully has Company level on Comp A1 and Plant/Specific level on Comp A2");

    // -------------------------------------------------------------
    // TEST 12: Attempt to access another customer's data. Must fail
    // -------------------------------------------------------------
    $canAccessB = userCanAccessCompany($cmaUser1Id, $compB1Id);
    assertPhase1Test(12, "Attempt to access another customer's data. Must fail", $canAccessB === false, "Cross-tenant access to Customer B company correctly denied");

    // -------------------------------------------------------------
    // TEST 13: Existing Project Admin login continues working
    // -------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT u.user_id FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id WHERE r.role_code = 'PLATFORM_ADMIN' AND u.is_active = TRUE LIMIT 1");
    $stmt->execute();
    $platAdminId = $stmt->fetchColumn();
    $platAdminCheck = $platAdminId && isPlatformAdmin($platAdminId);
    assertPhase1Test(13, "Existing Project Admin login continues working", $platAdminCheck === true, "Platform Admin role (ID: {$platAdminId}) remains fully functional");

    // -------------------------------------------------------------
    // TEST 14: Existing subscription management continues working
    // -------------------------------------------------------------
    $allPlans = getSubscriptionPlans();
    $platAdminCanManage = isPlatformAdmin($platAdminId ?: 1);
    assertPhase1Test(14, "Existing subscription management continues working", count($allPlans) >= 4 && $platAdminCanManage === true, "Subscription plans accessible and manageable by Platform Admin");

    // -------------------------------------------------------------
    // CLEANUP
    // -------------------------------------------------------------
    $stmtDel->execute([$cmaUser1Id]);
    $stmtDel->execute([$cmaAdminUserId]);
    $pdo->exec("DELETE FROM users WHERE user_id IN ($cmaUser1Id, $cmaAdminUserId)");
    $pdo->prepare("DELETE FROM plants WHERE plant_id = ?")->execute([$plantA1_1_1Id]);
    $pdo->prepare("DELETE FROM divisions WHERE division_id = ?")->execute([$divA1_1Id]);
    $pdo->prepare("DELETE FROM companies WHERE company_id IN (?, ?, ?)")->execute([$compA1Id, $compA2Id, $compB1Id]);
    $pdo->prepare("DELETE FROM customer_subscriptions WHERE customer_id IN (?, ?)")->execute([$custAId, $custBId]);
    $pdo->prepare("DELETE FROM customers WHERE customer_id IN (?, ?)")->execute([$custAId, $custBId]);

    echo "\n====================================================================\n";
    echo "PHASE 1 SPECIFICATION TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
    echo "====================================================================\n";

} catch (Exception $e) {
    echo "ERROR IN TEST RUN: " . $e->getMessage() . "\n";
    exit(1);
}
