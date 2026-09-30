<?php
/**
 * Cost360 - Authentication, RBAC & Organization Access Helper
 *
 * Handles:
 * - Authentication
 * - Current logged-in user
 * - User role
 * - Authorized menus
 * - Permissions
 * - Organization/company access
 * - Active company session
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';


/* ============================================================
   AUTHENTICATION
   ============================================================ */

if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']) &&
               !empty($_SESSION['user_id']);
    }
}


if (!function_exists('requireAuth')) {
    function requireAuth()
    {
        /*
         * Authentication is not valid merely because a session contains
         * user_id. The user's account and assigned role must still be active.
         * This check runs on every protected request so deactivating a role
         * immediately invalidates the existing session on its next request.
         */
        if (isLoggedIn()) {
            $userId = $_SESSION['user_id'] ?? null;
            $role = $userId ? getCurrentUserRole($userId) : null;

            if (!$role) {
                error_log('Authentication rejected: user has no active role. user_id=' . ($userId ?? 'unknown'));

                $_SESSION = [];

                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params['path'],
                        $params['domain'],
                        $params['secure'],
                        $params['httponly']
                    );
                }

                session_destroy();

                $script = $_SERVER['SCRIPT_NAME'] ?? '';
                $loginUrl = 'login.php';

                if (
                    strpos($script, '/admin/') !== false ||
                    strpos($script, '/administration/') !== false ||
                    strpos($script, '/organization/') !== false
                ) {
                    if (
                        preg_match('#/(?:admin|administration|organization)/[^/]+/#i', $script) ||
                        preg_match('#/(?:admin|administration|organization)/[^/]+/index\.php#i', $script)
                    ) {
                        $loginUrl = '../../login.php';
                    } else {
                        $loginUrl = '../login.php';
                    }
                }

                $separator = strpos($loginUrl, '?') !== false ? '&' : '?';
                header('Location: ' . $loginUrl . $separator . 'error=role_inactive');
                exit;
            }

            /* Keep the session role information synchronized with the DB. */
            $_SESSION['role_id'] = $role['role_id'] ?? null;
            $_SESSION['role_code'] = $role['role_code'] ?? '';
            $_SESSION['role_name'] = $role['role_name'] ?? '';
            $_SESSION['role_status'] = $role['status'] ?? 'Active';
        }

        if (!isLoggedIn()) {

            $script = $_SERVER['SCRIPT_NAME'] ?? '';

            $loginUrl = 'login.php';

            if (
                strpos($script, '/admin/') !== false ||
                strpos($script, '/administration/') !== false ||
                strpos($script, '/organization/') !== false
            ) {

                if (
                    preg_match(
                        '#/(?:admin|administration|organization)/[^/]+/#i',
                        $script
                    ) ||
                    preg_match(
                        '#/(?:admin|administration|organization)/[^/]+/index\.php#i',
                        $script
                    )
                ) {
                    $loginUrl = '../../login.php';
                } else {
                    $loginUrl = '../login.php';
                }
            }

            header('Location: ' . $loginUrl);
            exit;
        }
    }
}


/* ============================================================
   ACTIVE COMPANY REQUIRED
   ============================================================ */

/* ============================================================
   ACTIVE COMPANY REQUIRED
   ============================================================ */

if (!function_exists('requireActiveCompany')) {

    function requireActiveCompany()
    {
        return requireActiveOrganizationContext();
    }
}

if (!function_exists('requireActiveOrganizationContext')) {

    function requireActiveOrganizationContext()
    {
        requireAuth();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            header('Location: login.php');
            exit;
        }

        /*
         * Platform Admin global mode support.
         * If Admin has not selected a specific company/division/etc.,
         * set default Global Context ('ALL') so Admin is NEVER blocked or shown "No Company Access".
         */
        if (isPlatformAdmin($userId)) {
            if (!isset($_SESSION['active_company_id']) || empty($_SESSION['active_company_id'])) {
                $_SESSION['active_customer_id'] = 'ALL';
                $_SESSION['active_customer_code'] = 'ALL';
                $_SESSION['active_customer_name'] = 'All Customers';
                $_SESSION['active_company_id'] = 'ALL';
                $_SESSION['active_company_code'] = 'ALL';
                $_SESSION['active_company_name'] = 'All Companies';
                $_SESSION['active_division_id'] = 'ALL';
                $_SESSION['active_division_code'] = 'ALL';
                $_SESSION['active_division_name'] = 'All Divisions';
                $_SESSION['active_plant_id'] = 'ALL';
                $_SESSION['active_plant_code'] = 'ALL';
                $_SESSION['active_plant_name'] = 'All Plants';
                $_SESSION['active_warehouse_id'] = 'ALL';
                $_SESSION['active_warehouse_code'] = 'ALL';
                $_SESSION['active_warehouse_name'] = 'All Warehouses';
                
                // Get default active period
                $periods = getUserAccessiblePeriods();
                if (!empty($periods)) {
                    $_SESSION['active_period_id'] = $periods[0]['period_id'];
                    $_SESSION['active_period_name'] = $periods[0]['period_name'];
                    $_SESSION['active_period_start'] = $periods[0]['start_date'];
                    $_SESSION['active_period_end'] = $periods[0]['end_date'];
                } else {
                    $_SESSION['active_period_id'] = 'ALL';
                    $_SESSION['active_period_name'] = 'All Periods';
                }
            }
            return true;
        }

        /*
         * CMA Admin customer context support.
         * Scoped to their tenant/customer. If companies are not yet created or selected,
         * initialize customer context so CMA Admin is never locked out of administration.
         */
        if (isCmaAdmin($userId)) {
            $pdo = getDbConnection();
            $cid = getCmaAdminCustomerId($userId);
            if ($cid) {
                if (empty($_SESSION['active_customer_id']) || (string)$_SESSION['active_customer_id'] !== (string)$cid) {
                    $cStmt = $pdo->prepare("SELECT customer_id, customer_code, customer_name FROM customers WHERE customer_id = :cid");
                    $cStmt->execute([':cid' => $cid]);
                    $cust = $cStmt->fetch(PDO::FETCH_ASSOC);
                    if ($cust) {
                        $_SESSION['active_customer_id'] = $cust['customer_id'];
                        $_SESSION['active_customer_code'] = $cust['customer_code'];
                        $_SESSION['active_customer_name'] = $cust['customer_name'];
                    }
                }
            }

            // Check if companies exist for this customer
            $companies = getUserAccessibleCompanies($userId);
            if (empty($companies)) {
                // Customer has no companies yet - allow CMA Admin to manage customer & organization
                if (!isset($_SESSION['active_company_id']) || empty($_SESSION['active_company_id'])) {
                    $_SESSION['active_company_id'] = 'ALL';
                    $_SESSION['active_company_code'] = 'ALL';
                    $_SESSION['active_company_name'] = $_SESSION['active_customer_name'] ?? 'Customer Organization';
                    $_SESSION['active_division_id'] = 'ALL';
                    $_SESSION['active_division_code'] = 'ALL';
                    $_SESSION['active_division_name'] = 'All Divisions';
                    $_SESSION['active_plant_id'] = 'ALL';
                    $_SESSION['active_plant_code'] = 'ALL';
                    $_SESSION['active_plant_name'] = 'All Plants';
                    $_SESSION['active_warehouse_id'] = 'ALL';
                    $_SESSION['active_warehouse_code'] = 'ALL';
                    $_SESSION['active_warehouse_name'] = 'All Warehouses';
                    $_SESSION['active_period_id'] = 'ALL';
                    $_SESSION['active_period_name'] = 'All Periods';
                }
                return true;
            } else if (!empty($companies) && empty($_SESSION['active_company_id'])) {
                setActiveCompany($companies[0]['company_id']);
            }
            if (!empty($_SESSION['active_company_id'])) {
                // Set division / plant defaults if not set
                if (empty($_SESSION['active_division_id'])) {
                    $divs = getUserAccessibleDivisions($userId, $_SESSION['active_company_id']);
                    if (!empty($divs)) {
                        setActiveDivision($divs[0]['division_id']);
                    } else {
                        $_SESSION['active_division_id'] = 'ALL';
                        $_SESSION['active_division_name'] = 'All Divisions';
                    }
                }
                if (empty($_SESSION['active_plant_id'])) {
                    $plants = getUserAccessiblePlants($userId, $_SESSION['active_company_id'], $_SESSION['active_division_id'] ?? null);
                    if (!empty($plants)) {
                        setActivePlant($plants[0]['plant_id']);
                    } else {
                        $_SESSION['active_plant_id'] = 'ALL';
                        $_SESSION['active_plant_name'] = 'All Plants';
                    }
                }
                if (empty($_SESSION['active_period_id'])) {
                    $periods = getUserAccessiblePeriods($_SESSION['active_company_id']);
                    if (!empty($periods)) {
                        setActivePeriod($periods[0]['period_id']);
                    } else {
                        $_SESSION['active_period_id'] = 'ALL';
                        $_SESSION['active_period_name'] = 'All Periods';
                    }
                }
                return true;
            }
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $prefix = '';
        if (
            strpos($script, '/admin/') !== false ||
            strpos($script, '/administration/') !== false ||
            strpos($script, '/organization/') !== false
        ) {
            if (
                preg_match('#/(?:admin|administration|organization)/[^/]+/#i', $script) ||
                preg_match('#/(?:admin|administration|organization)/[^/]+/index\.php#i', $script)
            ) {
                $prefix = '../../';
            } else {
                $prefix = '../';
            }
        }

        if (empty($_SESSION['active_company_id'])) {
            header('Location: ' . $prefix . 'company-selection.php');
            exit;
        }

        if (empty($_SESSION['active_division_id'])) {
            header('Location: ' . $prefix . 'division-selection.php');
            exit;
        }

        $hasOperationalLocation = !empty($_SESSION['active_plant_id']) || !empty($_SESSION['active_warehouse_id']);
        if (!$hasOperationalLocation) {
            header('Location: ' . $prefix . 'organization-selection.php');
            exit;
        }

        if (empty($_SESSION['active_period_id'])) {
            header('Location: ' . $prefix . 'period-selection.php');
            exit;
        }

        return true;
    }
}



