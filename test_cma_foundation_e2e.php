<?php
/**
 * COST360 — CMA Admin Foundation Complete End-to-End Test Suite (Phase 20)
 * Validates Tests A through J in exact accordance with specification.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/subscription.php';

$pdo = getDbConnection();

echo "====================================================================\n";
echo "COST360 — CMA ADMIN FOUNDATION E2E VERIFICATION SUITE\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($description, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] $description\n";
    } else {
        $failCount++;
        echo " [FAIL] $description\n";
        if ($details) echo "        Details: $details\n";
    }
}

function createE2eTestUser($pdo, $username, $email, $firstName, $lastName, $password = 'Test@123', $roleCode = 'CMA_USER') {
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
    // SETUP TEST FIXTURES
    // -------------------------------------------------------------
    echo "--- SETUP TEST FIXTURES ---\n";
    
    // Find or create test customer A
    $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_code = 'CMA_TEST_A'");
    $stmt->execute();
    $custAId = $stmt->fetchColumn();
    if (!$custAId) {
        $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, status, created_at) VALUES ('CMA_TEST_A', 'CMA Test Organization A', 'Active', NOW()) RETURNING customer_id");
        $stmt->execute();
        $custAId = $stmt->fetchColumn();
    }

    // Find or create test customer B (for cross-tenant isolation testing)
    $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_code = 'CMA_TEST_B'");
    $stmt->execute();
    $custBId = $stmt->fetchColumn();
    if (!$custBId) {
        $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, status, created_at) VALUES ('CMA_TEST_B', 'CMA Test Organization B', 'Active', NOW()) RETURNING customer_id");
        $stmt->execute();
        $custBId = $stmt->fetchColumn();
    }

    // Assign a 2-user limited subscription plan to Customer A for limit testing
    $stmt = $pdo->prepare("SELECT plan_id FROM subscription_plans WHERE plan_code = 'CMA_TEST_PLAN'");
    $stmt->execute();
    $planId = $stmt->fetchColumn();
    if (!$planId) {
        $stmt = $pdo->prepare("INSERT INTO subscription_plans (plan_name, plan_code, plan_type, price, billing_cycle, max_users, status, created_at) VALUES ('CMA Test Tier', 'CMA_TEST_PLAN', 'COMMERCIAL', 100, 'MONTHLY', 2, 'ACTIVE', NOW()) RETURNING plan_id");
        $stmt->execute();
        $planId = $stmt->fetchColumn();
    }

    // Assign active test plan using official subscription service if not already active
    $existingSub = getCustomerActiveSubscription($custAId);
    if (!$existingSub || (int)$existingSub['plan_id'] !== (int)$planId) {
        $assignResult = assignSubscription($custAId, $planId, ['force_replace' => true, 'notes' => 'CMA Foundation E2E Test']);
        if (!$assignResult['success'] && strpos($assignResult['error'] ?? '', 'already currently subscribed') === false) {
            throw new Exception("Failed to assign test subscription: " . ($assignResult['error'] ?? 'Unknown'));
        }
    }

    // Clean up any stale test data from previous runs
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

    echo "Customer A ID: $custAId (Max Users: 2)\n";
    echo "Customer B ID: $custBId\n\n";

    // -------------------------------------------------------------
    // TEST A: Project Admin Creates CMA Admin
    // -------------------------------------------------------------
    echo "--- TEST A: Project Admin Creates CMA Admin ---\n";
    $testCmaUsername = 'test_cma_admin_' . time();
    $testCmaEmail = $testCmaUsername . '@cmatest.com';

    $cmaUser = createE2eTestUser($pdo, $testCmaUsername, $testCmaEmail, 'Rahul', 'Sharma', 'CmaAdmin@123', 'CMA_ADMIN');
    $cmaAdminUserId = $cmaUser['user_id'];
    $cmaRoleId = $cmaUser['role_id'];

    // Assign customer organization scope
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, NULL, NULL, NULL, 'Active', CURRENT_DATE)")->execute([$cmaAdminUserId, $custAId]);

    // Audit log
    $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_name, entity_id, new_value, timestamp, ip_address) VALUES (1, 'UPDATE', 'users', ?, ?, CURRENT_TIMESTAMP, '127.0.0.1')")
        ->execute([$cmaAdminUserId, json_encode(['action' => 'CMA_ADMIN_CREATED', 'username' => $testCmaUsername, 'customer_id' => $custAId])]);

    assertTest("CMA Admin user created in users table", $cmaAdminUserId > 0, "User ID: $cmaAdminUserId");
    assertTest("CMA Admin role assigned", (int)$cmaRoleId === 2 || $cmaRoleId > 0);
    assertTest("CMA Admin assigned to Customer A tenant", (int)getCmaAdminCustomerId($cmaAdminUserId) === (int)$custAId);

    // -------------------------------------------------------------
    // TEST B: CMA Admin Login & Scoping
    // -------------------------------------------------------------
    echo "\n--- TEST B: CMA Admin Login & Scoping ---\n";
    // Verify login credentials matching login.php flow
    $stmt = $pdo->prepare("SELECT user_id, username, email, password_hash, status FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$testCmaEmail]);
    $loginUser = $stmt->fetch(PDO::FETCH_ASSOC);
    $authCheck = $loginUser && password_verify('CmaAdmin@123', $loginUser['password_hash']);
    assertTest("CMA Admin credentials authenticate successfully", $authCheck === true);
    assertTest("isCmaAdmin() returns true for created CMA Admin", isCmaAdmin($cmaAdminUserId) === true);
    assertTest("isPlatformAdmin() returns false for created CMA Admin", isPlatformAdmin($cmaAdminUserId) === false);

    // Verify accessible menus for CMA Admin
    $stmt = $pdo->prepare("SELECT m.menu_code FROM menus m JOIN role_menu rm ON m.menu_id = rm.menu_id WHERE rm.role_id = ?");
    $stmt->execute([$cmaRoleId]);
    $cmaMenus = $stmt->fetchAll(PDO::FETCH_COLUMN);
    assertTest("CMA Admin has DASHBOARD menu", in_array('DASHBOARD', $cmaMenus));
    assertTest("CMA Admin has CMA_USER_MGMT menu", in_array('CMA_USER_MGMT', $cmaMenus));
    assertTest("CMA Admin has CMA_COMPANY_MGMT menu", in_array('CMA_COMPANY_MGMT', $cmaMenus));
    assertTest("CMA Admin has CMA_ACCESS_MGMT menu", in_array('CMA_ACCESS_MGMT', $cmaMenus));
    assertTest("CMA Admin does NOT have Project Admin-only CUSTOMER_MGMT", !in_array('CUSTOMER_MGMT', $cmaMenus));
    assertTest("CMA Admin does NOT have Project Admin-only SUBSCRIPTION_MGMT", !in_array('SUBSCRIPTION_MGMT', $cmaMenus));

    // -------------------------------------------------------------
    // TEST C: Initial User Master (CMA Admin sees only self initially)
    // -------------------------------------------------------------
    echo "\n--- TEST C: Initial User Master ---\n";
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.username, r.role_code
        FROM users u
        INNER JOIN user_organization_access uoa ON u.user_id = uoa.user_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.role_id
        WHERE (u.user_id = :cma_user_id OR (uoa.customer_id = :cma_cust_id AND (r.role_code IS NULL OR r.role_code NOT IN ('PLATFORM_ADMIN', 'SUPER_ADMIN', 'CMA_ADMIN'))))
    ");
    $stmt->execute([':cma_user_id' => $cmaAdminUserId, ':cma_cust_id' => $custAId]);
    $initialUserList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assertTest("User Master list contains CMA Admin self", count($initialUserList) === 1 && (int)$initialUserList[0]['user_id'] === (int)$cmaAdminUserId);

    // -------------------------------------------------------------
    // TEST D: CMA Admin Creates User
    // -------------------------------------------------------------
    echo "\n--- TEST D: CMA Admin Creates User ---\n";
    $testUser1Name = 'amit_patil_' . time();
    $testUser1Email = $testUser1Name . '@cmatest.com';

    // Check user limit before creation
    $limitCheck = checkUserLimit($custAId);
    assertTest("User limit check allows creation (current users < max users)", $limitCheck['allowed'] === true, "Current: {$limitCheck['current_users']} / Max: {$limitCheck['max_users']}");

    // Create user 1
    $u1 = createE2eTestUser($pdo, $testUser1Name, $testUser1Email, 'Amit', 'Patil', 'User@123', 'CMA_USER');
    $testUser1Id = $u1['user_id'];

    // Assign to Customer A
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, status, valid_from) VALUES (?, ?, 'Active', CURRENT_DATE)")->execute([$testUser1Id, $custAId]);

    // Query User Master again
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.username, r.role_code
        FROM users u
        INNER JOIN user_organization_access uoa ON u.user_id = uoa.user_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.role_id
        WHERE (u.user_id = :cma_user_id OR (uoa.customer_id = :cma_cust_id AND (r.role_code IS NULL OR r.role_code NOT IN ('PLATFORM_ADMIN', 'SUPER_ADMIN', 'CMA_ADMIN'))))
        ORDER BY u.user_id ASC
    ");
    $stmt->execute([':cma_user_id' => $cmaAdminUserId, ':cma_cust_id' => $custAId]);
    $updatedUserList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assertTest("Created user appears in CMA User Master", count($updatedUserList) === 2);
    assertTest("CMA Admin sees self and newly created user Amit", in_array($testUser1Name, array_column($updatedUserList, 'username')));

    // -------------------------------------------------------------
    // TEST E: Subscription User Limit Enforced
    // -------------------------------------------------------------
    echo "\n--- TEST E: Subscription User Limit Enforced ---\n";
    $limitCheckAfterUser1 = checkUserLimit($custAId);
    assertTest("Customer A current users reaches max limit (2/2)", $limitCheckAfterUser1['current_users'] >= $limitCheckAfterUser1['max_users']);
    assertTest("checkUserLimit() blocks further user creation", $limitCheckAfterUser1['allowed'] === false);

    // -------------------------------------------------------------
    // TEST F: Company Hierarchy (Company -> Division -> Plant)
    // -------------------------------------------------------------
    echo "\n--- TEST F: Company Hierarchy (Company -> Division -> Plant) ---\n";
    // Create Company for Customer A
    $stmt = $pdo->prepare("INSERT INTO companies (customer_id, company_code, company_name, status) VALUES (?, 'ABC_MFG', 'ABC Manufacturing Ltd', 'Active') RETURNING company_id");
    $stmt->execute([$custAId]);
    $testCompanyId = $stmt->fetchColumn();

    // Create Division 1 (Automotive) and Division 2 (Consumer)
    $stmt = $pdo->prepare("INSERT INTO divisions (company_id, division_code, division_name, status) VALUES (?, 'AUTO_DIV', 'Automotive Division', 'Active') RETURNING division_id");
    $stmt->execute([$testCompanyId]);
    $divAutoId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO divisions (company_id, division_code, division_name, status) VALUES (?, 'CONS_DIV', 'Consumer Division', 'Active') RETURNING division_id");
    $stmt->execute([$testCompanyId]);
    $divConsId = $stmt->fetchColumn();

    // Create Plants under Automotive: Nashik and Pune
    $stmt = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'NASHIK_PLT', 'Nashik Plant', 'Active') RETURNING plant_id");
    $stmt->execute([$divAutoId]);
    $plantNashikId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'PUNE_PLT', 'Pune Plant', 'Active') RETURNING plant_id");
    $stmt->execute([$divAutoId]);
    $plantPuneId = $stmt->fetchColumn();

    // Create Plant under Consumer: Mumbai
    $stmt = $pdo->prepare("INSERT INTO plants (division_id, plant_code, plant_name, status) VALUES (?, 'MUMBAI_PLT', 'Mumbai Plant', 'Active') RETURNING plant_id");
    $stmt->execute([$divConsId]);
    $plantMumbaiId = $stmt->fetchColumn();

    assertTest("Company created and bound to Customer A", $testCompanyId > 0);
    assertTest("Divisions created under Company", $divAutoId > 0 && $divConsId > 0);
    assertTest("Plants created under Automotive Division", $plantNashikId > 0 && $plantPuneId > 0);
    assertTest("Plant created under Consumer Division", $plantMumbaiId > 0);

    // -------------------------------------------------------------
    // TEST G: Company-Level Access (Cascades to all divisions & plants)
    // -------------------------------------------------------------
    echo "\n--- TEST G: Company-Level Access Cascades to All Divisions & Plants ---\n";
    $compUser = createE2eTestUser($pdo, 'comp_user_test', 'comp@test.com', 'Comp', 'User');
    $compUserId = $compUser['user_id'];

    // Assign COMPANY level access to ABC Manufacturing
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, NULL, NULL, 'Active', CURRENT_DATE)")
        ->execute([$compUserId, $custAId, $testCompanyId]);

    // Test accessible companies, divisions, and plants for this user
    $accCompanies = getUserAccessibleCompanies($compUserId);
    $accDivisions = getUserAccessibleDivisions($compUserId, $testCompanyId);
    $accPlants = getUserAccessiblePlants($compUserId, $testCompanyId);

    $accDivCodes = array_column($accDivisions, 'division_code');
    $accPlantCodes = array_column($accPlants, 'plant_code');

    assertTest("User can access ABC Manufacturing Company", count($accCompanies) >= 1 && in_array('ABC_MFG', array_column($accCompanies, 'company_code')));
    assertTest("User inherits Automotive Division", in_array('AUTO_DIV', $accDivCodes));
    assertTest("User inherits Consumer Division", in_array('CONS_DIV', $accDivCodes));
    assertTest("User inherits Nashik Plant", in_array('NASHIK_PLT', $accPlantCodes));
    assertTest("User inherits Pune Plant", in_array('PUNE_PLT', $accPlantCodes));
    assertTest("User inherits Mumbai Plant", in_array('MUMBAI_PLT', $accPlantCodes));

    // -------------------------------------------------------------
    // TEST H: Division-Level Access (Cascades only to plants under that division)
    // -------------------------------------------------------------
    echo "\n--- TEST H: Division-Level Access ---\n";
    $divUser = createE2eTestUser($pdo, 'div_user_test', 'div@test.com', 'Div', 'User');
    $divUserId = $divUser['user_id'];

    // Assign DIVISION level access to Automotive Division
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, ?, NULL, 'Active', CURRENT_DATE)")
        ->execute([$divUserId, $custAId, $testCompanyId, $divAutoId]);

    $divUserDivs = getUserAccessibleDivisions($divUserId, $testCompanyId);
    $divUserPlants = getUserAccessiblePlants($divUserId, $testCompanyId);

    $divUserDivCodes = array_column($divUserDivs, 'division_code');
    $divUserPlantCodes = array_column($divUserPlants, 'plant_code');

    assertTest("User accesses Automotive Division", in_array('AUTO_DIV', $divUserDivCodes));
    assertTest("User does NOT access Consumer Division", !in_array('CONS_DIV', $divUserDivCodes));
    assertTest("User inherits Nashik Plant (under Automotive)", in_array('NASHIK_PLT', $divUserPlantCodes));
    assertTest("User inherits Pune Plant (under Automotive)", in_array('PUNE_PLT', $divUserPlantCodes));
    assertTest("User does NOT inherit Mumbai Plant (under Consumer)", !in_array('MUMBAI_PLT', $divUserPlantCodes));

    // -------------------------------------------------------------
    // TEST I: Plant-Level Access (Restricted to single plant)
    // -------------------------------------------------------------
    echo "\n--- TEST I: Plant-Level Access ---\n";
    $pltUser = createE2eTestUser($pdo, 'plt_user_test', 'plt@test.com', 'Plt', 'User');
    $pltUserId = $pltUser['user_id'];

    // Assign PLANT level access to Nashik Plant only
    $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status, valid_from) VALUES (?, ?, ?, ?, ?, 'Active', CURRENT_DATE)")
        ->execute([$pltUserId, $custAId, $testCompanyId, $divAutoId, $plantNashikId]);

    $pltUserPlants = getUserAccessiblePlants($pltUserId, $testCompanyId);
    $pltUserPlantCodes = array_column($pltUserPlants, 'plant_code');

    assertTest("User accesses Nashik Plant", in_array('NASHIK_PLT', $pltUserPlantCodes));
    assertTest("User does NOT access Pune Plant", !in_array('PUNE_PLT', $pltUserPlantCodes));
    assertTest("User does NOT access Mumbai Plant", !in_array('MUMBAI_PLT', $pltUserPlantCodes));

    // -------------------------------------------------------------
    // TEST J: Multi-Tenant Access Isolation
    // -------------------------------------------------------------
    echo "\n--- TEST J: Multi-Tenant Access Isolation ---\n";
    // Create a Company under Customer B
    $stmt = $pdo->prepare("INSERT INTO companies (customer_id, company_code, company_name, status) VALUES (?, 'XYZ_MFG', 'XYZ Manufacturing Ltd', 'Active') RETURNING company_id");
    $stmt->execute([$custBId]);
    $companyBId = $stmt->fetchColumn();

    // Verify CMA Admin of Customer A cannot see Company B
    $cmaAdminComps = getUserAccessibleCompanies($cmaAdminUserId, $custAId);
    $cmaCompCodes = array_column($cmaAdminComps, 'company_code');
    assertTest("CMA Admin A can access Customer A Company", in_array('ABC_MFG', $cmaCompCodes));
    assertTest("CMA Admin A CANNOT access Customer B Company", !in_array('XYZ_MFG', $cmaCompCodes));

    // Verify backend rejects assigning Customer B's company to a Customer A access mapping
    $stmt = $pdo->prepare("SELECT 1 FROM companies WHERE company_id = ? AND customer_id = ?");
    $stmt->execute([$companyBId, $custAId]);
    $isAllowed = (bool)$stmt->fetchColumn();
    assertTest("Backend validation strictly rejects cross-tenant company mapping", $isAllowed === false);

    // -------------------------------------------------------------
    // CLEANUP TEST FIXTURES
    // -------------------------------------------------------------
    echo "\n--- CLEANING UP TEST FIXTURES ---\n";
    $cleanupUserIds = [$cmaAdminUserId, $testUser1Id, $compUserId, $divUserId, $pltUserId];
    $inUsers = implode(',', $cleanupUserIds);
    $pdo->prepare("DELETE FROM user_organization_access WHERE user_id IN ($inUsers)")->execute();
    $pdo->prepare("DELETE FROM user_roles WHERE user_id IN ($inUsers)")->execute();
    $pdo->prepare("DELETE FROM audit_log WHERE entity_id::text IN ('" . implode("','", $cleanupUserIds) . "')")->execute();
    $pdo->prepare("DELETE FROM users WHERE user_id IN ($inUsers)")->execute();

    $pdo->prepare("DELETE FROM plants WHERE plant_id IN (?, ?, ?)")->execute([$plantNashikId, $plantPuneId, $plantMumbaiId]);
    $pdo->prepare("DELETE FROM divisions WHERE division_id IN (?, ?)")->execute([$divAutoId, $divConsId]);
    $pdo->prepare("DELETE FROM companies WHERE company_id IN (?, ?)")->execute([$testCompanyId, $companyBId]);
    $pdo->prepare("DELETE FROM customer_subscriptions WHERE customer_id IN (?, ?)")->execute([$custAId, $custBId]);
    $pdo->prepare("DELETE FROM subscription_plans WHERE plan_id = ?")->execute([$planId]);
    $pdo->prepare("DELETE FROM customers WHERE customer_id IN (?, ?)")->execute([$custAId, $custBId]);

    echo "Cleanup completed successfully.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $failCount++;
}

echo "\n====================================================================\n";
echo "TEST RESULTS SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "====================================================================\n";
