<?php
/**
 * COST360 — Existing Customer Subscription Safe Backfill Migration
 * 
 * Inspects all existing customers and assigns subscriptions only where missing.
 * Business Logic:
 * - Customers with 0 active users -> FREE plan (max_users = 0)
 * - Customers with >0 active users -> Appropriate tier (e.g. STANDARD, max_users = 15)
 *   so existing accounts are not in an immediate limit violation.
 * - Skips customers who already have an active subscription (no duplicates).
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/subscription.php';

$pdo = getDbConnection();

$mode = (isset($argv[1]) && strtolower($argv[1]) === '--all-free') || (isset($_GET['mode']) && $_GET['mode'] === 'all_free')
    ? 'all_free' 
    : 'appropriate';

echo "=====================================================================\n";
echo "COST360 — EXISTING CUSTOMER SUBSCRIPTION BACKFILL MIGRATION\n";
echo "Mode: " . strtoupper($mode) . "\n";
echo "=====================================================================\n\n";

// 1. Inspect existing customers
$customers = $pdo->query("
    SELECT customer_id, customer_code, customer_name, status, created_at 
    FROM customers 
    ORDER BY customer_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

echo "1. Current Customers Found: " . count($customers) . "\n";

// 2. Execute Backfill
$summary = backfillCustomerSubscriptions($mode, 1);

echo "\n2. Migration Results:\n";
echo "   Total Customers Examined: {$summary['total_customers']}\n";
echo "   Subscriptions Assigned  : {$summary['assigned']}\n";
echo "   Already Subscribed      : {$summary['skipped']}\n\n";

echo "3. Detailed Customer Status Breakdown:\n";
echo str_repeat('-', 100) . "\n";
printf("%-6s | %-12s | %-30s | %-18s | %-20s\n", "ID", "Code", "Customer Name", "Action Taken", "Plan Code / Status");
echo str_repeat('-', 100) . "\n";

foreach ($summary['details'] as $d) {
    printf(
        "%-6d | %-12s | %-30s | %-18s | %-20s\n",
        $d['customer_id'],
        $d['customer_code'],
        substr($d['customer_name'], 0, 30),
        $d['action'],
        $d['plan_code'] ?? 'N/A'
    );
}
echo str_repeat('-', 100) . "\n\n";

// 4. Verification Check
echo "4. Post-Migration Verification:\n";
$stmtVerify = $pdo->query("
    SELECT 
        c.customer_id, 
        c.customer_code, 
        c.customer_name, 
        sp.plan_code, 
        sp.max_users,
        cs.status AS sub_status,
        (SELECT COUNT(DISTINCT uoa.user_id) 
         FROM user_organization_access uoa 
         JOIN users u ON u.user_id = uoa.user_id 
         WHERE uoa.customer_id = c.customer_id 
           AND UPPER(uoa.status) = 'ACTIVE' 
           AND u.is_active = TRUE) AS active_users
    FROM customers c
    LEFT JOIN customer_subscriptions cs ON c.customer_id = cs.customer_id AND UPPER(cs.status) = 'ACTIVE'
    LEFT JOIN subscription_plans sp ON cs.plan_id = sp.plan_id
    ORDER BY c.customer_id ASC
");
$verifiedList = $stmtVerify->fetchAll(PDO::FETCH_ASSOC);

$allHaveActiveSub = true;
foreach ($verifiedList as $v) {
    $hasSub = !empty($v['plan_code']);
    if (!$hasSub) $allHaveActiveSub = false;
    $statusText = $hasSub ? "[OK] {$v['plan_code']} (max_users: {$v['max_users']}, active_users: {$v['active_users']})" : "[FAIL] NO ACTIVE SUBSCRIPTION";
    echo "   Customer #{$v['customer_id']} ({$v['customer_code']}): {$statusText}\n";
}

echo "\n=====================================================================\n";
if ($allHaveActiveSub) {
    echo "STATUS: ALL EXISTING CUSTOMERS HAVE VALID SUBSCRIPTIONS ASSIGNED.\n";
} else {
    echo "STATUS: SOME CUSTOMERS STILL LACK SUBSCRIPTIONS.\n";
}
echo "=====================================================================\n";
