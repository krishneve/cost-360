<?php
/**
 * COST360 — Subscription Management Backend API Endpoint
 * 
 * Secure RESTful actions for:
 * - list_plans: Get available subscription plans
 * - get_active_subscription: Get customer's current active subscription
 * - get_history: Get customer's full subscription history
 * - get_usage: Calculate user usage metrics
 * - check_user_limit: Validate if customer can add new users (enforces max_users)
 * - validate_status: Validate subscription validity & expiration
 * - assign: Assign initial plan to customer
 * - change: Upgrade or downgrade customer subscription (with limit checks)
 * - set_status: Activate/deactivate/suspend a subscription
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/subscription.php';

// Authentication check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required. Please log in.',
        'code' => 'UNAUTHENTICATED'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = getAuthUser();
$currentUserId = (int)($currentUser['user_id'] ?? 0);
$isPlatform = isPlatformAdmin($currentUserId);

$action = trim($_GET['action'] ?? $_POST['action'] ?? 'get_plans');

try {
    /* ============================================================
       ACTION: LIST PLANS
       ============================================================ */
    if ($action === 'list_plans' || $action === 'get_plans') {
        $activeOnly = !isset($_GET['all']) || $_GET['all'] !== '1';
        $plans = getSubscriptionPlans($activeOnly);

        $search = trim($_GET['search'] ?? '');
        $statusFilter = strtoupper(trim($_GET['status'] ?? 'ALL'));
        $typeFilter = strtoupper(trim($_GET['plan_type'] ?? 'ALL'));

        if ($search !== '' || $statusFilter !== 'ALL' || $typeFilter !== 'ALL') {
            $plans = array_values(array_filter($plans, function($p) use ($search, $statusFilter, $typeFilter) {
                if ($statusFilter !== 'ALL' && strtoupper($p['status']) !== $statusFilter) {
                    return false;
                }
                if ($typeFilter !== 'ALL' && strtoupper($p['plan_type']) !== $typeFilter) {
                    return false;
                }
                if ($search !== '') {
                    $s = strtolower($search);
                    $nameMatch = str_contains(strtolower($p['plan_name']), $s);
                    $codeMatch = str_contains(strtolower($p['plan_code']), $s);
                    $descMatch = str_contains(strtolower($p['description'] ?? ''), $s);
                    $typeMatch = str_contains(strtolower($p['plan_type']), $s);
                    if (!$nameMatch && !$codeMatch && !$descMatch && !$typeMatch) {
                        return false;
                    }
                }
                return true;
            }));
        }

        echo json_encode([
            'success' => true,
            'plans' => $plans,
            'total' => count($plans)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: GET PLAN DETAILS
       ============================================================ */
    if ($action === 'get_plan') {
        $planId = (int)($_GET['plan_id'] ?? $_POST['plan_id'] ?? 0);
        if ($planId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid plan_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $plan = getSubscriptionPlanById($planId);
        if (!$plan) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Subscription plan not found.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['success' => true, 'plan' => $plan], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: CREATE / ADD PLAN (ADMIN ONLY)
       ============================================================ */
    if ($action === 'create_plan' || $action === 'add_plan') {
        if (!canUserManageSubscriptionPlans($currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Administrator privileges required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $res = createSubscriptionPlan($_POST, $currentUserId);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: UPDATE / EDIT PLAN (ADMIN ONLY)
       ============================================================ */
    if ($action === 'update_plan' || $action === 'edit_plan') {
        if (!canUserManageSubscriptionPlans($currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Administrator privileges required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $planId = (int)($_POST['plan_id'] ?? $_GET['plan_id'] ?? 0);
        if ($planId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid plan_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $res = updateSubscriptionPlan($planId, $_POST, $currentUserId);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: SET / TOGGLE PLAN STATUS (ACTIVATE / DEACTIVATE - ADMIN ONLY)
       ============================================================ */
    if ($action === 'set_plan_status' || $action === 'toggle_plan_status') {
        if (!canUserManageSubscriptionPlans($currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Administrator privileges required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $planId = (int)($_POST['plan_id'] ?? $_GET['plan_id'] ?? 0);
        $status = trim($_POST['status'] ?? $_GET['status'] ?? '');

        if ($planId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid plan_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $res = setSubscriptionPlanStatus($planId, $status, $currentUserId);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: DELETE PLAN (ADMIN ONLY)
       ============================================================ */
    if ($action === 'delete_plan') {
        if (!canUserManageSubscriptionPlans($currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Administrator privileges required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $planId = (int)($_POST['plan_id'] ?? $_GET['plan_id'] ?? 0);
        if ($planId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid plan_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $res = deleteSubscriptionPlan($planId, $currentUserId);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: LIST CUSTOMER SUBSCRIPTIONS (TABLE & FILTERS)
       ============================================================ */
    if ($action === 'list_customer_subscriptions') {
        $filters = [
            'search' => trim($_GET['search'] ?? ''),
            'plan_id' => trim($_GET['plan_id'] ?? 'ALL'),
            'status' => strtoupper(trim($_GET['status'] ?? 'ALL'))
        ];

        $result = getAllCustomerSubscriptions($filters, $currentUserId);

        echo json_encode([
            'success' => true,
            'subscriptions' => $result['subscriptions'],
            'total' => $result['total'],
            'kpis' => $result['kpis']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: GET CUSTOMER SUBSCRIPTION DETAILS
       ============================================================ */
    if ($action === 'get_customer_subscription_details') {
        $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

        if ($customerId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid customer_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!canUserAccessCustomerSubscription($customerId, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: You do not have access to this customer.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo = getDbConnection();
        $stmtCust = $pdo->prepare("SELECT customer_id, customer_code, customer_name, status, created_at FROM customers WHERE customer_id = :cid");
        $stmtCust->execute([':cid' => $customerId]);
        $customer = $stmtCust->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Customer record not found.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $subscription = getCustomerActiveSubscription($customerId);
        $usage = getCustomerUserUsage($customerId);
        $slots = calculateAvailableUserSlots($customerId);
        $history = getCustomerSubscriptionsHistory($customerId);

        echo json_encode([
            'success' => true,
            'customer' => $customer,
            'subscription' => $subscription,
            'usage' => $usage,
            'slots' => $slots,
            'history' => $history
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: GET ACTIVE SUBSCRIPTION
       ============================================================ */
    if ($action === 'get_active_subscription') {
        $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

        if ($customerId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid customer_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Security check: customer scope
        if (!canUserAccessCustomerSubscription($customerId, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: You do not have access to this customer.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $subscription = getCustomerActiveSubscription($customerId);
        $usage = getCustomerUserUsage($customerId);
        $slots = calculateAvailableUserSlots($customerId);

        echo json_encode([
            'success' => true,
            'customer_id' => $customerId,
            'subscription' => $subscription,
            'usage' => $usage,
            'slots' => $slots
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: GET SUBSCRIPTION HISTORY
       ============================================================ */
    if ($action === 'get_history') {
        $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

        if ($customerId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid customer_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!canUserAccessCustomerSubscription($customerId, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: You do not have access to this customer.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $history = getCustomerSubscriptionsHistory($customerId);

        echo json_encode([
            'success' => true,
            'customer_id' => $customerId,
            'history' => $history,
            'count' => count($history)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: GET USER USAGE
       ============================================================ */
    if ($action === 'get_usage') {
        $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

        if ($customerId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid customer_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!canUserAccessCustomerSubscription($customerId, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: You do not have access to this customer.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $usage = getCustomerUserUsage($customerId);
        $slots = calculateAvailableUserSlots($customerId);

        echo json_encode([
            'success' => true,
            'usage' => $usage,
            'slots' => $slots
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: CHECK USER LIMIT
       ============================================================ */
    if ($action === 'check_user_limit') {
        $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

        if ($customerId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid customer_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!canUserAccessCustomerSubscription($customerId, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: You do not have access to this customer.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = checkUserLimit($customerId);

        echo json_encode(array_merge(['success' => true], $result), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: VALIDATE SUBSCRIPTION STATUS
       ============================================================ */
    if ($action === 'validate_status') {
        $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

        if ($customerId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid customer_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!canUserAccessCustomerSubscription($customerId, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: You do not have access to this customer.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $validation = validateSubscriptionStatus($customerId);

        echo json_encode(array_merge(['success' => true], $validation), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: ASSIGN SUBSCRIPTION (ADMIN ONLY)
       ============================================================ */
    if ($action === 'assign') {
        if (!canUserManageCustomerSubscription(0, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Only Platform Administrators can assign subscriptions.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $customerId = (int)($_POST['customer_id'] ?? 0);
        $planId = (int)($_POST['plan_id'] ?? 0);
        $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));
        $endDate = trim($_POST['end_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $forceReplace = !empty($_POST['force_replace']) && ($_POST['force_replace'] === '1' || $_POST['force_replace'] === 'true');

        $options = [
            'start_date' => $startDate,
            'end_date' => $endDate !== '' ? $endDate : null,
            'notes' => $notes !== '' ? $notes : 'Assigned via API',
            'assigned_by' => $currentUserId,
            'force_replace' => $forceReplace
        ];

        $res = assignSubscription($customerId, $planId, $options);
        if (!$res['success']) {
            http_response_code(400);
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: CHANGE SUBSCRIPTION (UPGRADE / DOWNGRADE - ADMIN ONLY)
       ============================================================ */
    if ($action === 'change') {
        if (!canUserManageCustomerSubscription(0, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Only Platform Administrators can change subscriptions.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $customerId = (int)($_POST['customer_id'] ?? 0);
        $newPlanId = (int)($_POST['new_plan_id'] ?? $_POST['plan_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');

        $options = [
            'notes' => $notes !== '' ? $notes : null,
            'end_date' => $endDate !== '' ? $endDate : null,
            'assigned_by' => $currentUserId
        ];

        $res = changeSubscription($customerId, $newPlanId, $options);
        if (!$res['success']) {
            http_response_code(400);
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       ACTION: SET STATUS (ACTIVATE / DEACTIVATE - ADMIN ONLY)
       ============================================================ */
    if ($action === 'set_status') {
        if (!canUserManageCustomerSubscription(0, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Only Platform Administrators can modify subscription status.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $options = [
            'notes' => $notes !== '' ? $notes : null,
            'updated_by' => $currentUserId
        ];

        $res = setSubscriptionStatus($subscriptionId, $status, $options);
        if (!$res['success']) {
            http_response_code(400);
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Default: unknown action
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'."], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
