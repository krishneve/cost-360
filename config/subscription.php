<?php
/**
 * COST360 — Subscription Management Core Service & Business Logic
 * 
 * Provides backend services for:
 * 1. Get subscription plans
 * 2. Get customer active subscription & history
 * 3. Assign subscription
 * 4. Change subscription (with upgrade/downgrade validation)
 * 5. Activate/deactivate subscription
 * 6. Calculate customer user usage
 * 7. Calculate available user slots (available_users = max_users - current_active_users)
 * 8. Validate subscription status
 * 9. Reusable User Limit Validation: checkUserLimit(customer_id)
 * 10. Security & Customer Scope authorization
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

/* ============================================================
   AUDIT LOGGING HELPER
   ============================================================ */

if (!function_exists('logSubscriptionAudit')) {
    function logSubscriptionAudit(
        PDO $pdo,
        ?int $userId,
        string $action,
        int $subscriptionId,
        ?array $oldVal = null,
        ?array $newVal = null
    ): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            // Map to valid PostgreSQL check constraint ('INSERT', 'UPDATE', 'DELETE')
            $dbAction = 'UPDATE';
            $upperAction = strtoupper(trim($action));
            if (in_array($upperAction, ['INSERT', 'ASSIGN'], true)) {
                $dbAction = 'INSERT';
            } elseif (in_array($upperAction, ['DELETE', 'REMOVE'], true)) {
                $dbAction = 'DELETE';
            }

            if ($newVal !== null) {
                $newVal['operation'] = $upperAction;
            } else {
                $newVal = ['operation' => $upperAction];
            }

            $stmt = $pdo->prepare("
                INSERT INTO public.audit_log
                (
                    user_id,
                    entity_name,
                    entity_id,
                    action,
                    old_value,
                    new_value,
                    timestamp,
                    ip_address
                )
                VALUES
                (
                    :user_id,
                    'customer_subscriptions',
                    :entity_id,
                    :action,
                    :old_value,
                    :new_value,
                    CURRENT_TIMESTAMP,
                    :ip_address
                )
            ");

            $stmt->execute([
                ':user_id' => ($userId && $userId > 0) ? $userId : null,
                ':entity_id' => $subscriptionId,
                ':action' => $dbAction,
                ':old_value' => $oldVal !== null ? json_encode($oldVal, JSON_UNESCAPED_UNICODE) : null,
                ':new_value' => json_encode($newVal, JSON_UNESCAPED_UNICODE),
                ':ip_address' => $ip
            ]);
        } catch (Throwable $e) {
            error_log('Subscription Audit Log Error: ' . $e->getMessage());
        }
    }
}


/* ============================================================
   1. GET SUBSCRIPTION PLANS
   ============================================================ */

if (!function_exists('getSubscriptionPlans')) {
    /**
     * Retrieve available subscription plans.
     *
     * @param bool $activeOnly Filter by active status
     * @return array List of subscription plans
     */
    function getSubscriptionPlans(bool $activeOnly = true): array
    {
        $pdo = getDbConnection();

        $sql = "
            SELECT 
                plan_id,
                plan_code,
                plan_name,
                description,
                plan_type,
                price,
                currency,
                billing_cycle,
                max_users,
                limits_config,
                status,
                created_at,
                updated_at
            FROM subscription_plans
        ";

        if ($activeOnly) {
            $sql .= " WHERE UPPER(status) = 'ACTIVE'";
        }

        $sql .= " ORDER BY price ASC, max_users ASC, plan_id ASC";

        $stmt = $pdo->query($sql);
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($plans as &$p) {
            $p['plan_id'] = (int)$p['plan_id'];
            $p['max_users'] = (int)$p['max_users'];
            $p['price'] = (float)$p['price'];
            if (is_string($p['limits_config'])) {
                $decoded = json_decode($p['limits_config'], true);
                $p['limits_config'] = is_array($decoded) ? $decoded : [];
            }
        }
        unset($p);

        return $plans;
    }
}

