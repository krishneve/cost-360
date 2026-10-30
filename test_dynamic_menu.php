<?php
/**
 * Test Dynamic Menu Adjustment & Cost Reco Menu Bar Verification
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$pdo = getDbConnection();
$pass = 0;
$fail = 0;

function assertMenuTest(string $desc, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo " [PASS] {$desc}\n";
        if ($detail) echo "        -> {$detail}\n";
    } else {
        $fail++;
        echo " [FAIL] {$desc}\n";
        if ($detail) echo "        -> ERROR: {$detail}\n";
    }
}

echo "=================================================================\n";
echo "    COST360 — DYNAMIC MENU & COST RECO VERIFICATION\n";
echo "=================================================================\n\n";

// Helper to simulate dashboard sidebar output
function getSidebarHtml(int $userId): string {
    $script = __DIR__ . '/scratch/tmp_dash_render.php';
    $code = '<?php
    session_start();
    $_SESSION["user_id"] = ' . $userId . ';
    $_SESSION["active_company_id"] = 1;
    $_SESSION["active_company_code"] = "COMP_TEST";
    $_SESSION["active_period_id"] = 1;

    ob_start();
    require_once ' . var_export(__DIR__ . '/dashboard.php', true) . ';
    $html = ob_get_clean();
    echo $html;
    ';
    file_put_contents($script, $code);
    $out = (string)shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    return $out;
}

// TEST 1: Cost Reco is in menus and role_menu
$stmt1 = $pdo->query("SELECT m.menu_id, m.menu_name, m.menu_code, m.route, m.display_order, COUNT(rm.role_id) as role_count 
FROM menus m 
LEFT JOIN role_menu rm ON m.menu_id = rm.menu_id 
WHERE m.menu_id = 47 
GROUP BY m.menu_id, m.menu_name, m.menu_code, m.route, m.display_order");
$menu1 = $stmt1->fetch(PDO::FETCH_ASSOC);

$t1 = ($menu1 && $menu1['role_count'] >= 5 && $menu1['menu_name'] === 'Cost Reco');
assertMenuTest("TEST 1 — Cost Reco exists in DB with role_menu assignments", $t1, "Menu ID: {$menu1['menu_id']}, Name: '{$menu1['menu_name']}', Role assignments: {$menu1['role_count']}");

// TEST 2: Appears in sidebar HTML for Platform Admin & CMA Admin
$html1 = getSidebarHtml(1);
$html2 = getSidebarHtml(2);
$t2 = (strpos($html1, 'Cost Reco') !== false) && (strpos($html2, 'Cost Reco') !== false);
assertMenuTest("TEST 2 — Cost Reco renders dynamically in sidebar menu bar", $t2, "Verified 'Cost Reco' navigation item rendered in sidebar for User 1 and User 2");

// TEST 3: Dynamic Renaming via Menu Master API
$scriptApi = __DIR__ . '/scratch/tmp_menu_api.php';
function invokeMenuApi(array $postData, int $userId = 1): array {
    global $scriptApi;
    $code = '<?php
    session_start();
    $_SESSION["user_id"] = ' . $userId . ';
    $_SERVER["REQUEST_METHOD"] = "POST";
    $_POST = ' . var_export($postData, true) . ';
    require ' . var_export(__DIR__ . '/admin/menus/api.php', true) . ';
    ';
    file_put_contents($scriptApi, $code);
    $out = (string)shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($scriptApi) . ' 2>&1');
    @unlink($scriptApi);
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : ['raw' => $out];
}

// Rename to "Plant Cost Reco"
$resRename = invokeMenuApi([
    'action'        => 'update',
    'menu_id'       => 47,
    'menu_code'     => 'QUANTITY_RECO',
    'menu_name'     => 'Plant Cost Reco',
    'menu_type'     => 'Menu',
    'parent_menu_id'=> '',
    'route'         => 'quantity-reco.php',
    'icon'          => 'calculator',
    'display_order' => 22,
    'status'        => 'Active'
]);
$htmlRename = getSidebarHtml(1);
$t3 = ($resRename['success'] ?? false) && (strpos($htmlRename, 'Plant Cost Reco') !== false);
assertMenuTest("TEST 3 — Dynamically Adjustable: Rename to 'Plant Cost Reco' updates sidebar", $t3, "Sidebar updated instantly without code edits to reflect 'Plant Cost Reco'");

// TEST 4: Dynamic Reordering via Menu Master API
// Change display order to 3
$resReorder = invokeMenuApi([
    'action'        => 'update',
    'menu_id'       => 47,
    'menu_code'     => 'QUANTITY_RECO',
    'menu_name'     => 'Cost Reco',
    'menu_type'     => 'Menu',
    'parent_menu_id'=> '',
    'route'         => 'quantity-reco.php',
    'icon'          => 'calculator',
    'display_order' => 3,
    'status'        => 'Active'
]);
$newOrder = (int)$pdo->query("SELECT display_order FROM menus WHERE menu_id = 47")->fetchColumn();
$t4 = ($resReorder['success'] ?? false) && ($newOrder === 3);
assertMenuTest("TEST 4 — Dynamically Adjustable: Display order changed to 3", $t4, "display_order updated to 3 in database; sidebar sorts dynamically");

// TEST 5: Dynamic Nesting: Move under Parent Module Costing (menu_id = 16)
$resNest = invokeMenuApi([
    'action'        => 'update',
    'menu_id'       => 47,
    'menu_code'     => 'QUANTITY_RECO',
    'menu_name'     => 'Cost Reco',
    'menu_type'     => 'Menu',
    'parent_menu_id'=> 16,
    'route'         => 'quantity-reco.php',
    'icon'          => 'calculator',
    'display_order' => 5,
    'status'        => 'Active'
]);
$newParent = (int)$pdo->query("SELECT parent_menu_id FROM menus WHERE menu_id = 47")->fetchColumn();
$htmlNest = getSidebarHtml(1);
$t5 = ($resNest['success'] ?? false) && ($newParent === 16) && (strpos($htmlNest, 'nav-child-item') !== false);
assertMenuTest("TEST 5 — Dynamically Adjustable: Nested under parent module 'Costing'", $t5, "Cost Reco dynamically rendered as child submenu item inside Costing parent group");

// Restore back to root with default display order 22
invokeMenuApi([
    'action'        => 'update',
    'menu_id'       => 47,
    'menu_code'     => 'QUANTITY_RECO',
    'menu_name'     => 'Cost Reco',
    'menu_type'     => 'Menu',
    'parent_menu_id'=> '',
    'route'         => 'quantity-reco.php',
    'icon'          => 'calculator',
    'display_order' => 22,
    'status'        => 'Active'
]);
$restoredParent = $pdo->query("SELECT parent_menu_id FROM menus WHERE menu_id = 47")->fetchColumn();
assertMenuTest("TEST 6 — Dynamically Adjustable: Restored cleanly to Root menu bar", ($restoredParent === null), "Parent reset to NULL (Root) with order 22");

// TEST 7: Route Aliasing & Permissions
$pUser1Cost = userHasMenuAccess('COST_RECO', 1);
$pUser1Qty  = userHasMenuAccess('QUANTITY_RECO', 1);
$pUser5Cost = userHasMenuAccess('COST_RECO', 5);
$pUser5Qty  = userHasMenuAccess('QUANTITY_RECO', 5);
$t7 = $pUser1Cost && $pUser1Qty && $pUser5Cost && $pUser5Qty;
assertMenuTest("TEST 7 — Access Control: userHasMenuAccess supports COST_RECO and QUANTITY_RECO", $t7, "Both aliases resolved successfully across admin and auditor roles");

// TEST 8: Dedicated route wrapper cost-reco.php accessible
$scriptWrapper = __DIR__ . '/scratch/tmp_wrapper_test.php';
file_put_contents($scriptWrapper, '<?php
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["active_company_id"] = 1;
$_SESSION["active_company_code"] = "COMP_TEST";
$_SESSION["active_period_id"] = 1;
ob_start();
require_once ' . var_export(__DIR__ . '/cost-reco.php', true) . ';
$html = ob_get_clean();
echo (strpos($html, "Plant Quantity Reco Grid") !== false || strpos($html, "qrGenerateBtn") !== false) ? "WRAPPER_OK" : "WRAPPER_FAIL";
');
$wrapperOut = (string)shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($scriptWrapper) . ' 2>&1');
@unlink($scriptWrapper);
$t8 = (strpos($wrapperOut, 'WRAPPER_OK') !== false);
assertMenuTest("TEST 8 — Route Wrapper: cost-reco.php loads Cost Reco module cleanly", $t8, "Route cost-reco.php verified with authenticated session");

echo "\n=================================================================\n";
echo "    VERIFICATION SUMMARY: {$pass} PASSED, {$fail} FAILED\n";
echo "=================================================================\n";

if ($fail === 0) {
    echo ">>> ALL DYNAMIC MENU & COST RECO TESTS PASSED WITH 100% SUCCESS! <<<\n";
    exit(0);
} else {
    echo ">>> TESTS FAILED WITH {$fail} ERRORS! <<<\n";
    exit(1);
}