/* ============================================================
   PASSWORD FUNCTIONS
   ============================================================ */

if (!function_exists('hashPassword')) {

    function hashPassword($password)
    {
        return password_hash(
            $password,
            PASSWORD_DEFAULT
        );
    }
}


if (!function_exists('verifyPassword')) {

    function verifyPassword($password, $hash)
    {
        return password_verify(
            $password,
            $hash
        );
    }
}


/* ============================================================
   CURRENT USER
   ============================================================ */

if (!function_exists('getAuthUser')) {

    function getAuthUser()
    {
        if (!isLoggedIn()) {
            return null;
        }

        static $cachedUser = null;

        if ($cachedUser !== null) {
            return $cachedUser;
        }

        if (
            isset($_SESSION['auth_cache']['user']) &&
            !empty($_SESSION['auth_cache']['user'])
        ) {

            $cachedUser =
                $_SESSION['auth_cache']['user'];

            return $cachedUser;
        }

        try {

            $pdo = getDbConnection();

            $stmt = $pdo->prepare('
                SELECT
                    user_id,
                    username,
                    email,
                    first_name,
                    last_name,
                    phone AS mobile_number,
                    status,
                    is_email_verified,
                    last_login_at,
                    created_at
                FROM users
                WHERE user_id = :user_id
                LIMIT 1
            ');

            $stmt->execute([
                ':user_id' => $_SESSION['user_id']
            ]);

            $cachedUser =
                $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cachedUser) {

                $_SESSION['auth_cache']['user'] =
                    $cachedUser;
            }

            return $cachedUser;

        } catch (Exception $e) {

            error_log(
                'Error fetching auth user: ' .
                $e->getMessage()
            );

            return [
                'user_id' =>
                    $_SESSION['user_id'] ?? null,

                'email' =>
                    $_SESSION['email'] ?? '',

                'username' =>
                    $_SESSION['username'] ?? '',

                'first_name' =>
                    $_SESSION['first_name'] ?? '',

                'last_name' => '',

                'status' => 'ACTIVE'
            ];
        }
    }
}


/* ============================================================
   CURRENT USER ROLE
   ============================================================ */

if (!function_exists('getCurrentUserRole')) {

    function getCurrentUserRole($userId = null)
    {
        if ($userId === null) {
            $userId =
                $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return null;
        }

        static $cachedRolesMap = [];

        if (isset($cachedRolesMap[$userId])) {
            return $cachedRolesMap[$userId];
        }

        try {

            $pdo = getDbConnection();

            $stmt = $pdo->prepare('
                SELECT
                    r.role_id,
                    r.role_code,
                    r.role_name,
                    r.description,
                    r.status

                FROM roles r

                INNER JOIN user_roles ur
                    ON r.role_id = ur.role_id

                WHERE ur.user_id = :user_id
                  AND r.status = \'Active\'
                  AND ur.status = \'Active\'

                ORDER BY r.role_id ASC

                LIMIT 1
            ');

            $stmt->execute([
                ':user_id' => $userId
            ]);

            $role =
                $stmt->fetch(PDO::FETCH_ASSOC);

            $cachedRolesMap[$userId] =
                $role ?: null;

            return $role;

        } catch (Exception $e) {

            error_log(
                'Error fetching user role: ' .
                $e->getMessage()
            );

            return null;
        }
    }
}



/* ============================================================
   PLATFORM ADMIN CHECK
   ============================================================ */

if (!function_exists('isPlatformAdmin')) {

    function isPlatformAdmin($userId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return false;
        }

        $role = getCurrentUserRole($userId);

        if (!$role) {
            return false;
        }

        /*
         * Platform administrator is identified by the role.
         *
         * Keep this role-based rather than checking an email
         * such as admin@cost360.com.
         */

        $roleCode = strtoupper(
            trim($role['role_code'] ?? '')
        );

        $roleName = strtoupper(
            trim($role['role_name'] ?? '')
        );

        return in_array(
            $roleCode,
            [
                'PLATFORM_ADMIN',
                'SUPER_ADMIN',
                'ADMIN'
            ],
            true
        )
        ||
        in_array(
            $roleName,
            [
                'PLATFORM ADMIN',
                'SUPER ADMIN',
                'ADMIN',
                'SYSTEM ADMINISTRATOR'
            ],
            true
        );
    }
}

/* ============================================================
   CMA ADMIN CHECK
   ============================================================ */

if (!function_exists('isCmaAdmin')) {

    function isCmaAdmin($userId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return false;
        }

        $role = getCurrentUserRole($userId);

        if (!$role) {
            return false;
        }

        $roleCode = strtoupper(
            trim($role['role_code'] ?? '')
        );

        $roleName = strtoupper(
            trim($role['role_name'] ?? '')
        );

        return in_array(
            $roleCode,
            [
                'CMA_ADMIN',
                'CMA'
            ],
            true
        )
        ||
        in_array(
            $roleName,
            [
                'CMA ADMIN',
                'CMA / COST ADMINISTRATORS',
                'COST ADMINISTRATOR'
            ],
            true
        );
    }
}

