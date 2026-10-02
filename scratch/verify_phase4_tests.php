<?php
/**
 * Test Suite for Phase 4: User Dashboard & Dynamic Menu
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

echo "========================================================\n";
echo "       COST360 PHASE 4 TEST VERIFICATION SUITE         \n";
echo "========================================================\n\n";

$results = [];

function recordTest($id, $name, $passed, $details) {
    global $results;
    $results[] = [
        'id' => $id,
        'name' => $name,
        'passed' => $passed,
        'details' => $details
    ];
    $status = $passed ? "[PASS]" : "[FAIL]";
    echo sprintf("%-6s | Test %02d: %-35s | %s\n", $status, $id, $name, $details);
}

// ----------------------------------------------------
// TEST 1: Dynamic Authorized Menu Resolution for User
// ----------------------------------------------------
try {
    // Test with User 5 (Sneha Auditor)
    $user5Menus = getUserAuthorizedMenus(5);
    $passed = is_array($user5Menus) && count($user5Menus) > 0;
    recordTest(1, "Dynamic Authorized Menu Resolution", $passed, "Found " . count($user5Menus) . " authorized menu items for User 5.");
} catch (Throwable $e) {
    recordTest(1, "Dynamic Authorized Menu Resolution", false, $e->getMessage());
}

// ----------------------------------------------------
// TEST 2: Upload Center Menu Access Verification
// ----------------------------------------------------
try {
    // User 2 (CMA Admin) has Upload Center menu access
    $user2Menus = getUserAuthorizedMenus(2);
    $hasUploadCenter = false;
    foreach ($user2Menus as $m) {
        if (strpos(strtolower($m['menu_name']), 'upload') !== false || strpos(strtolower($m['menu_code']), 'UPLOAD') !== false) {
            $hasUploadCenter = true;
            break;
        }
    }
    recordTest(2, "Upload Center Menu Included", $hasUploadCenter, "Upload Center menu resolved in authorized navigation list.");
} catch (Throwable $e) {
    recordTest(2, "Upload Center Menu Included", false, $e->getMessage());
}

// ----------------------------------------------------
// TEST 3: Active Organizational Scope Maintenance
// ----------------------------------------------------
try {
    $_SESSION = [];
    $_SESSION['user_id'] = 5;
    setActiveCompany(1);
    setActiveDivision(1);
    setActivePlant(1);
    setActivePeriod(3);

    $context = getActiveOrganizationContext();
    $passed = ($context['company_id'] == 1 && $context['division_id'] == 1 && $context['plant_id'] == 1 && $context['period_id'] == 3);
    recordTest(3, "Active Scope Consistency Across Modules", $passed, "Company: {$context['company_id']}, Div: {$context['division_id']}, Plant: {$context['plant_id']}, Period: {$context['period_id']}");
} catch (Throwable $e) {
    recordTest(3, "Active Scope Consistency Across Modules", false, $e->getMessage());
}

// ----------------------------------------------------
// TEST 4: Scope Switcher Functionality
// ----------------------------------------------------
try {
    // Switch active location from Plant 1 to Warehouse 2
    $switchOk = setActiveWarehouse(2);
    $context = getActiveOrganizationContext();
    $passed = $switchOk && ($context['warehouse_id'] == 2);
    recordTest(4, "Interactive Scope Switching", $passed, "Successfully switched active operational location to Warehouse 2.");
} catch (Throwable $e) {
    recordTest(4, "Interactive Scope Switching", false, $e->getMessage());
}

// ----------------------------------------------------
// TEST 5: Customer DB Sourcing Integration
// ----------------------------------------------------
try {
    $_SESSION['user_id'] = 5;
    $_SESSION['active_customer_id'] = 1;
    $mapping = getCustomerDatabaseMapping(1);
    $passed = !empty($mapping['database_name']);
    recordTest(5, "Customer DB Data Sourcing Link", $passed, "Resolved customer database handle: " . $mapping['database_name']);
} catch (Throwable $e) {
    recordTest(5, "Customer DB Data Sourcing Link", false, $e->getMessage());
}

echo "\n========================================================\n";
$total = count($results);
$passedCount = count(array_filter($results, function($r) { return $r['passed']; }));
echo "SUMMARY: {$passedCount} / {$total} Tests PASSED.\n";
echo "========================================================\n";