if (!function_exists('getSubscriptionPlanById')) {
    /**
     * Retrieve a specific plan by its primary ID.
     */
    function getSubscriptionPlanById(int $planId): ?array
    {
        if ($planId <= 0) {
            return null;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT 
                plan_id,
                plan_code,
                plan_name,
                description,
                plan_type,
                price,
                currency,
                billing_cycle,
                max_users,
                limits_config,
                status,
                created_at,
                updated_at
            FROM subscription_plans
            WHERE plan_id = :plan_id
            LIMIT 1
        ");
        $stmt->execute([':plan_id' => $planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            return null;
        }

        $plan['plan_id'] = (int)$plan['plan_id'];
        $plan['max_users'] = (int)$plan['max_users'];
        $plan['price'] = (float)$plan['price'];
        if (is_string($plan['limits_config'])) {
            $decoded = json_decode($plan['limits_config'], true);
            $plan['limits_config'] = is_array($decoded) ? $decoded : [];
        }

        return $plan;
    }
}

if (!function_exists('getSubscriptionPlanByCode')) {
    /**
     * Retrieve a specific plan by its unique code (e.g., 'FREE', 'STANDARD').
     */
    function getSubscriptionPlanByCode(string $planCode): ?array
    {
        $planCode = trim($planCode);
        if ($planCode === '') {
            return null;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT 
                plan_id,
                plan_code,
                plan_name,
                description,
                plan_type,
                price,
                currency,
                billing_cycle,
                max_users,
                limits_config,
                status,
                created_at,
                updated_at
            FROM subscription_plans
            WHERE UPPER(plan_code) = UPPER(:plan_code)
            LIMIT 1
        ");
        $stmt->execute([':plan_code' => $planCode]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            return null;
        }

        $plan['plan_id'] = (int)$plan['plan_id'];
        $plan['max_users'] = (int)$plan['max_users'];
        $plan['price'] = (float)$plan['price'];
        if (is_string($plan['limits_config'])) {
            $decoded = json_decode($plan['limits_config'], true);
            $plan['limits_config'] = is_array($decoded) ? $decoded : [];
        }

        return $plan;
    }
}


/* ============================================================
   2. GET CUSTOMER ACTIVE SUBSCRIPTION & HISTORY
   ============================================================ */

if (!function_exists('getActiveCustomerSubscription')) {
    function getActiveCustomerSubscription(int $customerId): ?array
    {
        return getCustomerActiveSubscription($customerId);
    }
}

if (!function_exists('getCustomerActiveSubscription')) {
    /**
     * Retrieve the single active subscription for a customer.
     *
     * @param int $customerId
     * @return array|null Returns active subscription record or null
     */
    function getCustomerActiveSubscription(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT 
                cs.subscription_id,
                cs.customer_id,
                cs.plan_id,
                cs.start_date,
                cs.end_date,
                cs.status,
                cs.assigned_by,
                cs.assigned_date,
                cs.notes,
                cs.created_at,
                cs.updated_at,
                sp.plan_code,
                sp.plan_name,
                sp.description AS plan_description,
                sp.plan_type,
                sp.price,
                sp.currency,
                sp.billing_cycle,
                sp.max_users,
                sp.limits_config,
                u.full_name AS assigned_by_name,
                u.email AS assigned_by_email,
                c.customer_code,
                c.customer_name,
                c.status AS customer_status
            FROM customer_subscriptions cs
            JOIN subscription_plans sp ON cs.plan_id = sp.plan_id
            JOIN customers c ON cs.customer_id = c.customer_id
            LEFT JOIN users u ON cs.assigned_by = u.user_id
            WHERE cs.customer_id = :customer_id
              AND UPPER(cs.status) = 'ACTIVE'
            ORDER BY cs.subscription_id DESC
            LIMIT 1
        ");

        $stmt->execute([':customer_id' => $customerId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            return null;
        }

        $sub['subscription_id'] = (int)$sub['subscription_id'];
        $sub['customer_id'] = (int)$sub['customer_id'];
        $sub['plan_id'] = (int)$sub['plan_id'];
        $sub['max_users'] = (int)$sub['max_users'];
        $sub['price'] = (float)$sub['price'];

        if (is_string($sub['limits_config'])) {
            $decoded = json_decode($sub['limits_config'], true);
            $sub['limits_config'] = is_array($decoded) ? $decoded : [];
        }

        // Check date expiry
        $today = date('Y-m-d');
        $sub['is_expired'] = false;
        $sub['days_remaining'] = null;

        if (!empty($sub['end_date'])) {
            if ($sub['end_date'] < $today) {
                $sub['is_expired'] = true;
                $sub['days_remaining'] = 0;
                // Active subscription reached end date: transition ACTIVE -> EXPIRED
                try {
                    $updExp = $pdo->prepare("
                        UPDATE customer_subscriptions 
                        SET status = 'EXPIRED', 
                            updated_at = CURRENT_TIMESTAMP,
                            notes = CONCAT(COALESCE(notes, ''), ' [Expired on ', CURRENT_DATE::text, ']')
                        WHERE subscription_id = :sid AND UPPER(status) = 'ACTIVE'
                    ");
                    $updExp->execute([':sid' => $sub['subscription_id']]);

                    logSubscriptionAudit($pdo, null, 'SUBSCRIPTION_EXPIRED', (int)$sub['subscription_id'], [
                        'status' => 'ACTIVE'
                    ], [
                        'status' => 'EXPIRED',
                        'customer_id' => $sub['customer_id'],
                        'customer_name' => $sub['customer_name'],
                        'plan_name' => $sub['plan_name'],
                        'reason' => "Subscription reached end date ({$sub['end_date']})"
                    ]);
                } catch (Throwable $e) {
                    error_log('Error transitioning expired subscription: ' . $e->getMessage());
                }
                return null;
            } else {
                $diff = (strtotime($sub['end_date']) - strtotime($today)) / 86400;
                $sub['days_remaining'] = (int)round($diff);
            }
        }

        return $sub;
    }
}

if (!function_exists('getCustomerSubscriptionsHistory')) {
    /**
     * Retrieve full subscription history for a customer (historical records are never deleted).
     *
     * @param int $customerId
     * @return array
     */
    function getCustomerSubscriptionsHistory(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT 
                cs.subscription_id,
                cs.customer_id,
                cs.plan_id,
                cs.start_date,
                cs.end_date,
                cs.status,
                cs.assigned_by,
                cs.assigned_date,
                cs.notes,
                cs.created_at,
                cs.updated_at,
                sp.plan_code,
                sp.plan_name,
                sp.plan_type,
                sp.price,
                sp.currency,
                sp.billing_cycle,
                sp.max_users,
                u.full_name AS assigned_by_name
            FROM customer_subscriptions cs
            JOIN subscription_plans sp ON cs.plan_id = sp.plan_id
            LEFT JOIN users u ON cs.assigned_by = u.user_id
            WHERE cs.customer_id = :customer_id
            ORDER BY cs.subscription_id DESC
        ");

        $stmt->execute([':customer_id' => $customerId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($history as &$h) {
            $h['subscription_id'] = (int)$h['subscription_id'];
            $h['customer_id'] = (int)$h['customer_id'];
            $h['plan_id'] = (int)$h['plan_id'];
            $h['max_users'] = (int)$h['max_users'];
            $h['price'] = (float)$h['price'];
        }
        unset($h);

        return $history;
    }
}

if (!function_exists('processExpiredSubscriptions')) {
    /**
     * Scan active subscriptions and transition any that have passed their end_date to EXPIRED.
     * Preserves customer, users, and history. Logs SUBSCRIPTION_EXPIRED audit event.
     *
     * @return int Number of expired subscriptions processed
     */
    function processExpiredSubscriptions(): int
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT cs.subscription_id, cs.customer_id, cs.plan_id, cs.end_date, c.customer_name, sp.plan_name
            FROM customer_subscriptions cs
            JOIN customers c ON cs.customer_id = c.customer_id
            JOIN subscription_plans sp ON cs.plan_id = sp.plan_id
            WHERE UPPER(cs.status) = 'ACTIVE'
              AND cs.end_date IS NOT NULL
              AND cs.end_date < CURRENT_DATE
        ");
        $stmt->execute();
        $expiredSubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expiredSubs)) {
            return 0;
        }

        $count = 0;
        foreach ($expiredSubs as $sub) {
            $upd = $pdo->prepare("
                UPDATE customer_subscriptions
                SET status = 'EXPIRED',
                    updated_at = CURRENT_TIMESTAMP,
                    notes = CONCAT(COALESCE(notes, ''), ' [Expired on ', CURRENT_DATE::text, ']')
                WHERE subscription_id = :id AND UPPER(status) = 'ACTIVE'
            ");
            $upd->execute([':id' => $sub['subscription_id']]);

            logSubscriptionAudit($pdo, null, 'SUBSCRIPTION_EXPIRED', (int)$sub['subscription_id'], [
                'status' => 'ACTIVE'
            ], [
                'status' => 'EXPIRED',
                'customer_id' => (int)$sub['customer_id'],
                'customer_name' => $sub['customer_name'],
                'plan_name' => $sub['plan_name'],
                'reason' => "End date ({$sub['end_date']}) reached"
            ]);
            $count++;
        }
        return $count;
    }
}

if (!function_exists('getAllCustomerSubscriptions')) {
    /**
     * Retrieve all customer subscriptions with tenant user counts, slot calculations,
     * filter support (search, plan_id, status), and KPI metrics.
     *
     * @param array|null $filters ['search' => string, 'plan_id' => int|string, 'status' => string]
     * @param int|null $userId User ID for customer scoping (Customer Admin sees only own customer)
     * @return array ['subscriptions' => array, 'total' => int, 'kpis' => array]
     */
    function getAllCustomerSubscriptions(?array $filters = null, ?int $userId = null): array
    {
        // First process any expired subscriptions
        processExpiredSubscriptions();

        $pdo = getDbConnection();

        if ($userId === null) {
            $user = getAuthUser();
            $userId = (int)($user['user_id'] ?? 0);
        }

        $isPlatform = isPlatformAdmin($userId);

        $sql = "
            SELECT 
                c.customer_id,
                c.customer_code,
                c.customer_name,
                c.status AS customer_status,
                cs.subscription_id,
                cs.plan_id,
                cs.start_date,
                cs.end_date,
                cs.status AS subscription_status,
                cs.notes,
                cs.assigned_date,
                cs.assigned_by,
                u.full_name AS assigned_by_name,
                sp.plan_code,
                sp.plan_name,
                sp.plan_type,
                sp.price,
                sp.currency,
                sp.billing_cycle,
                COALESCE(sp.max_users, 0) AS max_users,
                COALESCE(uc.active_users, 0) AS current_users
            FROM customers c
            LEFT JOIN customer_subscriptions cs 
                ON c.customer_id = cs.customer_id 
                AND UPPER(cs.status) = 'ACTIVE'
            LEFT JOIN subscription_plans sp 
                ON cs.plan_id = sp.plan_id
            LEFT JOIN users u 
                ON cs.assigned_by = u.user_id
            LEFT JOIN (
                SELECT 
                    uoa.customer_id,
                    COUNT(DISTINCT uoa.user_id) AS active_users
                FROM user_organization_access uoa
                JOIN users usr ON uoa.user_id = usr.user_id
                WHERE UPPER(uoa.status) = 'ACTIVE' 
                  AND usr.is_active = TRUE 
                  AND (usr.status IS NULL OR UPPER(usr.status) = 'ACTIVE')
                GROUP BY uoa.customer_id
            ) uc ON c.customer_id = uc.customer_id
        ";

        $where = [];
        $params = [];

        // Scoping for non-platform admins
        if (!$isPlatform && $userId > 0) {
            $where[] = "c.customer_id IN (
                SELECT customer_id 
                FROM user_organization_access 
                WHERE user_id = :scope_user_id AND UPPER(status) = 'ACTIVE' AND customer_id IS NOT NULL
            )";
            $params[':scope_user_id'] = $userId;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY c.customer_name ASC, c.customer_id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        $processed = [];

        // KPI accumulators
        $totalSubscriptions = count($rows);
        $activeSubscriptions = 0;
        $nearLimitCount = 0;
        $expiredInactiveCount = 0;

        foreach ($rows as $r) {
            $r['customer_id'] = (int)$r['customer_id'];
            $r['subscription_id'] = $r['subscription_id'] ? (int)$r['subscription_id'] : null;
            $r['plan_id'] = $r['plan_id'] ? (int)$r['plan_id'] : null;
            $r['current_users'] = (int)$r['current_users'];
            $r['max_users'] = (int)$r['max_users'];
            $r['price'] = (float)($r['price'] ?? 0);

            $isExpired = (!empty($r['end_date']) && $r['end_date'] < $today);
            $r['is_expired'] = $isExpired;

            if (!$r['subscription_id'] || !$r['plan_id']) {
                $r['display_status'] = 'NO_PLAN';
                $r['plan_name'] = 'No Active Plan';
                $r['plan_code'] = 'NONE';
                $r['available_users'] = 0;
                $expiredInactiveCount++;
            } elseif ($isExpired) {
                $r['display_status'] = 'EXPIRED';
                $r['available_users'] = 0;
                $expiredInactiveCount++;
            } else {
                $r['display_status'] = strtoupper($r['subscription_status'] ?? 'ACTIVE');
                if ($r['display_status'] === 'ACTIVE') {
                    $activeSubscriptions++;
                } else {
                    $expiredInactiveCount++;
                }

                if ($r['max_users'] === 0) {
                    $r['available_users'] = 0;
                } else {
                    $r['available_users'] = max(0, $r['max_users'] - $r['current_users']);
                    if ($r['max_users'] > 0 && ($r['current_users'] / $r['max_users']) >= 0.8) {
                        $nearLimitCount++;
                    }
                }
            }

            // Days remaining calculation
            $r['days_remaining'] = null;
            if (!empty($r['end_date'])) {
                if ($isExpired) {
                    $r['days_remaining'] = 0;
                } else {
                    $diff = (strtotime($r['end_date']) - strtotime($today)) / 86400;
                    $r['days_remaining'] = (int)round($diff);
                }
            }

            $processed[] = $r;
        }

        // Apply In-Memory Filters (search, plan_id, status)
        $search = trim($filters['search'] ?? '');
        $planFilter = trim($filters['plan_id'] ?? 'ALL');
        $statusFilter = strtoupper(trim($filters['status'] ?? 'ALL'));

        if ($search !== '' || $planFilter !== 'ALL' || $statusFilter !== 'ALL') {
            $processed = array_values(array_filter($processed, function($row) use ($search, $planFilter, $statusFilter) {
                if ($statusFilter !== 'ALL') {
                    if (strtoupper($row['display_status']) !== $statusFilter) {
                        return false;
                    }
                }
                if ($planFilter !== 'ALL') {
                    if ((string)$row['plan_id'] !== (string)$planFilter && strtoupper($row['plan_code']) !== strtoupper($planFilter)) {
                        return false;
                    }
                }
                if ($search !== '') {
                    $q = strtolower($search);
                    $cName = strtolower($row['customer_name'] ?? '');
                    $cCode = strtolower($row['customer_code'] ?? '');
                    $pName = strtolower($row['plan_name'] ?? '');
                    $pCode = strtolower($row['plan_code'] ?? '');
                    if (!str_contains($cName, $q) && !str_contains($cCode, $q) && !str_contains($pName, $q) && !str_contains($pCode, $q)) {
                        return false;
                    }
                }
                return true;
            }));
        }

        return [
            'subscriptions' => $processed,
            'total' => count($processed),
            'kpis' => [
                'total_subscriptions' => $totalSubscriptions,
                'active_subscriptions' => $activeSubscriptions,
                'near_limit' => $nearLimitCount,
                'expired_inactive' => $expiredInactiveCount
            ]
        ];
    }
}



/* ============================================================
   6. CALCULATE USER USAGE
   ============================================================ */

if (!function_exists('getCustomerUserUsage')) {
    /**
     * Count active users associated with a customer.
     *
     * @param int $customerId
     * @return array Usage metrics (active_users, admin_users, standard_users)
     */
    function getCustomerUserUsage(int $customerId): array
    {
        if ($customerId <= 0) {
            return [
                'customer_id' => $customerId,
                'active_users' => 0,
                'admin_users' => 0,
                'standard_users' => 0,
                'inactive_users' => 0
            ];
        }

        $pdo = getDbConnection();

        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT CASE 
                    WHEN UPPER(uoa.status) = 'ACTIVE' AND u.is_active = TRUE AND (u.status IS NULL OR UPPER(u.status) = 'ACTIVE') 
                    THEN uoa.user_id 
                END) AS active_users,

                COUNT(DISTINCT CASE 
                    WHEN UPPER(uoa.status) = 'ACTIVE' AND u.is_active = TRUE AND (u.status IS NULL OR UPPER(u.status) = 'ACTIVE')
                         AND UPPER(COALESCE(r.role_code, '')) = 'CUSTOMER_ADMIN'
                    THEN uoa.user_id 
                END) AS admin_users,

                COUNT(DISTINCT CASE 
                    WHEN UPPER(uoa.status) = 'ACTIVE' AND u.is_active = TRUE AND (u.status IS NULL OR UPPER(u.status) = 'ACTIVE')
                         AND UPPER(COALESCE(r.role_code, '')) != 'CUSTOMER_ADMIN'
                    THEN uoa.user_id 
                END) AS standard_users,

                COUNT(DISTINCT CASE 
                    WHEN UPPER(uoa.status) != 'ACTIVE' OR u.is_active = FALSE OR UPPER(COALESCE(u.status, '')) = 'INACTIVE'
                    THEN uoa.user_id 
                END) AS inactive_users

            FROM user_organization_access uoa
            JOIN users u ON uoa.user_id = u.user_id
            LEFT JOIN user_roles ur ON u.user_id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.role_id
            WHERE uoa.customer_id = :customer_id
        ");

        $stmt->execute([':customer_id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'customer_id' => $customerId,
            'active_users' => (int)($row['active_users'] ?? 0),
            'admin_users' => (int)($row['admin_users'] ?? 0),
            'standard_users' => (int)($row['standard_users'] ?? 0),
            'inactive_users' => (int)($row['inactive_users'] ?? 0)
        ];
    }
}


/* ============================================================
   7. CALCULATE AVAILABLE USER SLOTS
   ============================================================ */

if (!function_exists('calculateAvailableUserSlots')) {
    /**
     * Calculate available user slots based on customer active subscription.
     * Core formula: available_users = max_users - current_active_users
     *
     * @param int $customerId
     * @return array Calculation breakdown
     */
    function calculateAvailableUserSlots(int $customerId): array
    {
        $sub = getCustomerActiveSubscription($customerId);
        $usage = getCustomerUserUsage($customerId);
        $currentUsers = $usage['active_users'];

        if (!$sub) {
            return [
                'has_active_subscription' => false,
                'customer_id' => $customerId,
                'plan_code' => null,
                'plan_name' => 'No Active Plan',
                'max_users' => 0,
                'current_active_users' => $currentUsers,
                'available_users' => 0,
                'allowed' => false,
                'reason' => 'Customer has no active subscription assigned.'
            ];
        }

        if ($sub['is_expired']) {
            return [
                'has_active_subscription' => true,
                'customer_id' => $customerId,
                'plan_code' => $sub['plan_code'],
                'plan_name' => $sub['plan_name'],
                'max_users' => $sub['max_users'],
                'current_active_users' => $currentUsers,
                'available_users' => 0,
                'allowed' => false,
                'reason' => 'The customer subscription has expired.'
            ];
        }

        $maxUsers = (int)$sub['max_users'];

        // Special rule for FREE plan (max_users = 0)
        if ($maxUsers === 0 || strtoupper($sub['plan_code']) === 'FREE') {
            return [
                'has_active_subscription' => true,
                'customer_id' => $customerId,
                'plan_code' => $sub['plan_code'],
                'plan_name' => $sub['plan_name'],
                'max_users' => 0,
                'current_active_users' => $currentUsers,
                'available_users' => 0,
                'allowed' => false,
                'reason' => 'User limit reached. Your current subscription allows a maximum limit of 0 users. Please contact the Project Administrator to upgrade your subscription.'
            ];
        }

        $available = max(0, $maxUsers - $currentUsers);
        $allowed = ($available > 0);

        return [
            'has_active_subscription' => true,
            'customer_id' => $customerId,
            'plan_code' => $sub['plan_code'],
            'plan_name' => $sub['plan_name'],
            'max_users' => $maxUsers,
            'current_active_users' => $currentUsers,
            'available_users' => $available,
            'allowed' => $allowed,
            'reason' => $allowed 
                ? "{$available} user slot(s) available." 
                : "User limit reached. Your current subscription allows a maximum of {$maxUsers} users. Please contact the Project Administrator to upgrade your subscription."
        ];
    }
}


/* ============================================================
   8. VALIDATE SUBSCRIPTION STATUS
   ============================================================ */

if (!function_exists('validateSubscriptionStatus')) {
    /**
     * Validate whether a customer has a valid, active subscription.
     *
     * @param int $customerId
     * @return array Status information
     */
    function validateSubscriptionStatus(int $customerId): array
    {
        if ($customerId <= 0) {
            return [
                'is_valid' => false,
                'status' => 'INVALID_CUSTOMER',
                'subscription' => null,
                'message' => 'Invalid customer ID provided.'
            ];
        }

        $sub = getCustomerActiveSubscription($customerId);

        if (!$sub) {
            return [
                'is_valid' => false,
                'status' => 'NO_SUBSCRIPTION',
                'subscription' => null,
                'message' => 'No active subscription found for this customer.'
            ];
        }

        if (strtoupper($sub['customer_status']) !== 'ACTIVE') {
            return [
                'is_valid' => false,
                'status' => 'CUSTOMER_INACTIVE',
                'subscription' => $sub,
                'message' => 'The customer account itself is deactivated/inactive.'
            ];
        }

        if ($sub['is_expired']) {
            return [
                'is_valid' => false,
                'status' => 'EXPIRED',
                'subscription' => $sub,
                'days_remaining' => 0,
                'message' => "Subscription expired on {$sub['end_date']}."
            ];
        }

        if (strtoupper($sub['status']) !== 'ACTIVE') {
            return [
                'is_valid' => false,
                'status' => strtoupper($sub['status']),
                'subscription' => $sub,
                'message' => "Subscription status is {$sub['status']}."
            ];
        }

        return [
            'is_valid' => true,
            'status' => 'ACTIVE',
            'subscription' => $sub,
            'days_remaining' => $sub['days_remaining'],
            'message' => 'Subscription is active and valid.'
        ];
    }
}


/* ============================================================
   9. USER LIMIT VALIDATION SERVICE
   ============================================================ */

if (!function_exists('checkUserLimit')) {
    /**
     * Reusable service function to check if a customer can create new users.
     *
     * Steps:
     * 1. Identify customer's active subscription.
     * 2. Retrieve max_users.
     * 3. Count customer's active users.
     * 4. Compare current users against the limit.
     * 5. Return structured result.
     *
     * @param int $customerId
     * @return array
     */
    function checkUserLimit(int $customerId): array
    {
        $statusValidation = validateSubscriptionStatus($customerId);
        if (!$statusValidation['is_valid']) {
            $usage = getCustomerUserUsage($customerId);
            return [
                'allowed' => false,
                'customer_id' => $customerId,
                'plan_name' => 'None',
                'plan_code' => 'NONE',
                'plan_type' => 'NONE',
                'max_users' => 0,
                'current_users' => $usage['active_users'],
                'available_users' => 0,
                'status' => $statusValidation['status'],
                'reason' => $statusValidation['message']
            ];
        }

        $slots = calculateAvailableUserSlots($customerId);
        $sub = $statusValidation['subscription'];

        return [
            'allowed' => $slots['allowed'],
            'customer_id' => $customerId,
            'plan_name' => $sub['plan_name'],
            'plan_code' => $sub['plan_code'],
            'plan_type' => $sub['plan_type'],
            'max_users' => $slots['max_users'],
            'current_users' => $slots['current_active_users'],
            'available_users' => $slots['available_users'],
            'status' => 'ACTIVE',
            'reason' => $slots['reason']
        ];
    }
}


/* ============================================================
   10. SECURITY & SCOPE AUTHORIZATION
   ============================================================ */

if (!function_exists('canUserAccessCustomerSubscription')) {
    /**
     * Verify whether the authenticated user has permission to view this customer's subscription.
     */
    function canUserAccessCustomerSubscription(int $customerId, ?int $userId = null): bool
    {
        if (isPlatformAdmin($userId)) {
            return true;
        }

        if (!$userId) {
            $user = getAuthUser();
            $userId = (int)($user['user_id'] ?? 0);
        }

        if ($userId <= 0 || $customerId <= 0) {
            return false;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM user_organization_access 
            WHERE user_id = :user_id 
              AND customer_id = :customer_id 
              AND UPPER(status) = 'ACTIVE'
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':customer_id' => $customerId
        ]);

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('canUserManageCustomerSubscription')) {
    /**
     * Verify whether the authenticated user has permission to assign or change subscriptions.
     * Only Platform Administrator (or designated super administrative roles) can change subscriptions.
     */
    function canUserManageCustomerSubscription(int $customerId, ?int $userId = null): bool
    {
        return isPlatformAdmin($userId);
    }
}


/* ============================================================
   3. ASSIGN SUBSCRIPTION
   ============================================================ */

if (!function_exists('assignSubscription')) {
    /**
     * Assign a subscription plan to a customer.
     *
     * @param int $customerId
     * @param int $planId
     * @param array $options Optional settings: ['start_date', 'end_date', 'notes', 'assigned_by', 'force_replace']
     * @return array
     */
    function assignSubscription(int $customerId, int $planId, array $options = []): array
    {
        $pdo = getDbConnection();

        if ($customerId <= 0) {
            return ['success' => false, 'error' => 'Invalid customer ID provided.'];
        }

        if ($planId <= 0) {
            return ['success' => false, 'error' => 'Invalid plan ID provided.'];
        }

        // Validate customer existence
        $stmtCust = $pdo->prepare("SELECT customer_id, customer_name, status FROM customers WHERE customer_id = :id");
        $stmtCust->execute([':id' => $customerId]);
        $customer = $stmtCust->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            return ['success' => false, 'error' => 'Customer not found in system.'];
        }

        // Validate plan existence and status
        $plan = getSubscriptionPlanById($planId);
        if (!$plan) {
            return ['success' => false, 'error' => 'Subscription plan does not exist.'];
        }

        if (strtoupper($plan['status']) !== 'ACTIVE') {
            return ['success' => false, 'error' => "Cannot assign inactive plan '{$plan['plan_name']}'."];
        }

        // Check if customer already has an active subscription
        $existingActive = getCustomerActiveSubscription($customerId);
        if ($existingActive) {
            if (empty($options['force_replace'])) {
                return [
                    'success' => false,
                    'error' => "Customer already has an active subscription ('{$existingActive['plan_name']}'). Use changeSubscription to switch plans.",
                    'code' => 'ACTIVE_SUBSCRIPTION_EXISTS'
                ];
            }
            // Delegate to changeSubscription if force_replace is enabled
            return changeSubscription($customerId, $planId, $options);
        }

        // Check existing user count vs plan limit
        $usage = getCustomerUserUsage($customerId);
        $currentUsers = $usage['active_users'];
        $planMaxUsers = (int)$plan['max_users'];

        if ($planMaxUsers === 0 && $currentUsers > 0) {
            return [
                'success' => false,
                'error' => "Cannot assign FREE plan: customer currently has {$currentUsers} active user(s), but the FREE plan allows 0 users.",
                'code' => 'PLAN_LIMIT_EXCEEDED'
            ];
        }

        if ($planMaxUsers > 0 && $currentUsers > $planMaxUsers) {
            return [
                'success' => false,
                'error' => "Cannot assign plan '{$plan['plan_name']}': customer has {$currentUsers} active user(s), which exceeds plan limit of {$planMaxUsers}.",
                'code' => 'PLAN_LIMIT_EXCEEDED'
            ];
        }

        $startDate = !empty($options['start_date']) ? $options['start_date'] : date('Y-m-d');
        $endDate = !empty($options['end_date']) ? $options['end_date'] : null;
        $notes = $options['notes'] ?? 'Initial subscription assignment';

        $assignedBy = !empty($options['assigned_by']) ? (int)$options['assigned_by'] : null;
        if (!$assignedBy) {
            $user = getAuthUser();
            $assignedBy = (int)($user['user_id'] ?? 0) ?: null;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO customer_subscriptions
                (
                    customer_id,
                    plan_id,
                    start_date,
                    end_date,
                    status,
                    assigned_by,
                    assigned_date,
                    notes,
                    created_at,
                    created_by,
                    updated_at,
                    updated_by
                )
                VALUES
                (
                    :customer_id,
                    :plan_id,
                    :start_date,
                    :end_date,
                    'ACTIVE',
                    :assigned_by,
                    CURRENT_TIMESTAMP,
                    :notes,
                    CURRENT_TIMESTAMP,
                    :created_by,
                    CURRENT_TIMESTAMP,
                    :updated_by
                )
                RETURNING subscription_id
            ");

            $stmt->execute([
                ':customer_id' => $customerId,
                ':plan_id' => $planId,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':assigned_by' => $assignedBy,
                ':notes' => $notes,
                ':created_by' => $assignedBy,
                ':updated_by' => $assignedBy
            ]);

            $subscriptionId = (int)$stmt->fetchColumn();

            // Log audit entry
            logSubscriptionAudit($pdo, $assignedBy, 'SUBSCRIPTION_ASSIGNED', $subscriptionId, null, [
                'customer_id' => $customerId,
                'customer_name' => $customer['customer_name'] ?? '',
                'plan_id' => $planId,
                'plan_code' => $plan['plan_code'],
                'plan_name' => $plan['plan_name'],
                'max_users' => $plan['max_users'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'action' => 'SUBSCRIPTION_ASSIGNED',
                'notes' => $notes
            ]);

            return [
                'success' => true,
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
                'plan' => $plan,
                'message' => "Successfully assigned plan '{$plan['plan_name']}' to customer."
            ];

        } catch (PDOException $e) {
            return [
                'success' => false,
                'error' => 'Database error assigning subscription: ' . $e->getMessage()
            ];
        }
    }
}


/* ============================================================
   4. CHANGE SUBSCRIPTION (UPGRADE / DOWNGRADE)
   ============================================================ */

if (!function_exists('changeSubscription')) {
    /**
     * Change a customer's active subscription (Upgrade or Downgrade).
     *
     * Business Rules:
     * - Upgrade: allowed when new plan has sufficient limit.
     * - Downgrade: MUST BE REJECTED if current_active_users > new_plan.max_users
     * - Historical records are preserved (marked EXPIRED / CANCELLED, never deleted).
     *
     * @param int $customerId
     * @param int $newPlanId
     * @param array $options Optional settings: ['end_date', 'notes', 'assigned_by', 'previous_status']
     * @return array
     */
    function changeSubscription(int $customerId, int $newPlanId, array $options = []): array
    {
        $pdo = getDbConnection();

        if ($customerId <= 0) {
            return ['success' => false, 'error' => 'Invalid customer ID provided.'];
        }

        if ($newPlanId <= 0) {
            return ['success' => false, 'error' => 'Invalid target plan ID provided.'];
        }

        // Validate target plan
        $newPlan = getSubscriptionPlanById($newPlanId);
        if (!$newPlan) {
            return ['success' => false, 'error' => 'Target subscription plan does not exist.'];
        }

        if (strtoupper($newPlan['status']) !== 'ACTIVE') {
            return ['success' => false, 'error' => "Cannot change to inactive plan '{$newPlan['plan_name']}'."];
        }

        // Find existing active subscription
        $currentSub = getCustomerActiveSubscription($customerId);
        if (!$currentSub) {
            // No current active subscription; perform initial assignment
            return assignSubscription($customerId, $newPlanId, $options);
        }

        // Check if plan is identical
        if ((int)$currentSub['plan_id'] === $newPlanId) {
            return [
                'success' => false,
                'error' => "Customer is already currently subscribed to plan '{$newPlan['plan_name']}'.",
                'code' => 'SAME_PLAN'
            ];
        }

        // Determine user limits & upgrade vs downgrade
        $usage = getCustomerUserUsage($customerId);
        $currentActiveUsers = $usage['active_users'];
        $newMaxUsers = (int)$newPlan['max_users'];
        $currentMaxUsers = (int)$currentSub['max_users'];

        $isUpgrade = ($newMaxUsers > $currentMaxUsers) || ($newPlan['price'] > $currentSub['price']);

        // CRITICAL BUSINESS RULE: DOWNGRADE VALIDATION
        // Downgrade must be rejected if: current_active_users > new_plan.max_users
        if ($newMaxUsers === 0 && $currentActiveUsers > 0) {
            return [
                'success' => false,
                'error' => "Subscription cannot be downgraded because the customer currently has {$currentActiveUsers} active users while the selected plan allows only 0 users.",
                'code' => 'DOWNGRADE_REJECTED_USER_LIMIT',
                'current_active_users' => $currentActiveUsers,
                'target_max_users' => 0
            ];
        }

        if ($newMaxUsers > 0 && $currentActiveUsers > $newMaxUsers) {
            $excess = $currentActiveUsers - $newMaxUsers;
            return [
                'success' => false,
                'error' => "Subscription cannot be downgraded because the customer currently has {$currentActiveUsers} active users while the selected plan allows only {$newMaxUsers} users.",
                'code' => 'DOWNGRADE_REJECTED_USER_LIMIT',
                'current_active_users' => $currentActiveUsers,
                'target_max_users' => $newMaxUsers,
                'excess_users' => $excess
            ];
        }

        // Authorization / operator
        $assignedBy = !empty($options['assigned_by']) ? (int)$options['assigned_by'] : null;
        if (!$assignedBy) {
            $user = getAuthUser();
            $assignedBy = (int)($user['user_id'] ?? 0) ?: null;
        }

        $startDate = !empty($options['start_date']) ? $options['start_date'] : date('Y-m-d');
        $endDate = !empty($options['end_date']) ? $options['end_date'] : null;
        $previousStatus = !empty($options['previous_status']) ? $options['previous_status'] : 'EXPIRED';
        $notes = $options['notes'] ?? ($isUpgrade ? "Upgraded from {$currentSub['plan_name']}" : "Downgraded from {$currentSub['plan_name']}");

        try {
            $pdo->beginTransaction();

            // 1. Mark previous active subscription as EXPIRED/CANCELLED (preserving historical record)
            $stmtExpire = $pdo->prepare("
                UPDATE customer_subscriptions
                SET 
                    status = :status,
                    end_date = CURRENT_DATE,
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = :updated_by,
                    notes = CONCAT(COALESCE(notes, ''), ' [Superseded on ', CURRENT_DATE::text, ']')
                WHERE subscription_id = :sub_id
            ");

            $stmtExpire->execute([
                ':status' => $previousStatus,
                ':updated_by' => $assignedBy,
                ':sub_id' => $currentSub['subscription_id']
            ]);

            // 2. Insert new ACTIVE subscription
            $stmtInsert = $pdo->prepare("
                INSERT INTO customer_subscriptions
                (
                    customer_id,
                    plan_id,
                    start_date,
                    end_date,
                    status,
                    assigned_by,
                    assigned_date,
                    notes,
                    created_at,
                    created_by,
                    updated_at,
                    updated_by
                )
                VALUES
                (
                    :customer_id,
                    :plan_id,
                    :start_date,
                    :end_date,
                    'ACTIVE',
                    :assigned_by,
                    CURRENT_TIMESTAMP,
                    :notes,
                    CURRENT_TIMESTAMP,
                    :created_by,
                    CURRENT_TIMESTAMP,
                    :updated_by
                )
                RETURNING subscription_id
            ");

            $stmtInsert->execute([
                ':customer_id' => $customerId,
                ':plan_id' => $newPlanId,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':assigned_by' => $assignedBy,
                ':notes' => $notes,
                ':created_by' => $assignedBy,
                ':updated_by' => $assignedBy
            ]);

            $newSubscriptionId = (int)$stmtInsert->fetchColumn();

            $pdo->commit();

            // Determine specific audit action
            $auditAction = 'SUBSCRIPTION_CHANGED';
            if ($newMaxUsers > $currentMaxUsers) {
                $auditAction = 'SUBSCRIPTION_UPGRADED';
            } elseif ($newMaxUsers < $currentMaxUsers) {
                $auditAction = 'SUBSCRIPTION_DOWNGRADED';
            }

            // Log audit
            logSubscriptionAudit($pdo, $assignedBy, $auditAction, $newSubscriptionId, [
                'previous_subscription_id' => $currentSub['subscription_id'],
                'customer_id' => $customerId,
                'customer_name' => $currentSub['customer_name'] ?? '',
                'previous_plan' => $currentSub['plan_name'],
                'old_plan_id' => $currentSub['plan_id'],
                'old_plan_code' => $currentSub['plan_code'],
                'previous_user_limit' => $currentSub['max_users']
            ], [
                'new_subscription_id' => $newSubscriptionId,
                'customer_id' => $customerId,
                'customer_name' => $currentSub['customer_name'] ?? '',
                'new_plan' => $newPlan['plan_name'],
                'new_plan_id' => $newPlanId,
                'new_plan_code' => $newPlan['plan_code'],
                'new_user_limit' => $newPlan['max_users'],
                'current_active_users' => $currentActiveUsers,
                'action' => $auditAction,
                'notes' => $notes
            ]);

            $actionWord = $isUpgrade ? 'upgraded' : 'downgraded';

            return [
                'success' => true,
                'subscription_id' => $newSubscriptionId,
                'customer_id' => $customerId,
                'is_upgrade' => $isUpgrade,
                'old_plan' => $currentSub,
                'new_plan' => $newPlan,
                'current_users' => $currentActiveUsers,
                'message' => "Customer subscription successfully {$actionWord} to '{$newPlan['plan_name']}'."
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Database error changing subscription: ' . $e->getMessage()
            ];
        }
    }
}


/* ============================================================
   5. ACTIVATE / DEACTIVATE SUBSCRIPTION
   ============================================================ */

if (!function_exists('setSubscriptionStatus')) {
    /**
     * Activate, suspend, or cancel a subscription.
     *
     * @param int $subscriptionId
     * @param string $newStatus 'ACTIVE', 'SUSPENDED', 'CANCELLED', 'EXPIRED'
     * @param array $options Optional: ['notes', 'updated_by']
     * @return array
     */
    function setSubscriptionStatus(int $subscriptionId, string $status, array $options = []): array
    {
        $pdo = getDbConnection();

        if ($subscriptionId <= 0) {
            return ['success' => false, 'error' => 'Invalid subscription ID provided.'];
        }

        $targetStatus = strtoupper(trim($status));
        $validStatuses = ['ACTIVE', 'SUSPENDED', 'CANCELLED', 'EXPIRED'];
        if (!in_array($targetStatus, $validStatuses, true)) {
            return ['success' => false, 'error' => "Invalid status '{$status}'. Permitted: " . implode(', ', $validStatuses)];
        }

        // Retrieve existing record
        $stmt = $pdo->prepare("SELECT * FROM customer_subscriptions WHERE subscription_id = :id");
        $stmt->execute([':id' => $subscriptionId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            return ['success' => false, 'error' => 'Subscription record not found.'];
        }

        if (strtoupper($sub['status']) === $targetStatus) {
            return [
                'success' => true,
                'message' => "Subscription is already in status '{$targetStatus}'."
            ];
        }

        $updatedBy = !empty($options['updated_by']) ? (int)$options['updated_by'] : null;
        if (!$updatedBy) {
            $user = getAuthUser();
            $updatedBy = (int)($user['user_id'] ?? 0) ?: null;
        }

        $notes = $options['notes'] ?? "Status updated to {$targetStatus}";

        try {
            $pdo->beginTransaction();

            // If activating, ensure no OTHER active subscription exists for this customer
            if ($targetStatus === 'ACTIVE') {
                $stmtExpireOther = $pdo->prepare("
                    UPDATE customer_subscriptions
                    SET 
                        status = 'EXPIRED',
                        updated_at = CURRENT_TIMESTAMP,
                        updated_by = :updated_by
                    WHERE customer_id = :customer_id
                      AND subscription_id != :sub_id
                      AND UPPER(status) = 'ACTIVE'
                ");
                $stmtExpireOther->execute([
                    ':customer_id' => $sub['customer_id'],
                    ':sub_id' => $subscriptionId,
                    ':updated_by' => $updatedBy
                ]);
            }

            $stmtUpdate = $pdo->prepare("
                UPDATE customer_subscriptions
                SET 
                    status = :status,
                    notes = CASE 
                        WHEN notes IS NULL OR notes = '' THEN :notes::text 
                        ELSE notes || ' | ' || :notes::text 
                    END,
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = :updated_by
                WHERE subscription_id = :sub_id
            ");

            $stmtUpdate->execute([
                ':status' => $targetStatus,
                ':notes' => (string)$notes,
                ':updated_by' => $updatedBy,
                ':sub_id' => $subscriptionId
            ]);

            $pdo->commit();

            $auditAction = 'SUBSCRIPTION_CHANGED';
            if ($targetStatus === 'CANCELLED') {
                $auditAction = 'SUBSCRIPTION_CANCELLED';
            } elseif ($targetStatus === 'SUSPENDED') {
                $auditAction = 'SUBSCRIPTION_SUSPENDED';
            } elseif ($targetStatus === 'EXPIRED') {
                $auditAction = 'SUBSCRIPTION_EXPIRED';
            }

            logSubscriptionAudit($pdo, $updatedBy, $auditAction, $subscriptionId, [
                'status' => $sub['status']
            ], [
                'status' => $targetStatus,
                'customer_id' => $sub['customer_id'],
                'action' => $auditAction,
                'notes' => $notes
            ]);

            return [
                'success' => true,
                'subscription_id' => $subscriptionId,
                'status' => $targetStatus,
                'message' => "Subscription status successfully changed to '{$targetStatus}'."
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Database error updating subscription status: ' . $e->getMessage()
            ];
        }
    }
}


/* ============================================================
   11. AUTOMATIC DEFAULT FREE SUBSCRIPTION ASSIGNMENT
   ============================================================ */

if (!function_exists('assignDefaultCustomerSubscription')) {
    /**
     * Automatically assign the predefined default FREE subscription plan to a customer.
     * Enforces that the FREE plan is retrieved dynamically from subscription_plans (not hardcoded).
     *
     * @param int $customerId
     * @param int|null $assignedBy
     * @param array $options
     * @return array
     */
    function assignDefaultCustomerSubscription(int $customerId, ?int $assignedBy = null, array $options = []): array
    {
        $pdo = getDbConnection();

        if ($customerId <= 0) {
            return ['success' => false, 'error' => 'Invalid customer ID provided.'];
        }

        // Verify customer exists
        $stmtCust = $pdo->prepare("SELECT customer_id, customer_name, status FROM customers WHERE customer_id = :id");
        $stmtCust->execute([':id' => $customerId]);
        $customer = $stmtCust->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            return ['success' => false, 'error' => 'Customer record not found.'];
        }

        // Prevent duplicate subscriptions
        $existing = getCustomerActiveSubscription($customerId);
        if ($existing) {
            return [
                'success' => true,
                'already_assigned' => true,
                'subscription_id' => $existing['subscription_id'],
                'customer_id' => $customerId,
                'plan_code' => $existing['plan_code'],
                'plan_name' => $existing['plan_name'],
                'max_users' => $existing['max_users'],
                'status' => $existing['status'],
                'message' => "Customer already has an active subscription ('{$existing['plan_name']}')."
            ];
        }

        // Retrieve predefined FREE subscription plan from subscription_plans (never hardcoded)
        $freePlan = getSubscriptionPlanByCode('FREE');
        if (!$freePlan) {
            $stmtFallback = $pdo->query("SELECT plan_id FROM subscription_plans WHERE UPPER(plan_type) = 'FREE' OR max_users = 0 ORDER BY plan_id ASC LIMIT 1");
            $fallbackId = (int)$stmtFallback->fetchColumn();
            if ($fallbackId > 0) {
                $freePlan = getSubscriptionPlanById($fallbackId);
            }
        }

        if (!$freePlan) {
            return ['success' => false, 'error' => 'Default FREE subscription plan is not configured in database.'];
        }

        $startDate = !empty($options['start_date']) ? $options['start_date'] : date('Y-m-d');
        $endDate = !empty($options['end_date']) ? $options['end_date'] : null;
        $notes = $options['notes'] ?? 'Default FREE subscription automatically assigned upon customer creation';

        if (!$assignedBy) {
            $user = getAuthUser();
            $assignedBy = (int)($user['user_id'] ?? 0) ?: null;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO customer_subscriptions
                (
                    customer_id,
                    plan_id,
                    start_date,
                    end_date,
                    status,
                    assigned_by,
                    assigned_date,
                    notes,
                    created_at,
                    created_by,
                    updated_at,
                    updated_by
                )
                VALUES
                (
                    :customer_id,
                    :plan_id,
                    :start_date,
                    :end_date,
                    'ACTIVE',
                    :assigned_by,
                    CURRENT_TIMESTAMP,
                    :notes,
                    CURRENT_TIMESTAMP,
                    :created_by,
                    CURRENT_TIMESTAMP,
                    :updated_by
                )
                RETURNING subscription_id
            ");

            $stmt->execute([
                ':customer_id' => $customerId,
                ':plan_id'     => $freePlan['plan_id'],
                ':start_date'  => $startDate,
                ':end_date'    => $endDate,
                ':assigned_by' => $assignedBy,
                ':notes'       => $notes,
                ':created_by'  => $assignedBy,
                ':updated_by'  => $assignedBy
            ]);

            $subscriptionId = (int)$stmt->fetchColumn();

            // Log audit entry
            logSubscriptionAudit($pdo, $assignedBy, 'ASSIGN', $subscriptionId, null, [
                'customer_id' => $customerId,
                'plan_id'     => $freePlan['plan_id'],
                'plan_code'   => $freePlan['plan_code'],
                'plan_name'   => $freePlan['plan_name'],
                'max_users'   => $freePlan['max_users'],
                'is_default'  => true
            ]);

            return [
                'success'          => true,
                'already_assigned' => false,
                'subscription_id'  => $subscriptionId,
                'customer_id'      => $customerId,
                'plan_code'        => $freePlan['plan_code'],
                'plan_name'        => $freePlan['plan_name'],
                'max_users'        => $freePlan['max_users'],
                'status'           => 'ACTIVE',
                'message'          => 'Default FREE subscription assigned successfully.'
            ];

        } catch (PDOException $e) {
            return [
                'success' => false,
                'error' => 'Database error assigning default subscription: ' . $e->getMessage()
            ];
        }
    }
}


/* ============================================================
   12. SAFE EXISTING CUSTOMER BACKFILL MIGRATION
   ============================================================ */

if (!function_exists('backfillCustomerSubscriptions')) {
    /**
     * Inspects all existing customers and assigns subscriptions only where missing.
     * Mode 'appropriate':
     * - Customers with 0 active users -> FREE plan (max_users = 0)
     * - Customers with >0 active users -> smallest tier that accommodates them (e.g. STANDARD)
     * Mode 'all_free':
     * - Assigns FREE plan to all unassigned customers regardless of existing users
     *
     * @param string $mode 'appropriate' or 'all_free'
     * @param int|null $assignedBy
     * @return array Backfill summary and itemized results
     */
    function backfillCustomerSubscriptions(string $mode = 'appropriate', ?int $assignedBy = null): array
    {
        $pdo = getDbConnection();

        $customers = $pdo->query("
            SELECT customer_id, customer_code, customer_name, status 
            FROM customers 
            ORDER BY customer_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $assignedCount = 0;
        $skippedCount = 0;

        foreach ($customers as $c) {
            $cid = (int)$c['customer_id'];

            // 1. Check if already has active subscription
            $existing = getCustomerActiveSubscription($cid);
            if ($existing) {
                $skippedCount++;
                $results[] = [
                    'customer_id' => $cid,
                    'customer_code' => $c['customer_code'],
                    'customer_name' => $c['customer_name'],
                    'action' => 'SKIPPED',
                    'reason' => "Already has active subscription: {$existing['plan_name']} (max_users: {$existing['max_users']})",
                    'plan_code' => $existing['plan_code']
                ];
                continue;
            }

            // 2. Count active users
            $usage = getCustomerUserUsage($cid);
            $activeUsers = $usage['active_users'];

            if ($mode === 'all_free' || $activeUsers === 0) {
                // Assign FREE plan
                $res = assignDefaultCustomerSubscription($cid, $assignedBy, [
                    'notes' => "Backfill migration: Assigned default FREE subscription (active users: {$activeUsers})"
                ]);

                if ($res['success']) {
                    $assignedCount++;
                    $results[] = [
                        'customer_id' => $cid,
                        'customer_code' => $c['customer_code'],
                        'customer_name' => $c['customer_name'],
                        'action' => 'ASSIGNED_FREE',
                        'reason' => "Assigned default FREE plan (0 active users)",
                        'plan_code' => 'FREE',
                        'max_users' => $res['max_users'],
                        'subscription_id' => $res['subscription_id']
                    ];
                } else {
                    $results[] = [
                        'customer_id' => $cid,
                        'customer_code' => $c['customer_code'],
                        'customer_name' => $c['customer_name'],
                        'action' => 'FAILED',
                        'reason' => $res['error'] ?? 'Assignment failed'
                    ];
                }
            } else {
                // Customer has existing users; assign appropriate tier that can accommodate them
                $stmtPlan = $pdo->prepare("
                    SELECT plan_id, plan_code, plan_name, max_users 
                    FROM subscription_plans 
                    WHERE max_users >= :users AND UPPER(status) = 'ACTIVE' 
                    ORDER BY max_users ASC 
                    LIMIT 1
                ");
                $stmtPlan->execute([':users' => $activeUsers]);
                $targetPlan = $stmtPlan->fetch(PDO::FETCH_ASSOC);

                if (!$targetPlan) {
                    // Fallback to highest available active plan
                    $targetPlan = $pdo->query("SELECT plan_id, plan_code, plan_name, max_users FROM subscription_plans WHERE UPPER(status) = 'ACTIVE' ORDER BY max_users DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                }

                $res = assignSubscription($cid, (int)$targetPlan['plan_id'], [
                    'notes' => "Backfill migration: Assigned {$targetPlan['plan_name']} to accommodate {$activeUsers} existing active user(s)",
                    'assigned_by' => $assignedBy
                ]);

                if ($res['success']) {
                    $assignedCount++;
                    $results[] = [
                        'customer_id' => $cid,
                        'customer_code' => $c['customer_code'],
                        'customer_name' => $c['customer_name'],
                        'action' => 'ASSIGNED_APPROPRIATE',
                        'reason' => "Assigned {$targetPlan['plan_name']} (max_users: {$targetPlan['max_users']}) to cover {$activeUsers} existing active user(s)",
                        'plan_code' => $targetPlan['plan_code'],
                        'max_users' => (int)$targetPlan['max_users'],
                        'subscription_id' => $res['subscription_id']
                    ];
                } else {
                    $results[] = [
                        'customer_id' => $cid,
                        'customer_code' => $c['customer_code'],
                        'customer_name' => $c['customer_name'],
                        'action' => 'FAILED',
                        'reason' => $res['error'] ?? 'Assignment failed'
                    ];
                }
            }
        }

        return [
            'total_customers' => count($customers),
            'assigned' => $assignedCount,
            'skipped' => $skippedCount,
            'mode' => $mode,
            'details' => $results
        ];
    }
}


/* ============================================================
   11. SUBSCRIPTION PLAN MASTER MANAGEMENT (ADMINISTRATIVE)
   ============================================================ */

if (!function_exists('canUserManageSubscriptionPlans')) {
    /**
     * Check if authenticated user can manage subscription tiers.
     */
    function canUserManageSubscriptionPlans(?int $userId = null): bool
    {
        if (isPlatformAdmin($userId)) {
            return true;
        }
        if (function_exists('userHasMenuAccess') && userHasMenuAccess('SUBSCRIPTION_PLANS', $userId)) {
            return true;
        }
        $role = getCurrentUserRole($userId);
        $roleCode = strtoupper($role['role_code'] ?? '');
        return in_array($roleCode, ['PLATFORM_ADMIN', 'CMA_ADMIN'], true);
    }
}

if (!function_exists('createSubscriptionPlan')) {
    /**
     * Create a new subscription plan with uniqueness and limit validation.
     *
     * @param array $data Plan input parameters
     * @param int|null $userId User ID executing the action
     * @return array Result array with success status and details
     */
    function createSubscriptionPlan(array $data, ?int $userId = null): array
    {
        $planName = trim($data['plan_name'] ?? '');
        $planCode = strtoupper(trim($data['plan_code'] ?? ''));
        $description = trim($data['description'] ?? '');
        $planType = strtoupper(trim($data['plan_type'] ?? 'COMMERCIAL'));
        $price = isset($data['price']) ? (float)$data['price'] : 0.00;
        $currency = strtoupper(trim($data['currency'] ?? 'INR'));
        $billingCycle = strtoupper(trim($data['billing_cycle'] ?? 'MONTHLY'));
        $maxUsers = isset($data['max_users']) ? (int)$data['max_users'] : 0;
        $status = strtoupper(trim($data['status'] ?? 'ACTIVE'));

        if ($planName === '') {
            return ['success' => false, 'error' => 'Plan Name is required.'];
        }
        if ($planCode === '') {
            return ['success' => false, 'error' => 'Plan Code is required.'];
        }
        if (!preg_match('/^[A-Z0-9_-]{2,50}$/', $planCode)) {
            return ['success' => false, 'error' => 'Plan Code must be 2 to 50 uppercase alphanumeric characters (letters, numbers, hyphens, underscores).'];
        }
        if ($maxUsers < 0) {
            return ['success' => false, 'error' => 'Maximum users cannot be negative.'];
        }
        if ($price < 0) {
            return ['success' => false, 'error' => 'Price cannot be negative.'];
        }

        $validTypes = ['FREE', 'TRIAL', 'COMMERCIAL', 'ENTERPRISE', 'CUSTOM'];
        if (!in_array($planType, $validTypes, true)) {
            $planType = 'COMMERCIAL';
        }

        $validCycles = ['MONTHLY', 'QUARTERLY', 'HALF_YEARLY', 'ANNUAL', 'LIFETIME', 'CUSTOM'];
        if (!in_array($billingCycle, $validCycles, true)) {
            $billingCycle = 'MONTHLY';
        }

        $status = ($status === 'INACTIVE' || $status === 'Inactive') ? 'INACTIVE' : 'ACTIVE';

        $pdo = getDbConnection();

        // Enforce uniqueness of plan_code
        $checkStmt = $pdo->prepare("SELECT plan_id FROM subscription_plans WHERE UPPER(plan_code) = UPPER(:plan_code) LIMIT 1");
        $checkStmt->execute([':plan_code' => $planCode]);
        if ($checkStmt->fetchColumn()) {
            return ['success' => false, 'error' => "Plan Code '{$planCode}' is already in use. Plan code must be unique."];
        }

        $limitsConfig = $data['limits_config'] ?? [];
        if (is_string($limitsConfig)) {
            $decoded = json_decode($limitsConfig, true);
            $limitsConfig = is_array($decoded) ? $decoded : [];
        }

        $stmt = $pdo->prepare("
            INSERT INTO subscription_plans (
                plan_code,
                plan_name,
                description,
                plan_type,
                price,
                currency,
                billing_cycle,
                max_users,
                limits_config,
                status,
                created_by,
                updated_by,
                created_at,
                updated_at
            ) VALUES (
                :plan_code,
                :plan_name,
                :description,
                :plan_type,
                :price,
                :currency,
                :billing_cycle,
                :max_users,
                :limits_config::jsonb,
                :status,
                :created_by,
                :updated_by,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            RETURNING plan_id
        ");

        $stmt->execute([
            ':plan_code' => $planCode,
            ':plan_name' => $planName,
            ':description' => $description !== '' ? $description : null,
            ':plan_type' => $planType,
            ':price' => $price,
            ':currency' => $currency ?: 'INR',
            ':billing_cycle' => $billingCycle,
            ':max_users' => $maxUsers,
            ':limits_config' => json_encode($limitsConfig, JSON_UNESCAPED_UNICODE),
            ':status' => $status,
            ':created_by' => ($userId && $userId > 0) ? $userId : null,
            ':updated_by' => ($userId && $userId > 0) ? $userId : null
        ]);

        $newPlanId = (int)$stmt->fetchColumn();

        return [
            'success' => true,
            'plan_id' => $newPlanId,
            'message' => "Subscription plan '{$planName}' created successfully."
        ];
    }
}

if (!function_exists('updateSubscriptionPlan')) {
    /**
     * Update an existing subscription plan with validations.
     *
     * @param int $planId ID of the plan to update
     * @param array $data Updated fields
     * @param int|null $userId User ID executing the update
     * @return array
     */
    function updateSubscriptionPlan(int $planId, array $data, ?int $userId = null): array
    {
        if ($planId <= 0) {
            return ['success' => false, 'error' => 'Invalid plan ID.'];
        }

        $existing = getSubscriptionPlanById($planId);
        if (!$existing) {
            return ['success' => false, 'error' => 'Subscription plan not found.'];
        }

        $isSystemFreePlan = (strtoupper($existing['plan_code']) === 'FREE' || $planId === 1);

        $planName = trim($data['plan_name'] ?? $existing['plan_name']);
        $planCode = strtoupper(trim($data['plan_code'] ?? $existing['plan_code']));
        $description = isset($data['description']) ? trim($data['description']) : ($existing['description'] ?? '');
        $planType = strtoupper(trim($data['plan_type'] ?? $existing['plan_type']));
        $price = isset($data['price']) ? (float)$data['price'] : (float)$existing['price'];
        $currency = strtoupper(trim($data['currency'] ?? $existing['currency']));
        $billingCycle = strtoupper(trim($data['billing_cycle'] ?? $existing['billing_cycle']));
        $maxUsers = isset($data['max_users']) ? (int)$data['max_users'] : (int)$existing['max_users'];
        $status = strtoupper(trim($data['status'] ?? $existing['status']));

        if ($planName === '') {
            return ['success' => false, 'error' => 'Plan Name cannot be empty.'];
        }
        if ($planCode === '') {
            return ['success' => false, 'error' => 'Plan Code cannot be empty.'];
        }
        if (!preg_match('/^[A-Z0-9_-]{2,50}$/', $planCode)) {
            return ['success' => false, 'error' => 'Plan Code must be 2 to 50 uppercase alphanumeric characters (letters, numbers, hyphens, underscores).'];
        }
        if ($maxUsers < 0) {
            return ['success' => false, 'error' => 'Maximum users cannot be negative.'];
        }
        if ($price < 0) {
            return ['success' => false, 'error' => 'Price cannot be negative.'];
        }

        // System Free Plan Protection: Keep Free plan constants intact
        if ($isSystemFreePlan) {
            $planCode = 'FREE';
            $planType = 'FREE';
            $price = 0.00;
            $maxUsers = 0;
            $status = 'ACTIVE';
        }

        $validTypes = ['FREE', 'TRIAL', 'COMMERCIAL', 'ENTERPRISE', 'CUSTOM'];
        if (!in_array($planType, $validTypes, true)) {
            $planType = $existing['plan_type'];
        }

        $validCycles = ['MONTHLY', 'QUARTERLY', 'HALF_YEARLY', 'ANNUAL', 'LIFETIME', 'CUSTOM'];
        if (!in_array($billingCycle, $validCycles, true)) {
            $billingCycle = $existing['billing_cycle'];
        }

        $status = ($status === 'INACTIVE' || $status === 'Inactive') ? 'INACTIVE' : 'ACTIVE';

        $pdo = getDbConnection();

        // Enforce uniqueness of plan_code excluding self
        $checkStmt = $pdo->prepare("SELECT plan_id FROM subscription_plans WHERE UPPER(plan_code) = UPPER(:plan_code) AND plan_id != :plan_id LIMIT 1");
        $checkStmt->execute([':plan_code' => $planCode, ':plan_id' => $planId]);
        if ($checkStmt->fetchColumn()) {
            return ['success' => false, 'error' => "Plan Code '{$planCode}' is already in use by another plan."];
        }

        $limitsConfig = isset($data['limits_config']) ? $data['limits_config'] : $existing['limits_config'];
        if (is_string($limitsConfig)) {
            $decoded = json_decode($limitsConfig, true);
            $limitsConfig = is_array($decoded) ? $decoded : [];
        }

        $stmt = $pdo->prepare("
            UPDATE subscription_plans
            SET
                plan_code = :plan_code,
                plan_name = :plan_name,
                description = :description,
                plan_type = :plan_type,
                price = :price,
                currency = :currency,
                billing_cycle = :billing_cycle,
                max_users = :max_users,
                limits_config = :limits_config::jsonb,
                status = :status,
                updated_by = :updated_by,
                updated_at = CURRENT_TIMESTAMP
            WHERE plan_id = :plan_id
        ");

        $stmt->execute([
            ':plan_code' => $planCode,
            ':plan_name' => $planName,
            ':description' => $description !== '' ? $description : null,
            ':plan_type' => $planType,
            ':price' => $price,
            ':currency' => $currency ?: 'INR',
            ':billing_cycle' => $billingCycle,
            ':max_users' => $maxUsers,
            ':limits_config' => json_encode($limitsConfig, JSON_UNESCAPED_UNICODE),
            ':status' => $status,
            ':updated_by' => ($userId && $userId > 0) ? $userId : null,
            ':plan_id' => $planId
        ]);

        return [
            'success' => true,
            'plan_id' => $planId,
            'message' => "Subscription plan '{$planName}' updated successfully."
        ];
    }
}

if (!function_exists('setSubscriptionPlanStatus')) {
    /**
     * Activate or deactivate a subscription plan.
     *
     * @param int $planId ID of the plan
     * @param string $status New status ('ACTIVE' or 'INACTIVE')
     * @param int|null $userId User ID executing the action
     * @return array
     */
    function setSubscriptionPlanStatus(int $planId, string $status, ?int $userId = null): array
    {
        if ($planId <= 0) {
            return ['success' => false, 'error' => 'Invalid plan ID.'];
        }

        $existing = getSubscriptionPlanById($planId);
        if (!$existing) {
            return ['success' => false, 'error' => 'Subscription plan not found.'];
        }

        $normStatus = (strtoupper(trim($status)) === 'ACTIVE') ? 'ACTIVE' : 'INACTIVE';

        // Protection: Free plan must remain active
        if ((strtoupper($existing['plan_code']) === 'FREE' || $planId === 1) && $normStatus === 'INACTIVE') {
            return ['success' => false, 'error' => 'The system-required Free Plan cannot be deactivated.'];
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            UPDATE subscription_plans
            SET status = :status,
                updated_by = :updated_by,
                updated_at = CURRENT_TIMESTAMP
            WHERE plan_id = :plan_id
        ");
        $stmt->execute([
            ':status' => $normStatus,
            ':updated_by' => ($userId && $userId > 0) ? $userId : null,
            ':plan_id' => $planId
        ]);

        $actionVerb = ($normStatus === 'ACTIVE') ? 'activated' : 'deactivated';
        return [
            'success' => true,
            'plan_id' => $planId,
            'status' => $normStatus,
            'message' => "Plan '{$existing['plan_name']}' has been {$actionVerb} successfully."
        ];
    }
}

if (!function_exists('deleteSubscriptionPlan')) {
    /**
     * Delete a subscription plan if not system-required or referenced by customers.
     *
     * @param int $planId ID of the plan to delete
     * @param int|null $userId User ID executing the action
     * @return array
     */
    function deleteSubscriptionPlan(int $planId, ?int $userId = null): array
    {
        if ($planId <= 0) {
            return ['success' => false, 'error' => 'Invalid plan ID.'];
        }

        $existing = getSubscriptionPlanById($planId);
        if (!$existing) {
            return ['success' => false, 'error' => 'Subscription plan not found.'];
        }

        // STRICT SYSTEM RULE: Never allow deletion of the system-required Free plan
        if (strtoupper($existing['plan_code']) === 'FREE' || $planId === 1) {
            return ['success' => false, 'error' => 'Deletion Forbidden: The system-required Free Plan cannot be deleted.'];
        }

        $pdo = getDbConnection();

        // Check if any customer subscriptions reference this plan
        $checkStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_subs,
                COUNT(CASE WHEN UPPER(status) = 'ACTIVE' THEN 1 END) AS active_subs
            FROM customer_subscriptions
            WHERE plan_id = :plan_id
        ");
        $checkStmt->execute([':plan_id' => $planId]);
        $subCounts = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $activeSubs = (int)($subCounts['active_subs'] ?? 0);
        $totalSubs = (int)($subCounts['total_subs'] ?? 0);

        if ($activeSubs > 0) {
            return [
                'success' => false,
                'error' => "Cannot delete plan '{$existing['plan_name']}': It is currently active on {$activeSubs} customer subscription(s). Please reassign those customers or deactivate the plan instead."
            ];
        }

        if ($totalSubs > 0) {
            return [
                'success' => false,
                'error' => "Cannot delete plan '{$existing['plan_name']}': It is referenced by {$totalSubs} historical subscription record(s). To preserve audit integrity, please deactivate the plan instead."
            ];
        }

        $stmt = $pdo->prepare("DELETE FROM subscription_plans WHERE plan_id = :plan_id");
        $stmt->execute([':plan_id' => $planId]);

        return [
            'success' => true,
            'plan_id' => $planId,
            'message' => "Plan '{$existing['plan_name']}' has been permanently deleted."
        ];
    }
}