if (!function_exists('getCmaAdminCustomerId')) {

    function getCmaAdminCustomerId($userId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        if (!$userId) return null;

        if (isset($_SESSION['active_customer_id']) && is_numeric($_SESSION['active_customer_id'])) {
            return (int)$_SESSION['active_customer_id'];
        }

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("
                SELECT customer_id 
                FROM user_organization_access 
                WHERE user_id = :uid 
                  AND customer_id IS NOT NULL 
                  AND UPPER(status) = 'ACTIVE'
                ORDER BY access_id ASC 
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $cid = $stmt->fetchColumn();
            return $cid ? (int)$cid : null;
        } catch (Throwable $e) {
            error_log('Error getting CMA Admin customer ID: ' . $e->getMessage());
            return null;
        }
    }
}

/* ============================================================
   ORGANIZATION ACCESS
   ============================================================ */

/**
 * Get all companies accessible by a user.
 *
 * Access can originate from:
 *
 * 1. Customer level
 * 2. Company level
 * 3. Division level
 * 4. Plant level
 * 5. Warehouse level
 *
 * Company is therefore resolved through the organization hierarchy.
 */

/* ============================================================
   CUSTOMERS ACCESS
   ============================================================ */

if (!function_exists('getUserAccessibleCustomers')) {
    function getUserAccessibleCustomers($userId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        if (!$userId) {
            return [];
        }

        try {
            $pdo = getDbConnection();

            if (isPlatformAdmin($userId)) {
                $stmt = $pdo->prepare('
                    SELECT customer_id, customer_code, customer_name, status
                    FROM customers
                    WHERE UPPER(status) = \'ACTIVE\'
                    ORDER BY customer_name ASC, customer_id ASC
                ');
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            // Direct customer scope assigned in user_organization_access
            $stmtDirect = $pdo->prepare("
                SELECT DISTINCT uoa.customer_id
                FROM user_organization_access uoa
                WHERE uoa.user_id = :user_id
                  AND UPPER(uoa.status) = 'ACTIVE'
                  AND uoa.customer_id IS NOT NULL
                  AND CURRENT_DATE >= uoa.valid_from
                  AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
            ");
            $stmtDirect->execute([':user_id' => $userId]);
            $directCustIds = $stmtDirect->fetchAll(PDO::FETCH_COLUMN) ?: [];

            // Indirect customer scope from accessible companies
            $companies = getUserAccessibleCompanies($userId);
            $compCustIds = !empty($companies) ? array_filter(array_unique(array_column($companies, 'customer_id'))) : [];

            $allCustIds = array_unique(array_merge($directCustIds, $compCustIds));
            if (empty($allCustIds)) return [];

            $inClause = implode(',', array_map('intval', $allCustIds));
            $stmt = $pdo->prepare("
                SELECT customer_id, customer_code, customer_name, status
                FROM customers
                WHERE customer_id IN ($inClause) AND UPPER(status) = 'ACTIVE'
                ORDER BY customer_name ASC, customer_id ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Error fetching accessible customers: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('userCanAccessCustomer')) {
    function userCanAccessCustomer($userId, $customerId)
    {
        if (!$userId || !$customerId) return false;
        if ((string)$customerId === 'ALL' && isPlatformAdmin($userId)) return true;
        $customers = getUserAccessibleCustomers($userId);
        foreach ($customers as $c) {
            if ((string)$c['customer_id'] === (string)$customerId) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('setActiveCustomer')) {
    function setActiveCustomer($customerId)
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId || !$customerId) return false;

        if ((string)$customerId === 'ALL' && isPlatformAdmin($userId)) {
            $_SESSION['active_customer_id'] = 'ALL';
            $_SESSION['active_customer_code'] = 'ALL';
            $_SESSION['active_customer_name'] = 'All Customers';
            clearActiveCompany();
            return true;
        }

        if (!userCanAccessCustomer($userId, $customerId)) return false;

        $customers = getUserAccessibleCustomers($userId);
        foreach ($customers as $c) {
            if ((string)$c['customer_id'] === (string)$customerId) {
                if (isset($_SESSION['active_customer_id']) && (string)$_SESSION['active_customer_id'] !== (string)$c['customer_id']) {
                    clearActiveCompany();
                }
                $_SESSION['active_customer_id'] = $c['customer_id'];
                $_SESSION['active_customer_code'] = $c['customer_code'];
                $_SESSION['active_customer_name'] = $c['customer_name'];
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('getActiveCustomer')) {
    function getActiveCustomer()
    {
        if (!isset($_SESSION['active_customer_id'])) return null;
        return [
            'customer_id' => $_SESSION['active_customer_id'],
            'customer_code' => $_SESSION['active_customer_code'] ?? '',
            'customer_name' => $_SESSION['active_customer_name'] ?? ''
        ];
    }
}

if (!function_exists('clearActiveCustomer')) {
    function clearActiveCustomer()
    {
        unset(
            $_SESSION['active_customer_id'],
            $_SESSION['active_customer_code'],
            $_SESSION['active_customer_name']
        );
        clearActiveCompany();
    }
}

if (!function_exists('getUserAccessibleCompanies')) {

    function getUserAccessibleCompanies($userId = null, $customerId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return [];
        }

        if ($customerId === null && isset($_SESSION['active_customer_id'])) {
            $customerId = $_SESSION['active_customer_id'];
        }

        try {

            $pdo = getDbConnection();

            if (isPlatformAdmin($userId)) {
                $sql = '
                    SELECT DISTINCT
                        c.company_id,
                        c.company_code,
                        c.company_name,
                        c.customer_id,
                        cust.customer_name,
                        cust.customer_code,
                        c.address,
                        c.status
                    FROM companies c
                    INNER JOIN customers cust ON cust.customer_id = c.customer_id
                    WHERE UPPER(c.status) = \'ACTIVE\'
                      AND UPPER(cust.status) = \'ACTIVE\'
                ';
                $params = [];
                if ($customerId !== null && $customerId !== '' && (string)$customerId !== 'ALL') {
                    $sql .= ' AND c.customer_id = :customer_id';
                    $params[':customer_id'] = $customerId;
                }
                $sql .= ' ORDER BY c.company_name ASC, c.company_id ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $sql = '
                SELECT DISTINCT
                    c.company_id,
                    c.company_code,
                    c.company_name,
                    c.customer_id,
                    cust.customer_name,
                    cust.customer_code,
                    c.address,
                    c.status
                FROM companies c
                INNER JOIN customers cust ON cust.customer_id = c.customer_id
                INNER JOIN user_organization_access uoa
                    ON (
                        (uoa.customer_id = c.customer_id AND uoa.company_id IS NULL AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                        OR (uoa.company_id = c.company_id)
                        OR (uoa.division_id IS NOT NULL AND EXISTS (SELECT 1 FROM divisions d WHERE d.division_id = uoa.division_id AND d.company_id = c.company_id))
                        OR (uoa.plant_id IS NOT NULL AND EXISTS (SELECT 1 FROM plants p INNER JOIN divisions d ON d.division_id = p.division_id WHERE p.plant_id = uoa.plant_id AND d.company_id = c.company_id))
                        OR (uoa.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses w INNER JOIN plants p ON p.plant_id = w.plant_id INNER JOIN divisions d ON d.division_id = p.division_id WHERE w.warehouse_id = uoa.warehouse_id AND d.company_id = c.company_id))
                    )
                WHERE uoa.user_id = :user_id
                  AND UPPER(uoa.status) = \'ACTIVE\'
                  AND CURRENT_DATE >= uoa.valid_from
                  AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
                  AND UPPER(c.status) = \'ACTIVE\'
                  AND UPPER(cust.status) = \'ACTIVE\'
            ';
            $params = [':user_id' => $userId];
            if ($customerId !== null && $customerId !== '' && (string)$customerId !== 'ALL') {
                $sql .= ' AND c.customer_id = :customer_id';
                $params[':customer_id'] = $customerId;
            }
            $sql .= ' ORDER BY c.company_name ASC, c.company_id ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        } catch (Throwable $e) {
            error_log('Error fetching accessible companies: ' . $e->getMessage());
            return [];
        }
    }
}


/**
 * Check whether a user can access a particular company.
 *
 * IMPORTANT:
 * Never trust company_id supplied by the browser.
 */

if (!function_exists('userCanAccessCompany')) {

    function userCanAccessCompany(
        $userId,
        $companyId
    ) {

        if (!$userId || !$companyId) {
            return false;
        }

        $companies =
            getUserAccessibleCompanies($userId);

        foreach ($companies as $company) {

            if (
                (string)$company['company_id'] ===
                (string)$companyId
            ) {
                return true;
            }
        }

        return false;
    }
}


/* ============================================================
   ACTIVE COMPANY
   ============================================================ */

if (!function_exists('setActiveCompany')) {

    function setActiveCompany($companyId)
    {
        $userId =
            $_SESSION['user_id'] ?? null;

        if (!$userId || !$companyId) {
            return false;
        }

        /*
         * SECURITY CHECK
         *
         * Only allow companies assigned to the user.
         */

        if (
            !userCanAccessCompany(
                $userId,
                $companyId
            )
        ) {

            return false;
        }

        $companies =
            getUserAccessibleCompanies($userId);

        foreach ($companies as $company) {

            if (
                (string)$company['company_id'] ===
                (string)$companyId
            ) {

                if (
                    isset($_SESSION['active_company_id']) &&
                    (string)$_SESSION['active_company_id'] !== (string)$company['company_id']
                ) {
                    clearActiveDivision();
                }

                $_SESSION['active_company_id'] =
                    $company['company_id'];

                $_SESSION['active_company_code'] =
                    $company['company_code'];

                $_SESSION['active_company_name'] =
                    $company['company_name'];

                $_SESSION['active_customer_id'] =
                    $company['customer_id'];

                try {
                    $pdo = getDbConnection();
                    $stmt = $pdo->prepare('SELECT customer_code, customer_name FROM customers WHERE customer_id = :cust_id LIMIT 1');
                    $stmt->execute([':cust_id' => $company['customer_id']]);
                    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($cust) {
                        $_SESSION['active_customer_code'] = $cust['customer_code'];
                        $_SESSION['active_customer_name'] = $cust['customer_name'];
                    } else {
                        $_SESSION['active_customer_code'] = '';
                        $_SESSION['active_customer_name'] = $company['company_name'];
                    }
                } catch (Throwable $e) {
                    $_SESSION['active_customer_code'] = '';
                    $_SESSION['active_customer_name'] = $company['company_name'];
                }

                return true;
            }
        }

        return false;
    }
}


if (!function_exists('getActiveCompany')) {

    function getActiveCompany()
    {
        if (
            !isset(
                $_SESSION['active_company_id']
            )
        ) {
            return null;
        }

        return [
            'company_id' =>
                $_SESSION['active_company_id'],

            'company_code' =>
                $_SESSION['active_company_code'] ?? '',

            'company_name' =>
                $_SESSION['active_company_name'] ?? '',

            'customer_id' =>
                $_SESSION['active_customer_id'] ?? null,

            'customer_name' =>
                $_SESSION['active_customer_name'] ?? '',

            'customer_code' =>
                $_SESSION['active_customer_code'] ?? ''
        ];
    }
}


if (!function_exists('clearActiveCompany')) {

    function clearActiveCompany()
    {
        unset(
            $_SESSION['active_company_id'],
            $_SESSION['active_company_code'],
            $_SESSION['active_company_name'],
            $_SESSION['active_customer_id'],
            $_SESSION['active_customer_code'],
            $_SESSION['active_customer_name']
        );
        clearActiveDivision();
    }
}

/* ============================================================
   ORGANIZATION HIERARCHY ACCESS (DIVISIONS, PLANTS, WAREHOUSES, PERIODS)
   ============================================================ */

/* --- DIVISIONS --- */
if (!function_exists('getUserAccessibleDivisions')) {
    function getUserAccessibleDivisions($userId = null, $companyId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        if ($companyId === null) {
            $companyId = $_SESSION['active_company_id'] ?? null;
        }
        if (!$userId || !$companyId) {
            return [];
        }

        try {
            $pdo = getDbConnection();
            if (isPlatformAdmin($userId)) {
                $stmt = $pdo->prepare('
                    SELECT division_id, company_id, division_code, division_name, status
                    FROM divisions
                    WHERE company_id = :company_id AND UPPER(status) = \'ACTIVE\'
                    ORDER BY division_name ASC, division_id ASC
                ');
                $stmt->execute([':company_id' => $companyId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $sql = '
                SELECT DISTINCT
                    d.division_id,
                    d.company_id,
                    d.division_code,
                    d.division_name,
                    d.status
                FROM divisions d
                INNER JOIN companies c ON c.company_id = d.company_id
                INNER JOIN user_organization_access uoa ON (
                    (uoa.customer_id = c.customer_id AND uoa.company_id IS NULL AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.company_id = d.company_id AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.division_id = d.division_id)
                    OR (uoa.plant_id IS NOT NULL AND EXISTS (SELECT 1 FROM plants p WHERE p.plant_id = uoa.plant_id AND p.division_id = d.division_id))
                    OR (uoa.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses w INNER JOIN plants p ON p.plant_id = w.plant_id WHERE w.warehouse_id = uoa.warehouse_id AND p.division_id = d.division_id))
                )
                WHERE d.company_id = :company_id
                  AND uoa.user_id = :user_id
                  AND UPPER(uoa.status) = \'ACTIVE\'
                  AND CURRENT_DATE >= uoa.valid_from
                  AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
                  AND UPPER(d.status) = \'ACTIVE\'
                ORDER BY d.division_name ASC, d.division_id ASC
            ';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':company_id' => $companyId, ':user_id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Error fetching divisions: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('userCanAccessDivision')) {
    function userCanAccessDivision($userId, $companyId, $divisionId)
    {
        if (!$userId || !$companyId || !$divisionId) return false;
        $divisions = getUserAccessibleDivisions($userId, $companyId);
        foreach ($divisions as $d) {
            if ((string)$d['division_id'] === (string)$divisionId) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('setActiveDivision')) {
    function setActiveDivision($divisionId)
    {
        $userId = $_SESSION['user_id'] ?? null;
        $companyId = $_SESSION['active_company_id'] ?? null;
        if (!$userId || !$companyId || !$divisionId) return false;

        if (!userCanAccessDivision($userId, $companyId, $divisionId)) return false;

        $divisions = getUserAccessibleDivisions($userId, $companyId);
        foreach ($divisions as $d) {
            if ((string)$d['division_id'] === (string)$divisionId) {
                if (isset($_SESSION['active_division_id']) && (string)$_SESSION['active_division_id'] !== (string)$d['division_id']) {
                    clearActivePlant();
                }
                $_SESSION['active_division_id'] = $d['division_id'];
                $_SESSION['active_division_code'] = $d['division_code'];
                $_SESSION['active_division_name'] = $d['division_name'];
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('getActiveDivision')) {
    function getActiveDivision()
    {
        if (!isset($_SESSION['active_division_id'])) return null;
        return [
            'division_id' => $_SESSION['active_division_id'],
            'division_code' => $_SESSION['active_division_code'] ?? '',
            'division_name' => $_SESSION['active_division_name'] ?? ''
        ];
    }
}

if (!function_exists('clearActiveDivision')) {
    function clearActiveDivision()
    {
        unset(
            $_SESSION['active_division_id'],
            $_SESSION['active_division_code'],
            $_SESSION['active_division_name']
        );
        clearActivePlant();
    }
}

/* --- PLANTS --- */
if (!function_exists('getUserAccessiblePlants')) {
    function getUserAccessiblePlants($userId = null, $companyId = null, $divisionId = null)
    {
        if ($userId === null) $userId = $_SESSION['user_id'] ?? null;
        if ($companyId === null) $companyId = $_SESSION['active_company_id'] ?? null;
        if ($divisionId === null && isset($_SESSION['active_division_id'])) {
            $divisionId = $_SESSION['active_division_id'];
        }
        if (!$userId || !$companyId) return [];

        try {
            $pdo = getDbConnection();
            $hasDiv = ($divisionId !== null && $divisionId !== '' && (string)$divisionId !== 'ALL');

            if (isPlatformAdmin($userId)) {
                $sql = '
                    SELECT p.plant_id, p.division_id, p.plant_code, p.plant_name, p.location, p.status
                    FROM plants p
                    INNER JOIN divisions d ON d.division_id = p.division_id
                    WHERE d.company_id = :company_id AND UPPER(p.status) = \'ACTIVE\'
                ';
                $params = [':company_id' => $companyId];
                if ($hasDiv) {
                    $sql .= ' AND p.division_id = :division_id';
                    $params[':division_id'] = $divisionId;
                }
                $sql .= ' ORDER BY p.plant_name ASC, p.plant_id ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $sql = '
                SELECT DISTINCT
                    p.plant_id,
                    p.division_id,
                    p.plant_code,
                    p.plant_name,
                    p.location,
                    p.status
                FROM plants p
                INNER JOIN divisions d ON d.division_id = p.division_id
                INNER JOIN companies c ON c.company_id = d.company_id
                INNER JOIN user_organization_access uoa ON (
                    (uoa.customer_id = c.customer_id AND uoa.company_id IS NULL AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.company_id = c.company_id AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.division_id = d.division_id AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.plant_id = p.plant_id)
                    OR (uoa.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses w WHERE w.warehouse_id = uoa.warehouse_id AND w.plant_id = p.plant_id))
                )
                WHERE d.company_id = :company_id
                  AND uoa.user_id = :user_id
                  AND UPPER(uoa.status) = \'ACTIVE\'
                  AND CURRENT_DATE >= uoa.valid_from
                  AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
                  AND UPPER(p.status) = \'ACTIVE\'
            ';
            $params = [':company_id' => $companyId, ':user_id' => $userId];
            if ($hasDiv) {
                $sql .= ' AND p.division_id = :division_id';
                $params[':division_id'] = $divisionId;
            }
            $sql .= ' ORDER BY p.plant_name ASC, p.plant_id ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Error fetching plants: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('userCanAccessPlant')) {
    function userCanAccessPlant($userId, $companyId, $divisionId, $plantId)
    {
        if (!$userId || !$companyId || !$divisionId || !$plantId) return false;
        $plants = getUserAccessiblePlants($userId, $companyId, $divisionId);
        foreach ($plants as $p) {
            if ((string)$p['plant_id'] === (string)$plantId) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('syncCost360ContextSession')) {
    function syncCost360ContextSession()
    {
        $locType = $_SESSION['active_location_type'] ?? null;
        if (!$locType) {
            if (!empty($_SESSION['active_plant_id'])) {
                $locType = 'plant';
            } elseif (!empty($_SESSION['active_warehouse_id'])) {
                $locType = 'warehouse';
            }
        }

        $_SESSION['cost360_context'] = [
            'customer_id'   => $_SESSION['active_customer_id'] ?? null,
            'customer_name' => $_SESSION['active_customer_name'] ?? '',
            'customer_code' => $_SESSION['active_customer_code'] ?? '',
            'company_id'    => $_SESSION['active_company_id'] ?? null,
            'company_name'  => $_SESSION['active_company_name'] ?? '',
            'company_code'  => $_SESSION['active_company_code'] ?? '',
            'division_id'   => $_SESSION['active_division_id'] ?? null,
            'division_name' => $_SESSION['active_division_name'] ?? '',
            'division_code' => $_SESSION['active_division_code'] ?? '',
            'location_type' => $locType,
            'location_id'   => ($locType === 'plant') ? ($_SESSION['active_plant_id'] ?? null) : (($locType === 'warehouse') ? ($_SESSION['active_warehouse_id'] ?? null) : null),
            'location_name' => ($locType === 'plant') ? ($_SESSION['active_plant_name'] ?? '') : (($locType === 'warehouse') ? ($_SESSION['active_warehouse_name'] ?? '') : ''),
            'location_code' => ($locType === 'plant') ? ($_SESSION['active_plant_code'] ?? '') : (($locType === 'warehouse') ? ($_SESSION['active_warehouse_code'] ?? '') : ''),
            'plant_id'      => ($locType === 'plant') ? ($_SESSION['active_plant_id'] ?? null) : null,
            'warehouse_id'  => ($locType === 'warehouse') ? ($_SESSION['active_warehouse_id'] ?? null) : null,
            'period_id'     => $_SESSION['active_period_id'] ?? null,
            'period_name'   => $_SESSION['active_period_name'] ?? '',
            'period_start'  => $_SESSION['active_period_start'] ?? '',
            'period_end'    => $_SESSION['active_period_end'] ?? ''
        ];
    }
}

if (!function_exists('setActivePlant')) {
    function setActivePlant($plantId)
    {
        $userId = $_SESSION['user_id'] ?? null;
        $companyId = $_SESSION['active_company_id'] ?? null;
        $divisionId = $_SESSION['active_division_id'] ?? null;
        if (!$userId || !$companyId || !$divisionId || !$plantId) return false;

        if ($plantId === 'ALL' && isPlatformAdmin($userId)) {
            $_SESSION['active_location_type'] = 'plant';
            $_SESSION['active_plant_id'] = 'ALL';
            $_SESSION['active_plant_code'] = 'ALL';
            $_SESSION['active_plant_name'] = 'All Plants';
            clearActiveWarehouse();
            syncCost360ContextSession();
            return true;
        }

        if (!userCanAccessPlant($userId, $companyId, $divisionId, $plantId)) return false;

        $plants = getUserAccessiblePlants($userId, $companyId, $divisionId);
        foreach ($plants as $p) {
            if ((string)$p['plant_id'] === (string)$plantId) {
                // Mutually exclusive: selecting a Plant clears any Warehouse selection
                clearActiveWarehouse();

                $_SESSION['active_location_type'] = 'plant';
                $_SESSION['active_plant_id'] = $p['plant_id'];
                $_SESSION['active_plant_code'] = $p['plant_code'];
                $_SESSION['active_plant_name'] = $p['plant_name'];

                syncCost360ContextSession();
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('getActivePlant')) {
    function getActivePlant()
    {
        if (!isset($_SESSION['active_plant_id'])) return null;
        return [
            'plant_id' => $_SESSION['active_plant_id'],
            'plant_code' => $_SESSION['active_plant_code'] ?? '',
            'plant_name' => $_SESSION['active_plant_name'] ?? ''
        ];
    }
}

if (!function_exists('clearActivePlant')) {
    function clearActivePlant()
    {
        unset(
            $_SESSION['active_plant_id'],
            $_SESSION['active_plant_code'],
            $_SESSION['active_plant_name']
        );
        if (($_SESSION['active_location_type'] ?? '') === 'plant') {
            unset($_SESSION['active_location_type']);
        }
        clearActivePeriod();
        syncCost360ContextSession();
    }
}

/* --- WAREHOUSES --- */
if (!function_exists('getUserAccessibleWarehouses')) {
    function getUserAccessibleWarehouses($userId = null, $companyId = null, $divisionId = null, $plantId = null)
    {
        if ($userId === null) $userId = $_SESSION['user_id'] ?? null;
        if ($companyId === null) $companyId = $_SESSION['active_company_id'] ?? null;
        if ($divisionId === null) $divisionId = $_SESSION['active_division_id'] ?? null;
        if ($plantId === null) $plantId = $_SESSION['active_plant_id'] ?? null;

        // If plantId is omitted or null, we now support resolving all warehouses under the division!
        if (!$userId || !$companyId || !$divisionId) return [];

        try {
            $pdo = getDbConnection();
            $params = [
                ':division_id' => $divisionId,
                ':company_id'  => $companyId
            ];

            $plantCondition = '';
            if (!empty($plantId) && $plantId !== 'ALL') {
                $plantCondition = ' AND w.plant_id = :plant_id';
                $params[':plant_id'] = $plantId;
            }

            if (isPlatformAdmin($userId)) {
                $sql = "
                    SELECT w.warehouse_id, w.plant_id, w.warehouse_code, w.warehouse_name, w.status,
                           p.plant_name, p.plant_code, p.location AS plant_location
                    FROM warehouses w
                    INNER JOIN plants p ON p.plant_id = w.plant_id
                    INNER JOIN divisions d ON d.division_id = p.division_id
                    WHERE p.division_id = :division_id 
                      AND d.company_id = :company_id 
                      AND UPPER(w.status) = 'ACTIVE'
                      {$plantCondition}
                    ORDER BY w.warehouse_name ASC, w.warehouse_id ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $params[':user_id'] = $userId;
            $sql = "
                SELECT DISTINCT
                    w.warehouse_id,
                    w.plant_id,
                    w.warehouse_code,
                    w.warehouse_name,
                    w.status,
                    p.plant_name,
                    p.plant_code,
                    p.location AS plant_location
                FROM warehouses w
                INNER JOIN plants p ON p.plant_id = w.plant_id
                INNER JOIN divisions d ON d.division_id = p.division_id
                INNER JOIN companies c ON c.company_id = d.company_id
                INNER JOIN user_organization_access uoa ON (
                    (uoa.customer_id = c.customer_id AND uoa.company_id IS NULL AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.company_id = c.company_id AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.division_id = d.division_id AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.plant_id = p.plant_id AND uoa.warehouse_id IS NULL)
                    OR (uoa.warehouse_id = w.warehouse_id)
                )
                WHERE p.division_id = :division_id
                  AND d.company_id = :company_id
                  AND uoa.user_id = :user_id
                  AND UPPER(uoa.status) = 'ACTIVE'
                  AND CURRENT_DATE >= uoa.valid_from
                  AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
                  AND UPPER(w.status) = 'ACTIVE'
                  {$plantCondition}
                ORDER BY w.warehouse_name ASC, w.warehouse_id ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Error fetching warehouses: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('userCanAccessWarehouse')) {
    function userCanAccessWarehouse($userId, $companyId, $divisionId, $warehouseId, $plantId = null)
    {
        if (!$userId || !$companyId || !$divisionId || !$warehouseId) return false;
        $warehouses = getUserAccessibleWarehouses($userId, $companyId, $divisionId, $plantId);
        foreach ($warehouses as $w) {
            if ((string)$w['warehouse_id'] === (string)$warehouseId) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('setActiveWarehouse')) {
    function setActiveWarehouse($warehouseId)
    {
        $userId = $_SESSION['user_id'] ?? null;
        $companyId = $_SESSION['active_company_id'] ?? null;
        $divisionId = $_SESSION['active_division_id'] ?? null;
        if (!$userId || !$companyId || !$divisionId || !$warehouseId) return false;

        if ($warehouseId === 'ALL' && isPlatformAdmin($userId)) {
            $_SESSION['active_location_type'] = 'warehouse';
            $_SESSION['active_warehouse_id'] = 'ALL';
            $_SESSION['active_warehouse_code'] = 'ALL';
            $_SESSION['active_warehouse_name'] = 'All Warehouses';
            clearActivePlant();
            syncCost360ContextSession();
            return true;
        }

        if (!userCanAccessWarehouse($userId, $companyId, $divisionId, $warehouseId)) return false;

        $warehouses = getUserAccessibleWarehouses($userId, $companyId, $divisionId, null);
        foreach ($warehouses as $w) {
            if ((string)$w['warehouse_id'] === (string)$warehouseId) {
                // Mutually exclusive: selecting a Warehouse clears any Plant selection
                clearActivePlant();

                $_SESSION['active_location_type'] = 'warehouse';
                $_SESSION['active_warehouse_id'] = $w['warehouse_id'];
                $_SESSION['active_warehouse_code'] = $w['warehouse_code'];
                $_SESSION['active_warehouse_name'] = $w['warehouse_name'];

                syncCost360ContextSession();
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('getActiveWarehouse')) {
    function getActiveWarehouse()
    {
        if (!isset($_SESSION['active_warehouse_id'])) return null;
        return [
            'warehouse_id' => $_SESSION['active_warehouse_id'],
            'warehouse_code' => $_SESSION['active_warehouse_code'] ?? '',
            'warehouse_name' => $_SESSION['active_warehouse_name'] ?? ''
        ];
    }
}

if (!function_exists('clearActiveWarehouse')) {
    function clearActiveWarehouse()
    {
        unset(
            $_SESSION['active_warehouse_id'],
            $_SESSION['active_warehouse_code'],
            $_SESSION['active_warehouse_name']
        );
        if (($_SESSION['active_location_type'] ?? '') === 'warehouse') {
            unset($_SESSION['active_location_type']);
        }
        clearActivePeriod();
        syncCost360ContextSession();
    }
}

/* --- OPERATIONAL LOCATION HELPERS (PLANT OR WAREHOUSE) --- */
if (!function_exists('getActiveOperationalLocation')) {
    function getActiveOperationalLocation()
    {
        $type = $_SESSION['active_location_type'] ?? null;
        if ($type === 'plant' && !empty($_SESSION['active_plant_id'])) {
            return [
                'type' => 'plant',
                'label' => 'Plant',
                'id' => $_SESSION['active_plant_id'],
                'code' => $_SESSION['active_plant_code'] ?? '',
                'name' => $_SESSION['active_plant_name'] ?? ''
            ];
        }
        if ($type === 'warehouse' && !empty($_SESSION['active_warehouse_id'])) {
            return [
                'type' => 'warehouse',
                'label' => 'Warehouse',
                'id' => $_SESSION['active_warehouse_id'],
                'code' => $_SESSION['active_warehouse_code'] ?? '',
                'name' => $_SESSION['active_warehouse_name'] ?? ''
            ];
        }

        // Fallback detection
        if (!empty($_SESSION['active_plant_id'])) {
            return [
                'type' => 'plant',
                'label' => 'Plant',
                'id' => $_SESSION['active_plant_id'],
                'code' => $_SESSION['active_plant_code'] ?? '',
                'name' => $_SESSION['active_plant_name'] ?? ''
            ];
        }
        if (!empty($_SESSION['active_warehouse_id'])) {
            return [
                'type' => 'warehouse',
                'label' => 'Warehouse',
                'id' => $_SESSION['active_warehouse_id'],
                'code' => $_SESSION['active_warehouse_code'] ?? '',
                'name' => $_SESSION['active_warehouse_name'] ?? ''
            ];
        }

        return null;
    }
}

if (!function_exists('clearActiveOperationalLocation')) {
    function clearActiveOperationalLocation()
    {
        clearActivePlant();
        clearActiveWarehouse();
        unset($_SESSION['active_location_type']);
        clearActivePeriod();
        syncCost360ContextSession();
    }
}

/* --- PERIODS --- */
if (!function_exists('getUserAccessiblePeriods')) {
    function getUserAccessiblePeriods($companyId = null)
    {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("
                SELECT period_id, period_name, start_date, end_date, is_locked, status
                FROM periods
                WHERE UPPER(status) = 'ACTIVE'
                ORDER BY start_date DESC, period_id DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Error fetching periods: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('userCanAccessPeriod')) {
    function userCanAccessPeriod($periodId)
    {
        if (!$periodId) return false;
        $periods = getUserAccessiblePeriods();
        foreach ($periods as $p) {
            if ((string)$p['period_id'] === (string)$periodId) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('setActivePeriod')) {
    function setActivePeriod($periodId)
    {
        if (!userCanAccessPeriod($periodId)) return false;

        $periods = getUserAccessiblePeriods();
        foreach ($periods as $p) {
            if ((string)$p['period_id'] === (string)$periodId) {
                $_SESSION['active_period_id'] = $p['period_id'];
                $_SESSION['active_period_name'] = $p['period_name'];
                $_SESSION['active_period_start'] = $p['start_date'];
                $_SESSION['active_period_end'] = $p['end_date'];

                syncCost360ContextSession();
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('getActivePeriod')) {
    function getActivePeriod()
    {
        if (!isset($_SESSION['active_period_id'])) return null;
        return [
            'period_id' => $_SESSION['active_period_id'],
            'period_name' => $_SESSION['active_period_name'] ?? '',
            'start_date' => $_SESSION['active_period_start'] ?? '',
            'end_date' => $_SESSION['active_period_end'] ?? ''
        ];
    }
}

if (!function_exists('clearActivePeriod')) {
    function clearActivePeriod()
    {
        unset(
            $_SESSION['active_period_id'],
            $_SESSION['active_period_name'],
            $_SESSION['active_period_start'],
            $_SESSION['active_period_end']
        );
        syncCost360ContextSession();
    }
}

if (!function_exists('createAccountingPeriod')) {
    function createAccountingPeriod($periodName, $startDate, $endDate, $status = 'Active')
    {
        $name = trim($periodName);
        $start = trim($startDate);
        $end = trim($endDate);
        $st = (strcasecmp($status, 'Active') === 0) ? 'Active' : 'Inactive';

        if (empty($name) || empty($start) || empty($end)) {
            throw new Exception("Period name, start date, and end date are required.");
        }

        if (strtotime($start) > strtotime($end)) {
            throw new Exception("Start date cannot be after end date.");
        }

        $pdo = getDbConnection();

        // Check for duplicate period name
        $chk = $pdo->prepare("SELECT period_id FROM periods WHERE LOWER(period_name) = LOWER(:name) LIMIT 1");
        $chk->execute([':name' => $name]);
        if ($chk->fetch()) {
            throw new Exception("An accounting period with the name '{$name}' already exists.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO periods (period_name, start_date, end_date, is_locked, status)
            VALUES (:name, :start, :end, FALSE, :status)
            RETURNING period_id, period_name, start_date, end_date, is_locked, status
        ");
        $stmt->execute([
            ':name'   => $name,
            ':start'  => $start,
            ':end'    => $end,
            ':status' => $st
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* --- FULL CONTEXT FUNCTIONS --- */
if (!function_exists('getActiveOrganizationContext')) {
    function getActiveOrganizationContext()
    {
        if (!isset($_SESSION['active_company_id'])) {
            return null;
        }

        syncCost360ContextSession();

        $loc = getActiveOperationalLocation();

        return [
            'customer_id'   => $_SESSION['active_customer_id'] ?? null,
            'customer_name' => $_SESSION['active_customer_name'] ?? '',
            'customer_code' => $_SESSION['active_customer_code'] ?? '',
            'company_id'    => $_SESSION['active_company_id'] ?? null,
            'company_name'  => $_SESSION['active_company_name'] ?? '',
            'company_code'  => $_SESSION['active_company_code'] ?? '',
            'division_id'   => $_SESSION['active_division_id'] ?? null,
            'division_name' => $_SESSION['active_division_name'] ?? '',
            'division_code' => $_SESSION['active_division_code'] ?? '',
            'location_type' => $loc['type'] ?? null,
            'location_label'=> $loc['label'] ?? null,
            'location_id'   => $loc['id'] ?? null,
            'location_name' => $loc['name'] ?? '',
            'location_code' => $loc['code'] ?? '',
            'plant_id'      => $_SESSION['active_plant_id'] ?? null,
            'plant_name'    => $_SESSION['active_plant_name'] ?? '',
            'plant_code'    => $_SESSION['active_plant_code'] ?? '',
            'warehouse_id'  => $_SESSION['active_warehouse_id'] ?? null,
            'warehouse_name'=> $_SESSION['active_warehouse_name'] ?? '',
            'warehouse_code'=> $_SESSION['active_warehouse_code'] ?? '',
            'period_id'     => $_SESSION['active_period_id'] ?? null,
            'period_name'   => $_SESSION['active_period_name'] ?? '',
            'period_start'  => $_SESSION['active_period_start'] ?? '',
            'period_end'    => $_SESSION['active_period_end'] ?? ''
        ];
    }
}

if (!function_exists('clearActiveOrganizationContext')) {
    function clearActiveOrganizationContext()
    {
        clearActiveCompany();
    }
}



/* ============================================================
   AUTHORIZED MENUS
   ============================================================ */

if (!function_exists('getUserAuthorizedMenus')) {

    function getUserAuthorizedMenus($userId = null)
    {
        if ($userId === null) {
            $userId =
                $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return [];
        }

        static $cachedMenusMap = [];

        if (isset($cachedMenusMap[$userId])) {
            return $cachedMenusMap[$userId];
        }

        try {

            $pdo = getDbConnection();

            if (isPlatformAdmin($userId)) {
                $stmt = $pdo->prepare("
                    SELECT
                        m.menu_id,
                        m.parent_menu_id,
                        m.menu_code,
                        m.menu_name,
                        m.menu_type,
                        m.route,
                        m.icon,
                        m.display_order,
                        m.status,
                        TRUE AS can_view,
                        TRUE AS can_create,
                        TRUE AS can_edit,
                        TRUE AS can_delete,
                        TRUE AS can_upload,
                        TRUE AS can_download,
                        TRUE AS can_approve,
                        TRUE AS can_export,
                        TRUE AS can_print,
                        TRUE AS can_configure
                    FROM menus m
                    WHERE m.status = 'Active'
                    ORDER BY m.display_order ASC, m.menu_id ASC
                ");
                $stmt->execute();
                $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $cachedMenusMap[$userId] = $menus ?: [];
                return $cachedMenusMap[$userId];
            }

            $sql = "

                WITH RECURSIVE authorized_menu_tree AS (

                    SELECT DISTINCT

                        m.menu_id,
                        m.parent_menu_id,
                        m.menu_code,
                        m.menu_name,
                        m.menu_type,
                        m.route,
                        m.icon,
                        m.display_order,
                        m.status,

                        rm.can_view,
                        rm.can_create,
                        rm.can_edit,
                        rm.can_delete,
                        rm.can_upload,
                        rm.can_download,
                        rm.can_approve,
                        rm.can_export,
                        rm.can_print,
                        rm.can_configure

                    FROM menus m

                    INNER JOIN role_menu rm
                        ON rm.menu_id = m.menu_id

                    INNER JOIN user_roles ur
                        ON ur.role_id = rm.role_id

                    INNER JOIN roles r
                        ON r.role_id = ur.role_id

                    WHERE ur.user_id = :user_id

                      AND ur.status = 'Active'

                      AND r.status = 'Active'

                      AND m.status = 'Active'

                      AND rm.is_active = TRUE

                      AND rm.can_view = TRUE


                    UNION ALL


                    SELECT

                        parent.menu_id,
                        parent.parent_menu_id,
                        parent.menu_code,
                        parent.menu_name,
                        parent.menu_type,
                        parent.route,
                        parent.icon,
                        parent.display_order,
                        parent.status,

                        FALSE AS can_view,
                        FALSE AS can_create,
                        FALSE AS can_edit,
                        FALSE AS can_delete,
                        FALSE AS can_upload,
                        FALSE AS can_download,
                        FALSE AS can_approve,
                        FALSE AS can_export,
                        FALSE AS can_print,
                        FALSE AS can_configure

                    FROM menus parent

                    INNER JOIN authorized_menu_tree child
                        ON child.parent_menu_id =
                           parent.menu_id

                    WHERE parent.status = 'Active'
                )

                SELECT

                    menu_id,
                    parent_menu_id,
                    menu_code,
                    menu_name,
                    menu_type,
                    route,
                    icon,
                    display_order,
                    status,

                    BOOL_OR(can_view) AS can_view,
                    BOOL_OR(can_create) AS can_create,
                    BOOL_OR(can_edit) AS can_edit,
                    BOOL_OR(can_delete) AS can_delete,
                    BOOL_OR(can_upload) AS can_upload,
                    BOOL_OR(can_download) AS can_download,
                    BOOL_OR(can_approve) AS can_approve,
                    BOOL_OR(can_export) AS can_export,
                    BOOL_OR(can_print) AS can_print,
                    BOOL_OR(can_configure) AS can_configure

                FROM authorized_menu_tree

                GROUP BY
                    menu_id,
                    parent_menu_id,
                    menu_code,
                    menu_name,
                    menu_type,
                    route,
                    icon,
                    display_order,
                    status

                ORDER BY
                    display_order ASC,
                    menu_id ASC
            ";

            $stmt =
                $pdo->prepare($sql);

            $stmt->execute([
                ':user_id' => $userId
            ]);

            $menus =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

            $cachedMenusMap[$userId] =
                $menus ?: [];

            return $cachedMenusMap[$userId];

        } catch (Throwable $e) {

            error_log(
                'Error fetching authorized menus: ' .
                $e->getMessage()
            );

            return [];
        }
    }
}


/* ============================================================
   PERMISSIONS
   ============================================================ */

if (!function_exists('getUserAuthorizedPermissions')) {

    function getUserAuthorizedPermissions(
        $userId = null
    ) {

        if ($userId === null) {
            $userId =
                $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return [];
        }

        static $cachedPermissions = [];

        if (isset($cachedPermissions[$userId])) {
            return $cachedPermissions[$userId];
        }

        try {

            $menus =
                getUserAuthorizedMenus(
                    $userId
                );

            $perms = [];

            foreach ($menus as $m) {

                $mCode =
                    $m['menu_code'];

                $mName =
                    $m['menu_name'];

                if ($m['can_view']) {
                    $perms[] = [
                        'permission_code' => 'VIEW',
                        'permission_name' => 'View',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_create']) {
                    $perms[] = [
                        'permission_code' => 'CREATE',
                        'permission_name' => 'Create',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_edit']) {
                    $perms[] = [
                        'permission_code' => 'EDIT',
                        'permission_name' => 'Edit',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_delete']) {
                    $perms[] = [
                        'permission_code' => 'DELETE',
                        'permission_name' => 'Delete',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_upload']) {
                    $perms[] = [
                        'permission_code' => 'UPLOAD',
                        'permission_name' => 'Upload',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_download']) {
                    $perms[] = [
                        'permission_code' => 'DOWNLOAD',
                        'permission_name' => 'Download',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_approve']) {
                    $perms[] = [
                        'permission_code' => 'APPROVE',
                        'permission_name' => 'Approve',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_export']) {
                    $perms[] = [
                        'permission_code' => 'EXPORT',
                        'permission_name' => 'Export',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_print']) {
                    $perms[] = [
                        'permission_code' => 'PRINT',
                        'permission_name' => 'Print',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }

                if ($m['can_configure']) {
                    $perms[] = [
                        'permission_code' => 'CONFIGURE',
                        'permission_name' => 'Configure',
                        'menu_code' => $mCode,
                        'menu_name' => $mName
                    ];
                }
            }

            $cachedPermissions[$userId] =
                $perms;

            $_SESSION['auth_cache']['permissions'] =
                $perms;

            return $perms;

        } catch (Exception $e) {

            error_log(
                'Error generating user permissions: ' .
                $e->getMessage()
            );

            return [];
        }
    }
}


/* ============================================================
   MENU ACCESS
   ============================================================ */

if (!function_exists('userHasMenuAccess')) {

    function userHasMenuAccess(
        $menuRouteOrCode,
        $userId = null
    ) {

        $menus =
            getUserAuthorizedMenus(
                $userId
            );

        $cleanTarget =
            strtolower(
                trim($menuRouteOrCode)
            );

        foreach ($menus as $m) {

            $mCode =
                strtolower(
                    trim($m['menu_code'])
                );

            $mRoute =
                strtolower(
                    trim($m['route'])
                );

            if (
                $mCode === $cleanTarget ||
                $mRoute === $cleanTarget
            ) {
                return true;
            }

            if (
                $mRoute !== '' &&
                (
                    str_contains(
                        $mRoute,
                        $cleanTarget
                    ) ||
                    str_contains(
                        $cleanTarget,
                        $mCode
                    )
                )
            ) {
                return true;
            }
        }

        return false;
    }
}


if (!function_exists('userHasPermission')) {

    function userHasPermission(
        $permissionCode,
        $menuCode = null,
        $userId = null
    ) {

        $permissions =
            getUserAuthorizedPermissions(
                $userId
            );

        foreach ($permissions as $p) {

            if (
                strcasecmp(
                    $p['permission_code'],
                    $permissionCode
                ) === 0
            ) {

                if (
                    $menuCode === null ||
                    strcasecmp(
                        $p['menu_code'],
                        $menuCode
                    ) === 0
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}


/* ============================================================
   PERMISSION REQUIREMENT
   ============================================================ */

if (!function_exists('requirePermission')) {

    function requirePermission(
        $permissionCode,
        $menuCode = null
    ) {

        requireAuth();

        if (
            !userHasPermission(
                $permissionCode,
                $menuCode
            )
        ) {

            header(
                'HTTP/1.1 403 Forbidden'
            );

            echo '
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Access Denied - Cost360</title>

                <style>

                    body {
                        font-family: sans-serif;
                        background: #0A0E17;
                        color: #fff;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        margin: 0;
                    }

                    .card {
                        background: #1E293B;
                        padding: 2rem;
                        border-radius: 12px;
                        text-align: center;
                        max-width: 400px;
                    }

                    h1 {
                        color: #F87171;
                    }

                    a {
                        color: #00CFEF;
                        text-decoration: none;
                        font-weight: 600;
                    }

                </style>

            </head>

            <body>

                <div class="card">

                    <h1>Access Denied</h1>

                    <p>
                        You do not have permission
                        ('
                        . htmlspecialchars(
                            $permissionCode
                        )
                        . ')
                        to access this resource.
                    </p>

                    <p>
                        <a href="dashboard.php">
                            Return to Dashboard
                        </a>
                    </p>

                </div>

            </body>
            </html>';

            exit;
        }
    }
}