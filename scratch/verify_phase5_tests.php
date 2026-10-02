<?php
/**
 * Test Suite for Phase 5: Upload Center
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

echo "========================================================\n";
echo "       COST360 PHASE 5 TEST VERIFICATION SUITE         \n";
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
// TEST 1: View-Only vs Upload Permission Enforcement
// ----------------------------------------------------
try {
    // User 5 (Auditor) has no upload permission (can_upload = false)
    $user5CanUpload = isPlatformAdmin(5) || userHasPermission('UPLOAD', 'UPLOAD_CENTER', 5);
    // User 2 (CMA Admin) / User 1 (Platform Admin) has can_upload = true
    $user2CanUpload = isPlatformAdmin(2) || userHasPermission('UPLOAD', 'UPLOAD_CENTER', 2);
    
    $passed = (!$user5CanUpload) && ($user2CanUpload);
    recordTest(1, "RBAC Upload Permission Enforcement", $passed, "User 5 can_upload: " . ($user5CanUpload?'true':'false') . " | User 2 can_upload: " . ($user2CanUpload?'true':'false'));
} catch (Throwable $e) {
    recordTest(1, "RBAC Upload Permission Enforcement", false, $e->getMessage());
}

// ----------------------------------------------------
// TEST 2: Active Scope Display & Extraction
// ----------------------------------------------------
try {
    $_SESSION = [];
    $_SESSION['user_id'] = 1;
    setActiveCompany(1);
    setActiveDivision(1);
    setActivePlant(1);
    setActivePeriod(3);

    $context = getActiveOrganizationContext();
    $passed = !empty($context['company_id']) && !empty($context['plant_id']) && !empty($context['period_id']);
    recordTest(2, "Active Target Scope Resolution", $passed, "Scope: Company {$context['company_id']}, Plant {$context['plant_id']}, Period {$context['period_id']}");
} catch (Throwable $e) {
    recordTest(2, "Active Target Scope Resolution", false, $e->getMessage());
}

// ----------------------------------------------------
// TEST 3: Backend Scope Guarding Against Unselected Context
// ----------------------------------------------------
try {
    $_SESSION = []; // Clear session scope
    $_SESSION['user_id'] = 5;
    
    // Attempt file upload without setting active company/location/period
    $mockFiles = ['name' => ['test_cost.csv'], 'tmp_name' => [''], 'error' => [0], 'size' => [100]];
    $res = analyzeUploadedFiles($mockFiles, getAuthUser() ?: ['user_id' => 5, 'username' => 'test']);
    
    $passed = ($res['ok'] === false) && (strpos($res['message'], 'Validation Error') !== false || strpos($res['message'], 'Active scope') !== false);
    recordTest(3, "Backend Unselected Scope Guarding", $passed, "Response message: " . $res['message']);
} catch (Throwable $e) {
    recordTest(3, "Backend Unselected Scope Guarding", false, $e->getMessage());
}

// ----------------------------------------------------
// TEST 4: Supported File Format & Keyword Classification
// ----------------------------------------------------
try {
    $groups = getMasterGroupsAndSubCategories();
    $passed = is_array($groups) && count($groups) > 0;
    recordTest(4, "Data Categories & Classifications", $passed, "Discovered " . count($groups) . " master data groups.");
} catch (Throwable $e) {
    recordTest(4, "Data Categories & Classifications", false, $e->getMessage());
}

// ----------------------------------------------------
// TEST 5: Upload Scope Database Association
// ----------------------------------------------------
try {
    $pdo = getDbConnection();
    $scopes = $pdo->query("SELECT * FROM upload_scopes ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $passed = is_array($scopes);
    recordTest(5, "upload_scopes DB Schema Integration", $passed, "upload_scopes table accessible. Total past entries: " . count($scopes));
} catch (Throwable $e) {
    recordTest(5, "upload_scopes DB Schema Integration", false, $e->getMessage());
}

echo "\n========================================================\n";
$total = count($results);
$passedCount = count(array_filter($results, function($r) { return $r['passed']; }));
echo "SUMMARY: {$passedCount} / {$total} Tests PASSED.\n";
echo "========================================================\n";
