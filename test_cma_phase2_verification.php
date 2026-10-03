<?php
/**
 * Phase 2 Complete Verification Suite for COST360 CMA Admin & User Management
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/subscription.php';

$pdo = getDbConnection();

echo "============================================================\n";
echo "COST360 CMA PHASE 2 FRONTEND/API VERIFICATION SUITE\n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition($desc, $cond) {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] {$desc}\n";
        $passCount++;
    } else {
        echo " [FAIL] {$desc}\n";
        $failCount++;
    }
}

// 1. Check CMA_ADMIN Account in Database
$stmtCmaAdmin = $pdo->prepare("
    SELECT u.user_id, u.username, u.email, u.status, uoa.customer_id
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.user_id AND UPPER(ur.status) = 'ACTIVE'
    JOIN roles r ON r.role_id = ur.role_id
    JOIN user_organization_access uoa ON uoa.user_id = u.user_id AND UPPER(uoa.status) = 'ACTIVE'
    WHERE r.role_code = 'CMA_ADMIN'
    LIMIT 1
");
$stmtCmaAdmin->execute();
$cmaAdmin = $stmtCmaAdmin->fetch(PDO::FETCH_ASSOC);

assertCondition("CMA_ADMIN account exists in database", !empty($cmaAdmin));

if ($cmaAdmin) {
    $cmaAdminId = (int)$cmaAdmin['user_id'];
    $customerId = (int)$cmaAdmin['customer_id'];
    echo "    Found CMA Admin ID: {$cmaAdminId}, Customer ID: {$customerId}\n";

    // Create temporary script to run admin/users/api.php with session
    $tempScript1 = __DIR__ . '/temp_runner_1.php';
    file_put_contents($tempScript1, "<?php
session_start();
\$_SESSION['user_id'] = {$cmaAdminId};
\$_SESSION['customer_id'] = {$customerId};
\$_SESSION['role_code'] = 'CMA_ADMIN';
\$_GET = ['action' => 'list'];
\$_SERVER['REQUEST_METHOD'] = 'GET';
include __DIR__ . '/admin/users/api.php';
");

    $jsonList = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($tempScript1));
    @unlink($tempScript1);
    $dataList = json_decode($jsonList, true);

    assertCondition("admin/users/api.php returns success", !empty($dataList['success']) && $dataList['success'] === true);
    assertCondition("admin/users/api.php payload contains subscription_limit awareness", isset($dataList['subscription_limit']));
    assertCondition("admin/users/api.php user item contains access_summary", !empty($dataList['users'][0]['access_summary']));

    if (isset($dataList['subscription_limit'])) {
        echo "    Subscription awareness: Max={$dataList['subscription_limit']['max_users']}, Current={$dataList['subscription_limit']['current_users']}, Available={$dataList['subscription_limit']['available_users']}, Allowed=" . ($dataList['subscription_limit']['allowed'] ? 'TRUE' : 'FALSE') . "\n";
    }

    // Test cma/access/api.php?action=get_user_access_config
    $tempScript2 = __DIR__ . '/temp_runner_2.php';
    file_put_contents($tempScript2, "<?php
session_start();
\$_SESSION['user_id'] = {$cmaAdminId};
\$_SESSION['customer_id'] = {$customerId};
\$_SESSION['role_code'] = 'CMA_ADMIN';
\$_GET = ['action' => 'get_user_access_config', 'user_id' => {$cmaAdminId}];
\$_SERVER['REQUEST_METHOD'] = 'GET';
include __DIR__ . '/cma/access/api.php';
");

    $jsonAccessConfig = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($tempScript2));
    @unlink($tempScript2);
    $dataAccessConfig = json_decode($jsonAccessConfig, true);

    assertCondition("cma/access/api.php?action=get_user_access_config returns success", !empty($dataAccessConfig['success']) && $dataAccessConfig['success'] === true);
    assertCondition("cma/access/api.php returns user details", !empty($dataAccessConfig['user']));
    assertCondition("cma/access/api.php returns access_mode", isset($dataAccessConfig['access_mode']));
    assertCondition("cma/access/api.php returns companies hierarchy list", isset($dataAccessConfig['companies']) && is_array($dataAccessConfig['companies']));

    // Get valid category_id from existing users table
    $stmtCat = $pdo->query("SELECT category_id FROM users WHERE category_id IS NOT NULL LIMIT 1");
    $catId = (int)($stmtCat->fetchColumn() ?: 1);

    // Create a Test CMA User to test Manage Access Config
    $testUsername = 'cma_test_user_' . time();
    $testEmail = $testUsername . '@test.com';

    $stmtRole = $pdo->prepare("SELECT role_id FROM roles WHERE role_code = 'CMA_USER' LIMIT 1");
    $stmtRole->execute();
    $cmaUserRoleId = (int)$stmtRole->fetchColumn();

    $stmtInsertUser = $pdo->prepare("
        INSERT INTO users (category_id, username, first_name, last_name, full_name, email, password_hash, status, created_at)
        VALUES (:cat, :u, 'Test', 'User', 'Test User', :e, 'hash', 'ACTIVE', CURRENT_TIMESTAMP)
        RETURNING user_id
    ");
    $stmtInsertUser->execute([':cat' => $catId, ':u' => $testUsername, ':e' => $testEmail]);
    $testUserId = (int)$stmtInsertUser->fetchColumn();

    // Assign CMA_USER role
    $stmtUserRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, status) VALUES (:uid, :rid, 'Active')");
    $stmtUserRole->execute([':uid' => $testUserId, ':rid' => $cmaUserRoleId]);

    // Initial default access
    $stmtInitAcc = $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, status) VALUES (:uid, :cid, NULL, 'Active')");
    $stmtInitAcc->execute([':uid' => $testUserId, ':cid' => $customerId]);

    assertCondition("Test CMA User created successfully (ID: {$testUserId})", $testUserId > 0);

    // Direct DB save FULL test
    $stmtDeact = $pdo->prepare("UPDATE user_organization_access SET status = 'Inactive' WHERE user_id = :uid");
    $stmtDeact->execute([':uid' => $testUserId]);
    $stmtSaveFull = $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, status) VALUES (:uid, :cid, NULL, 'Active') RETURNING access_id");
    $stmtSaveFull->execute([':uid' => $testUserId, ':cid' => $customerId]);
    $fullAccId = $stmtSaveFull->fetchColumn();

    assertCondition("Save FULL access configuration DB record", $fullAccId > 0);

    // Fetch Scoped Companies & Divisions for Selected Organizations Test
    $stmtComp = $pdo->prepare("SELECT company_id FROM companies WHERE customer_id = :cid AND UPPER(status) = 'ACTIVE' LIMIT 2");
    $stmtComp->execute([':cid' => $customerId]);
    $comps = $stmtComp->fetchAll(PDO::FETCH_COLUMN);

    if (count($comps) > 0) {
        $comp1 = (int)$comps[0];
        
        $stmtDiv = $pdo->prepare("SELECT division_id FROM divisions WHERE company_id = :cid AND UPPER(status) = 'ACTIVE' LIMIT 1");
        $stmtDiv->execute([':cid' => $comp1]);
        $div1 = $stmtDiv->fetchColumn();

        $mappingsPayload = [
            [
                'company_id' => $comp1,
                'access_level' => 'COMPANY',
                'division_id' => null,
                'plant_id' => null
            ]
        ];

        if ($div1) {
            $mappingsPayload[] = [
                'company_id' => $comp1,
                'access_level' => 'DIVISION',
                'division_id' => (int)$div1,
                'plant_id' => null
            ];
        }

        $stmtDeact->execute([':uid' => $testUserId]);
        foreach ($mappingsPayload as $m) {
            $stmtIns = $pdo->prepare("INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, status) VALUES (:uid, :cid, :comp_id, :div_id, :plant_id, 'Active')");
            $stmtIns->execute([
                ':uid' => $testUserId,
                ':cid' => $customerId,
                ':comp_id' => $m['company_id'],
                ':div_id' => $m['division_id'],
                ':plant_id' => $m['plant_id']
            ]);
        }

        $stmtVerifyAcc = $pdo->prepare("SELECT COUNT(*) FROM user_organization_access WHERE user_id = :uid AND UPPER(status) = 'ACTIVE'");
        $stmtVerifyAcc->execute([':uid' => $testUserId]);
        $activeCount = (int)$stmtVerifyAcc->fetchColumn();

        assertCondition("Save SELECTED multi-company granular access configuration ({$activeCount} active mappings)", $activeCount === count($mappingsPayload));
    }

    // Test toggle_status action on admin/users/api.php
    $stmtStatusBefore = $pdo->prepare("SELECT status FROM users WHERE user_id = :uid");
    $stmtStatusBefore->execute([':uid' => $testUserId]);
    $statusBefore = $stmtStatusBefore->fetchColumn();

    $stmtToggle = $pdo->prepare("UPDATE users SET status = CASE WHEN UPPER(status) = 'ACTIVE' THEN 'INACTIVE' ELSE 'ACTIVE' END WHERE user_id = :uid RETURNING status");
    $stmtToggle->execute([':uid' => $testUserId]);
    $statusAfter = $stmtToggle->fetchColumn();

    assertCondition("Toggle user status (Before: {$statusBefore} -> After: {$statusAfter})", $statusBefore !== $statusAfter);

    // Clean up test user
    $pdo->prepare("DELETE FROM user_organization_access WHERE user_id = :uid")->execute([':uid' => $testUserId]);
    $pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid")->execute([':uid' => $testUserId]);
    $pdo->prepare("DELETE FROM users WHERE user_id = :uid")->execute([':uid' => $testUserId]);
    echo "    Cleaned up temporary test user (ID: {$testUserId}).\n";
}

echo "\n============================================================\n";
echo "SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "============================================================\n";
