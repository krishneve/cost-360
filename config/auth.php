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

        autoResolveSingleScopeChain($userId);

        $appRootDir = realpath(dirname(__DIR__));
        $currentScriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
        $relative = str_replace('\\', '/', substr($currentScriptDir, strlen($appRootDir)));
        $relative = trim($relative, '/');
        $prefix = $relative === '' ? '' : str_repeat('../', count(explode('/', $relative)));

        // 1. Company Check
        if (empty($_SESSION['active_company_id'])) {
            $accessibleCompanies = getUserAccessibleCompanies($userId);
            if (count($accessibleCompanies) === 1) {
                setActiveCompany($accessibleCompanies[0]['company_id']);
            } elseif (count($accessibleCompanies) > 1) {
                header('Location: ' . $prefix . 'company-selection.php');
                exit;
            } else {
                // No company access assigned
                header('Location: ' . $prefix . 'login.php');
                exit;
            }
        }

        $companyId = $_SESSION['active_company_id'];

        // 2. Division Check (Only required if multiple divisions exist and none selected)
        if (empty($_SESSION['active_division_id'])) {
            $divisions = getUserAccessibleDivisions($userId, $companyId);
            if (count($divisions) === 1) {
                setActiveDivision($divisions[0]['division_id']);
            } elseif (count($divisions) > 1) {
                header('Location: ' . $prefix . 'division-selection.php');
                exit;
            }
            // If 0 divisions exist, valid at Company scope
        }

        // 3. Location Check (Plant / Warehouse - Only required if multiple locations exist and none selected)
        $divisionId = $_SESSION['active_division_id'] ?? null;
        $hasOperationalLocation = !empty($_SESSION['active_plant_id']) || !empty($_SESSION['active_warehouse_id']);
        if (!$hasOperationalLocation && !empty($divisionId)) {
            $locations = getUserAccessibleLocations($userId, $companyId, $divisionId);
            if (count($locations) === 1) {
                setActiveLocation($locations[0]['type'], $locations[0]['id']);
            } elseif (count($locations) > 1) {
                header('Location: ' . $prefix . 'organization-selection.php');
                exit;
            }
            // If 0 locations exist, valid at Division scope
        }

        // 4. Period Check (Only required if multiple periods exist and none selected)
        if (empty($_SESSION['active_period_id'])) {
            $activeLoc = getActiveOperationalLocation();
            $locType = $activeLoc['type_code'] ?? null;
            $locId = $activeLoc['id'] ?? null;
            $periods = getUserAccessiblePeriods($companyId, $userId, $divisionId, $locType, $locId);
            if (count($periods) === 1) {
                setActivePeriod($periods[0]['period_id']);
            } elseif (count($periods) > 1) {
                header('Location: ' . $prefix . 'period-selection.php');
                exit;
            }
        }

        return true;
    }
}

if (!function_exists('autoResolveSingleScopeChain')) {
    /**
     * Automatically resolves single-assignment organizational context levels
     * down the chain: Customer -> Company -> Division -> Location -> Period.
     * Stops automatically if 2+ options exist at any step.
     */
    function autoResolveSingleScopeChain($userId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        if (!$userId || isPlatformAdmin($userId) || isCmaAdmin($userId)) {
            return;
        }

        // 1. Resolve Customer Tenant
        $activeCustomer = getActiveCustomer();
        if (!$activeCustomer || empty($activeCustomer['customer_id'])) {
            $tenantId = getUserCustomerTenantId($userId);
            if ($tenantId) {
                setActiveCustomer($tenantId);
                $activeCustomer = getActiveCustomer();
            }
        }
        $customerId = $activeCustomer['customer_id'] ?? null;

        // 2. Company Auto-Selection
        if (empty($_SESSION['active_company_id'])) {
            $companies = getUserAccessibleCompanies($userId, $customerId);
            if (count($companies) === 1) {
                setActiveCompany($companies[0]['company_id']);
            } else {
                return;
            }
        }

        // 3. Division Auto-Selection
        $companyId = $_SESSION['active_company_id'];
        if (empty($_SESSION['active_division_id'])) {
            $divisions = getUserAccessibleDivisions($userId, $companyId);
            if (count($divisions) === 1) {
                setActiveDivision($divisions[0]['division_id']);
            } elseif (count($divisions) > 1) {
                return;
            }
        }

        // 4. Location Auto-Selection (Plant / Warehouse)
        $divisionId = $_SESSION['active_division_id'] ?? null;
        $hasOpLoc = !empty($_SESSION['active_plant_id']) || !empty($_SESSION['active_warehouse_id']);
        if (!$hasOpLoc && !empty($divisionId)) {
            $locations = getUserAccessibleLocations($userId, $companyId, $divisionId);
            if (count($locations) === 1) {
                setActiveLocation($locations[0]['type'], $locations[0]['id']);
            } elseif (count($locations) > 1) {
                return;
            }
        }

        // 5. Period Auto-Selection
        $activeLoc = getActiveOperationalLocation();
        $locType = $activeLoc['type_code'] ?? null;
        $locId = $activeLoc['id'] ?? null;
        if (empty($_SESSION['active_period_id'])) {
            $periods = getUserAccessiblePeriods($companyId, $userId, $divisionId, $locType, $locId);
            if (count($periods) === 1) {
                setActivePeriod($periods[0]['period_id']);
            }
        }
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
if (!function_exists('hasUoaPeriodColumn')) {
    function hasUoaPeriodColumn(PDO $pdo): bool
    {
        static $hasCol = null;
        if ($hasCol !== null) return $hasCol;
        try {
            $stmt = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='user_organization_access' AND column_name='period_id'");
            $hasCol = ($stmt && $stmt->fetchColumn() !== false);
        } catch (Throwable $e) {
            $hasCol = false;
        }
        return $hasCol;
    }
}

if (!function_exists('getUserAccessibleLocations')) {
    /**
     * Returns a unified list of all accessible Locations (Plants and Warehouses) under the given Division.
     * Each location item includes standard fields:
     * - id: entity ID (plant_id or warehouse_id)
     * - type: 'Plant' or 'Warehouse'
     * - type_code: 'plant' or 'warehouse'
     * - name: Location display name
     * - code: Location code
     * - plant_id: plant_id (if plant, or parent plant_id if warehouse)
     * - warehouse_id: warehouse_id (if warehouse, else null)
     * - parent_plant_name: Parent plant name (if warehouse attached to plant)
     * - location_address: Physical location / MIDC address if known
     * - status: Status (Active)
     */
    function getUserAccessibleLocations($userId = null, $companyId = null, $divisionId = null)
    {
        if ($userId === null) $userId = $_SESSION['user_id'] ?? null;
        if ($companyId === null) $companyId = $_SESSION['active_company_id'] ?? null;
        if ($divisionId === null) $divisionId = $_SESSION['active_division_id'] ?? null;

        if (!$userId || !$companyId || !$divisionId) {
            return [];
        }

        $plants = getUserAccessiblePlants($userId, $companyId, $divisionId);
        $warehouses = getUserAccessibleWarehouses($userId, $companyId, $divisionId, null);

        $locations = [];

        // 1. Add all accessible Plants
        foreach ($plants as $p) {
            $locations[] = [
                'id'                => (int)$p['plant_id'],
                'location_id'       => (int)$p['plant_id'],
                'type'              => 'Plant',
                'type_code'         => 'plant',
                'name'              => $p['plant_name'],
                'code'              => $p['plant_code'],
                'plant_id'          => (int)$p['plant_id'],
                'warehouse_id'      => null,
                'parent_plant_name' => null,
                'location_address'  => $p['location'] ?? '',
                'status'            => $p['status'] ?? 'Active'
            ];
        }

        // 2. Add all accessible Warehouses
        foreach ($warehouses as $w) {
            $locations[] = [
                'id'                => (int)$w['warehouse_id'],
                'location_id'       => (int)$w['warehouse_id'],
                'type'              => 'Warehouse',
                'type_code'         => 'warehouse',
                'name'              => $w['warehouse_name'],
                'code'              => $w['warehouse_code'],
                'plant_id'          => !empty($w['plant_id']) ? (int)$w['plant_id'] : null,
                'warehouse_id'      => (int)$w['warehouse_id'],
                'parent_plant_name' => $w['plant_name'] ?? null,
                'location_address'  => $w['plant_location'] ?? '',
                'status'            => $w['status'] ?? 'Active'
            ];
        }

        // Sort: Plants first, then Warehouses, alphabetically by name
        usort($locations, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return ($a['type'] === 'Plant') ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $locations;
    }
}

if (!function_exists('setActiveLocation')) {
    /**
     * Sets active location context. Accepts either 'plant' or 'warehouse' as $locationType.
     */
    function setActiveLocation($locationType, $locationId)
    {
        $type = strtolower(trim($locationType));
        if ($type === 'plant') {
            return setActivePlant($locationId);
        } elseif ($type === 'warehouse') {
            return setActiveWarehouse($locationId);
        }
        return false;
    }
}

if (!function_exists('userCanAccessLocation')) {
    function userCanAccessLocation($arg1, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null)
    {
        // Polymorphic support for 3 args: userCanAccessLocation($locationType, $locationId, $userId) or ($userId, $locationType, $locationId)
        if ($arg4 === null && $arg5 === null) {
            if (is_numeric($arg1) && is_string($arg2)) {
                $userId = (int)$arg1;
                $locationType = strtolower(trim($arg2));
                $locationId = (int)$arg3;
            } elseif (is_string($arg1)) {
                $locationType = strtolower(trim($arg1));
                $locationId = (int)$arg2;
                $userId = (int)$arg3;
            } else {
                $userId = (int)$arg3;
                $locationType = 'plant';
                $locationId = (int)$arg2;
            }

            if ($locationType === 'warehouse') {
                $whs = getUserAccessibleWarehouses(null, null, $userId);
                foreach ($whs as $w) {
                    if ((int)$w['warehouse_id'] === $locationId) return true;
                }
                return false;
            } else {
                $plts = getUserAccessiblePlants(null, null, $userId);
                foreach ($plts as $p) {
                    if ((int)$p['plant_id'] === $locationId) return true;
                }
                return false;
            }
        }

        // Standard 5 args: ($userId, $companyId, $divisionId, $locationType, $locationId)
        $userId = $arg1;
        $companyId = $arg2;
        $divisionId = $arg3;
        $locationType = $arg4;
        $locationId = $arg5;

        $type = strtolower(trim((string)$locationType));
        if ($type === 'plant') {
            return userCanAccessPlant($userId, $companyId, $divisionId, $locationId);
        } elseif ($type === 'warehouse') {
            return userCanAccessWarehouse($userId, $companyId, $divisionId, $locationId);
        }
        return false;
    }
}

if (!function_exists('getActiveOperationalLocation')) {
    function getActiveOperationalLocation()
    {
        $type = $_SESSION['active_location_type'] ?? null;
        if ($type === 'plant' && !empty($_SESSION['active_plant_id'])) {
            return [
                'type'      => 'plant',
                'type_code' => 'plant',
                'label'     => 'Plant',
                'id'        => $_SESSION['active_plant_id'],
                'code'      => $_SESSION['active_plant_code'] ?? '',
                'name'      => $_SESSION['active_plant_name'] ?? ''
            ];
        }
        if ($type === 'warehouse' && !empty($_SESSION['active_warehouse_id'])) {
            return [
                'type'      => 'warehouse',
                'type_code' => 'warehouse',
                'label'     => 'Warehouse',
                'id'        => $_SESSION['active_warehouse_id'],
                'code'      => $_SESSION['active_warehouse_code'] ?? '',
                'name'      => $_SESSION['active_warehouse_name'] ?? ''
            ];
        }

        // Fallback detection
        if (!empty($_SESSION['active_plant_id'])) {
            return [
                'type'      => 'plant',
                'type_code' => 'plant',
                'label'     => 'Plant',
                'id'        => $_SESSION['active_plant_id'],
                'code'      => $_SESSION['active_plant_code'] ?? '',
                'name'      => $_SESSION['active_plant_name'] ?? ''
            ];
        }
        if (!empty($_SESSION['active_warehouse_id'])) {
            return [
                'type'      => 'warehouse',
                'type_code' => 'warehouse',
                'label'     => 'Warehouse',
                'id'        => $_SESSION['active_warehouse_id'],
                'code'      => $_SESSION['active_warehouse_code'] ?? '',
                'name'      => $_SESSION['active_warehouse_name'] ?? ''
            ];
        }

        return null;
    }
}

if (!function_exists('getActiveLocation')) {
    function getActiveLocation()
    {
        return getActiveOperationalLocation();
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

if (!function_exists('clearActiveLocation')) {
    function clearActiveLocation()
    {
        clearActiveOperationalLocation();
    }
}

/* --- PERIODS --- */
if (!function_exists('getUserAccessiblePeriods')) {
    function getUserAccessiblePeriods($companyId = null, $userId = null, $divisionId = null, $locationType = null, $locationId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        if ($companyId === null) {
            $companyId = $_SESSION['active_company_id'] ?? null;
        }
        if ($divisionId === null) {
            $divisionId = $_SESSION['active_division_id'] ?? null;
        }
        if ($locationType === null) {
            $locationType = $_SESSION['active_location_type'] ?? null;
        }
        if ($locationId === null) {
            $locationId = ($locationType === 'plant') ? ($_SESSION['active_plant_id'] ?? null) : (($locationType === 'warehouse') ? ($_SESSION['active_warehouse_id'] ?? null) : null);
        }

        try {
            $pdo = getDbConnection();

            // All active periods (master list)
            $stmt = $pdo->prepare("
                SELECT period_id, period_name, start_date, end_date, is_locked, status
                FROM periods
                WHERE UPPER(status) = 'ACTIVE'
                ORDER BY start_date DESC, period_id DESC
            ");
            $stmt->execute();
            $allPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Platform admin sees all periods
            if (!$userId || isPlatformAdmin($userId)) {
                return $allPeriods;
            }

            // PRIORITY 1: Explicit period_id grants in user_organization_access (ONLY if column exists)
            if (hasUoaPeriodColumn($pdo)) {
                $sql = "
                    SELECT DISTINCT period_id
                    FROM user_organization_access
                    WHERE user_id = :uid
                      AND period_id IS NOT NULL
                      AND UPPER(status) = 'ACTIVE'
                      AND CURRENT_DATE >= valid_from
                      AND (valid_to IS NULL OR CURRENT_DATE <= valid_to)
                ";
                $params = [':uid' => $userId];

                if (!empty($locationType) && !empty($locationId)) {
                    if (strtolower($locationType) === 'plant') {
                        $sql .= " AND (plant_id = :loc_id OR (plant_id IS NULL AND warehouse_id IS NULL))";
                        $params[':loc_id'] = $locationId;
                    } elseif (strtolower($locationType) === 'warehouse') {
                        $sql .= " AND (warehouse_id = :loc_id OR (warehouse_id IS NULL AND plant_id IS NULL))";
                        $params[':loc_id'] = $locationId;
                    }
                }

                $explicitStmt = $pdo->prepare($sql);
                $explicitStmt->execute($params);
                $explicitPeriodIds = $explicitStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

                if (!empty($explicitPeriodIds)) {
                    $allowedMap = array_flip(array_map('intval', $explicitPeriodIds));
                    return array_values(array_filter($allPeriods, function($p) use ($allowedMap) {
                        return isset($allowedMap[(int)$p['period_id']]);
                    }));
                }
            }

            // PRIORITY 2: Date-range overlap fallback (from effective organization scope)
            $scope = getUserEffectiveOrganizationScope($userId);
            if (!empty($scope['period_ids'])) {
                $allowedMap = array_flip($scope['period_ids']);
                $filtered = array_values(array_filter($allPeriods, function($p) use ($allowedMap) {
                    return isset($allowedMap[(int)$p['period_id']]);
                }));
                if (!empty($filtered)) {
                    return $filtered;
                }
            }

            // No restriction or broad validity — return all active periods
            return $allPeriods;
        } catch (Throwable $e) {
            error_log('Error fetching periods: ' . $e->getMessage());
            // Graceful fallback to avoid blank screens
            try {
                if (isset($pdo) && $pdo instanceof PDO) {
                    $stmt = $pdo->query("SELECT period_id, period_name, start_date, end_date, is_locked, status FROM periods WHERE UPPER(status) = 'ACTIVE' ORDER BY start_date DESC");
                    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Throwable $e2) {
                // Ignore secondary error
            }
            return [];
        }
    }
}

if (!function_exists('userCanAccessPeriod')) {
    function userCanAccessPeriod($periodId, $userId = null)
    {
        if (!$periodId) return false;
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        $periods = getUserAccessiblePeriods(null, $userId);
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
                    INNER JOIN role_menu rm ON rm.menu_id = m.menu_id
                    INNER JOIN roles r ON r.role_id = rm.role_id
                    WHERE r.role_code = 'PLATFORM_ADMIN'
                      AND m.status = 'Active'
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
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return false;
        }

        if (isPlatformAdmin($userId)) {
            return true;
        }

        $menus = getUserAuthorizedMenus($userId);
        $cleanTarget = strtolower(trim($menuRouteOrCode));
        $cleanTargetNorm = str_replace(['centre', '-', '_', '/', '.php', ' '], ['center', '', '', '', '', ''], $cleanTarget);

        $aliasMap = [
            'upload_center' => ['upload_centre', 'upload-centre.php', '/data/upload', 'data/upload', 'upload', 'data_management', '/data'],
            'upload_centre' => ['upload_center', 'upload-centre.php', '/data/upload', 'data/upload', 'upload', 'data_management', '/data'],
            'mapping' => ['mapping_center', 'mapping_centre', 'mapping-centre.php', '/data/mapping', 'data/mapping', 'data_management', '/data'],
            'mapping_centre' => ['mapping', 'mapping_center', 'mapping-centre.php', '/data/mapping', 'data/mapping', 'data_management', '/data'],
            'import_history' => ['import-history.php', 'upload_center', 'upload_centre', 'data_management', '/data'],
            'audit_logs' => ['audit-logs.php', 'audit', 'audit_trail', 'audit_desk', 'reconciliation', '/audit'],
            'master_groups' => ['master-groups.php', 'data_management', 'settings'],
        ];

        foreach ($menus as $m) {
            $mCode = strtolower(trim($m['menu_code'] ?? ''));
            $mRoute = strtolower(trim($m['route'] ?? ''));
            $mCodeNorm = str_replace(['centre', '-', '_', '/', '.php', ' '], ['center', '', '', '', '', ''], $mCode);
            $mRouteNorm = str_replace(['centre', '-', '_', '/', '.php', ' '], ['center', '', '', '', '', ''], $mRoute);

            if ($mCode === $cleanTarget || $mRoute === $cleanTarget) {
                return true;
            }

            if ($mCodeNorm !== '' && ($mCodeNorm === $cleanTargetNorm || str_contains($cleanTargetNorm, $mCodeNorm) || str_contains($mCodeNorm, $cleanTargetNorm))) {
                return true;
            }

            if ($mRouteNorm !== '' && ($mRouteNorm === $cleanTargetNorm || str_contains($mRouteNorm, $cleanTargetNorm) || str_contains($cleanTargetNorm, $mRouteNorm))) {
                return true;
            }

            if (isset($aliasMap[$cleanTarget])) {
                foreach ($aliasMap[$cleanTarget] as $alt) {
                    $normAlt = str_replace(['centre', '-', '_', '/', '.php', ' '], ['center', '', '', '', '', ''], strtolower($alt));
                    if ($mCode === strtolower($alt) || $mRoute === strtolower($alt) || $mCodeNorm === $normAlt || $mRouteNorm === $normAlt) {
                        return true;
                    }
                }
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
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if ($userId && isPlatformAdmin($userId)) {
            return true;
        }

        $permissions = getUserAuthorizedPermissions($userId);
        $normReqMenu = $menuCode ? str_replace(['centre', '-', '_', '/', ' '], ['center', '', '', '', ''], strtolower(trim($menuCode))) : null;

        foreach ($permissions as $p) {
            if (strcasecmp($p['permission_code'], $permissionCode) === 0) {
                if ($menuCode === null) {
                    return true;
                }
                if (strcasecmp($p['menu_code'], $menuCode) === 0) {
                    return true;
                }
                $normPermMenu = str_replace(['centre', '-', '_', '/', ' '], ['center', '', '', '', ''], strtolower(trim($p['menu_code'] ?? '')));
                if ($normReqMenu !== '' && ($normPermMenu === $normReqMenu || str_contains($normPermMenu, $normReqMenu) || str_contains($normReqMenu, $normPermMenu))) {
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

/* ============================================================
   CUSTOMER ADMIN & HIERARCHICAL ACCESS CONTROL ENGINE
   ============================================================ */

if (!function_exists('getUserCustomerTenantId')) {
    /**
     * Resolves the primary customer tenant ID for a user.
     * Platform admins return null (cross-tenant global scope).
     */
    function getUserCustomerTenantId($userId)
    {
        $uid = (int)$userId;
        if ($uid <= 0) return null;

        if (isPlatformAdmin($uid)) {
            return null;
        }

        try {
            $pdo = getDbConnection();

            // 1. Direct active customer assignment in user_organization_access
            $stmt = $pdo->prepare("
                SELECT customer_id 
                FROM user_organization_access 
                WHERE user_id = :uid 
                  AND customer_id IS NOT NULL 
                  AND UPPER(status) = 'ACTIVE'
                  AND CURRENT_DATE >= valid_from
                  AND (valid_to IS NULL OR CURRENT_DATE <= valid_to)
                ORDER BY access_id ASC 
                LIMIT 1
            ");
            $stmt->execute([':uid' => $uid]);
            $cid = $stmt->fetchColumn();
            if ($cid) return (int)$cid;

            // 2. Inherited through company assignment
            $stmt = $pdo->prepare("
                SELECT c.customer_id 
                FROM user_organization_access uoa
                INNER JOIN companies c ON c.company_id = uoa.company_id
                WHERE uoa.user_id = :uid 
                  AND UPPER(uoa.status) = 'ACTIVE'
                  AND CURRENT_DATE >= uoa.valid_from
                  AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
                LIMIT 1
            ");
            $stmt->execute([':uid' => $uid]);
            $cid = $stmt->fetchColumn();
            if ($cid) return (int)$cid;

            // 3. Fallback: check creator's customer tenant
            $stmt = $pdo->prepare("
                SELECT uoa.customer_id
                FROM users u
                INNER JOIN user_organization_access uoa ON uoa.user_id = u.created_by
                WHERE u.user_id = :uid 
                  AND uoa.customer_id IS NOT NULL 
                  AND UPPER(uoa.status) = 'ACTIVE'
                LIMIT 1
            ");
            $stmt->execute([':uid' => $uid]);
            $cid = $stmt->fetchColumn();
            if ($cid) return (int)$cid;

            return null;
        } catch (Throwable $e) {
            error_log('Error in getUserCustomerTenantId: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('getUserEffectiveOrganizationScope')) {
    /**
     * Resolves the complete effective organizational scope for any user.
     * Computes direct assignments and downward inheritance through the 5 levels.
     */
    function getUserEffectiveOrganizationScope($userId)
    {
        $uid = (int)$userId;
        if ($uid <= 0) {
            return [
                'is_global_admin'     => false,
                'tenant_customer_id'  => null,
                'customer_ids'        => [],
                'company_ids'         => [],
                'division_ids'        => [],
                'plant_ids'           => [],
                'warehouse_ids'       => [],
                'period_ids'          => [],
                'direct_assignments'  => []
            ];
        }

        try {
            $pdo = getDbConnection();

            if (isPlatformAdmin($uid)) {
                // Platform admin has universal cross-tenant scope
                $custIds = $pdo->query("SELECT customer_id FROM customers WHERE UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $compIds = $pdo->query("SELECT company_id FROM companies WHERE UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $divIds  = $pdo->query("SELECT division_id FROM divisions WHERE UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $plantIds= $pdo->query("SELECT plant_id FROM plants WHERE UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $whIds   = $pdo->query("SELECT warehouse_id FROM warehouses WHERE UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $perIds  = $pdo->query("SELECT period_id FROM periods WHERE UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];

                return [
                    'is_global_admin'     => true,
                    'tenant_customer_id'  => null,
                    'customer_ids'        => array_map('intval', $custIds),
                    'company_ids'         => array_map('intval', $compIds),
                    'division_ids'        => array_map('intval', $divIds),
                    'plant_ids'           => array_map('intval', $plantIds),
                    'warehouse_ids'       => array_map('intval', $whIds),
                    'period_ids'          => array_map('intval', $perIds),
                    'direct_assignments'  => [[
                        'access_id'      => 0,
                        'level'          => 'GLOBAL',
                        'customer_id'    => null,
                        'customer_name'  => 'All Customers (Platform Global)',
                        'company_id'     => null,
                        'company_name'   => 'All Companies',
                        'division_id'    => null,
                        'division_name'  => 'All Divisions',
                        'plant_id'       => null,
                        'plant_name'     => 'All Plants',
                        'warehouse_id'   => null,
                        'warehouse_name' => 'All Warehouses'
                    ]]
                ];
            }

            // Fetch direct active assignments
            $stmt = $pdo->prepare("
                SELECT uoa.*,
                       cust.customer_name, cust.customer_code,
                       c.company_name, c.company_code,
                       d.division_name, d.division_code,
                       p.plant_name, p.plant_code,
                       w.warehouse_name, w.warehouse_code
                FROM user_organization_access uoa
                LEFT JOIN customers cust ON cust.customer_id = uoa.customer_id
                LEFT JOIN companies c ON c.company_id = uoa.company_id
                LEFT JOIN divisions d ON d.division_id = uoa.division_id
                LEFT JOIN plants p ON p.plant_id = uoa.plant_id
                LEFT JOIN warehouses w ON w.warehouse_id = uoa.warehouse_id
                WHERE uoa.user_id = :uid
                  AND UPPER(uoa.status) = 'ACTIVE'
                  AND CURRENT_DATE >= uoa.valid_from
                  AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
                ORDER BY uoa.access_id ASC
            ");
            $stmt->execute([':uid' => $uid]);
            $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $tenantCustomerId = getUserCustomerTenantId($uid);

            $customerIds  = [];
            $companyIds   = [];
            $divisionIds  = [];
            $plantIds     = [];
            $warehouseIds = [];
            $directList   = [];

            foreach ($rawRows as $row) {
                $level = 'CUSTOMER';
                if (!empty($row['warehouse_id'])) {
                    $level = 'WAREHOUSE';
                    $warehouseIds[] = (int)$row['warehouse_id'];
                } elseif (!empty($row['plant_id'])) {
                    $level = 'PLANT';
                    $plantIds[] = (int)$row['plant_id'];
                } elseif (!empty($row['division_id'])) {
                    $level = 'DIVISION';
                    $divisionIds[] = (int)$row['division_id'];
                } elseif (!empty($row['company_id'])) {
                    $level = 'COMPANY';
                    $companyIds[] = (int)$row['company_id'];
                } elseif (!empty($row['customer_id'])) {
                    $level = 'CUSTOMER';
                    $customerIds[] = (int)$row['customer_id'];
                }

                $row['level'] = $level;
                $directList[] = $row;
            }

            // Downward Inheritance Calculation:
            // 1. Customer level -> inherits all child companies
            if (!empty($customerIds)) {
                $inCust = implode(',', array_map('intval', array_unique($customerIds)));
                $childComps = $pdo->query("SELECT company_id FROM companies WHERE customer_id IN ($inCust) AND UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $companyIds = array_merge($companyIds, array_map('intval', $childComps));
            }

            // 2. Company level -> inherits all child divisions
            if (!empty($companyIds)) {
                $inComp = implode(',', array_map('intval', array_unique($companyIds)));
                $childDivs = $pdo->query("SELECT division_id FROM divisions WHERE company_id IN ($inComp) AND UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $divisionIds = array_merge($divisionIds, array_map('intval', $childDivs));
            }

            // 3. Division level -> inherits all child plants
            if (!empty($divisionIds)) {
                $inDiv = implode(',', array_map('intval', array_unique($divisionIds)));
                $childPlants = $pdo->query("SELECT plant_id FROM plants WHERE division_id IN ($inDiv) AND UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $plantIds = array_merge($plantIds, array_map('intval', $childPlants));
            }

            // 4. Plant level -> inherits all child warehouses
            if (!empty($plantIds)) {
                $inPlant = implode(',', array_map('intval', array_unique($plantIds)));
                $childWhs = $pdo->query("SELECT warehouse_id FROM warehouses WHERE plant_id IN ($inPlant) AND UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $warehouseIds = array_merge($warehouseIds, array_map('intval', $childWhs));
            }

            // Upward Resolution for hierarchy context:
            if (!empty($warehouseIds)) {
                $inWh = implode(',', array_map('intval', array_unique($warehouseIds)));
                $parentPlants = $pdo->query("SELECT plant_id FROM warehouses WHERE warehouse_id IN ($inWh)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $plantIds = array_merge($plantIds, array_map('intval', $parentPlants));
            }

            if (!empty($plantIds)) {
                $inPl = implode(',', array_map('intval', array_unique($plantIds)));
                $parentDivs = $pdo->query("SELECT division_id FROM plants WHERE plant_id IN ($inPl)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $divisionIds = array_merge($divisionIds, array_map('intval', $parentDivs));
            }

            if (!empty($divisionIds)) {
                $inDv = implode(',', array_map('intval', array_unique($divisionIds)));
                $parentComps = $pdo->query("SELECT company_id FROM divisions WHERE division_id IN ($inDv)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $companyIds = array_merge($companyIds, array_map('intval', $parentComps));
            }

            if (!empty($companyIds)) {
                $inCp = implode(',', array_map('intval', array_unique($companyIds)));
                $parentCusts = $pdo->query("SELECT customer_id FROM companies WHERE company_id IN ($inCp)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $customerIds = array_merge($customerIds, array_map('intval', $parentCusts));
            }

            // Deduplicate all ID arrays
            $customerIds  = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
            $companyIds   = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
            $divisionIds  = array_values(array_unique(array_filter(array_map('intval', $divisionIds))));
            $plantIds     = array_values(array_unique(array_filter(array_map('intval', $plantIds))));
            $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds))));

            // Accessible periods:
            // PRIORITY 1 — Explicit period_id rows in user_organization_access (ONLY if column exists)
            $periodIds = [];
            $hasUoaPeriod = hasUoaPeriodColumn($pdo);

            if ($hasUoaPeriod) {
                $explicitPeriodStmt = $pdo->prepare("
                    SELECT DISTINCT period_id
                    FROM user_organization_access
                    WHERE user_id = :uid
                      AND period_id IS NOT NULL
                      AND UPPER(status) = 'ACTIVE'
                      AND CURRENT_DATE >= valid_from
                      AND (valid_to IS NULL OR CURRENT_DATE <= valid_to)
                ");
                $explicitPeriodStmt->execute([':uid' => $uid]);
                $explicitPeriodIds = $explicitPeriodStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

                if (!empty($explicitPeriodIds)) {
                    $periodIds = array_values(array_unique(array_map('intval', $explicitPeriodIds)));
                }
            }

            if (empty($periodIds)) {
                // PRIORITY 2 — Date-range overlap fallback (legacy)
                $perRows = $pdo->query("SELECT period_id, start_date, end_date FROM periods WHERE UPPER(status) = 'ACTIVE'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $hasDateFilter = false;
                foreach ($directList as $da) {
                    if (!empty($da['valid_from']) || !empty($da['valid_to'])) {
                        $hasDateFilter = true;
                        break;
                    }
                }
                if ($hasDateFilter) {
                    foreach ($perRows as $pr) {
                        $pStart = $pr['start_date'];
                        $pEnd   = $pr['end_date'];
                        $matches = false;
                        foreach ($directList as $da) {
                            $vFrom = !empty($da['valid_from']) ? $da['valid_from'] : '1900-01-01';
                            $vTo   = !empty($da['valid_to']) ? $da['valid_to'] : '2099-12-31';
                            if ($vFrom <= $pEnd && $vTo >= $pStart) {
                                $matches = true;
                                break;
                            }
                        }
                        if ($matches) $periodIds[] = (int)$pr['period_id'];
                    }
                } else {
                    $periodIds = array_map(function($pr) { return (int)$pr['period_id']; }, $perRows);
                }
            }

            return [
                'is_global_admin'     => false,
                'tenant_customer_id'  => $tenantCustomerId,
                'customer_ids'        => $customerIds,
                'company_ids'         => $companyIds,
                'division_ids'        => $divisionIds,
                'plant_ids'           => $plantIds,
                'warehouse_ids'       => $warehouseIds,
                'period_ids'          => $periodIds,
                'direct_assignments'  => $directList
            ];
        } catch (Throwable $e) {
            error_log('Error in getUserEffectiveOrganizationScope: ' . $e->getMessage());
            return [
                'is_global_admin'     => false,
                'tenant_customer_id'  => null,
                'customer_ids'        => [],
                'company_ids'         => [],
                'division_ids'        => [],
                'plant_ids'           => [],
                'warehouse_ids'       => [],
                'period_ids'          => [],
                'direct_assignments'  => []
            ];
        }
    }
}

if (!function_exists('canAdminManageUser')) {
    /**
     * Enforces tenant isolation and administrative boundaries.
     * Prevents Customer Admins from managing users outside their customer scope or platform admins.
     */
    function canAdminManageUser($adminUserId, $targetUserId)
    {
        $adminId = (int)$adminUserId;
        $targetId = (int)$targetUserId;

        if ($adminId <= 0 || $targetId <= 0) return false;

        // Platform Admin can manage anyone
        if (isPlatformAdmin($adminId)) {
            return true;
        }

        // Customer Admin CANNOT manage any Platform Admin
        if (isPlatformAdmin($targetId)) {
            return false;
        }

        // A user can manage their own profile info
        if ($adminId === $targetId) {
            return true;
        }

        $adminTenantId = getUserCustomerTenantId($adminId);
        if (!$adminTenantId) {
            return false;
        }

        try {
            $pdo = getDbConnection();

            // Customer Admin CANNOT manage any Platform or CMA accounts (Aarav, Chanchal, etc.)
            $roleStmt = $pdo->prepare("
                SELECT r.role_code, c.category_code
                FROM user_roles ur
                JOIN roles r ON r.role_id = ur.role_id
                LEFT JOIN categories c ON c.category_id = r.category_id
                WHERE ur.user_id = :tid AND UPPER(ur.status) = 'ACTIVE'
            ");
            $roleStmt->execute([':tid' => $targetId]);
            $targetRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($targetRoles as $tr) {
                $roleCode = strtoupper(trim($tr['role_code'] ?? ''));
                $catCode = strtoupper(trim($tr['category_code'] ?? ''));
                if (in_array($catCode, ['PLAT_ADMIN', 'CMA_ADMIN'], true) ||
                    in_array($roleCode, ['PLATFORM_ADMIN', 'CMA_ADMIN', 'CMA_USER'], true)) {
                    return false;
                }
            }

            // Strict Creator Isolation: Customer Admin can only manage users created by them
            $stmt = $pdo->prepare("SELECT created_by FROM users WHERE user_id = :tid");
            $stmt->execute([':tid' => $targetId]);
            $creatorId = $stmt->fetchColumn();
            if ($creatorId && (int)$creatorId === $adminId) {
                return true;
            }

            return false;
        } catch (Throwable $e) {
            error_log('Error in canAdminManageUser: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('validateScopeAssignment')) {
    /**
     * Prevents privilege escalation.
     * Guarantees that an administrator cannot assign access to an entity outside their own scope.
     */
    function validateScopeAssignment($adminUserId, array $assignmentList)
    {
        $adminId = (int)$adminUserId;
        if ($adminId <= 0) {
            throw new Exception('Unauthorized: Invalid administrator identity.');
        }

        // Platform admins are authorized for all entities
        if (isPlatformAdmin($adminId)) {
            return true;
        }

        $adminScope = getUserEffectiveOrganizationScope($adminId);
        $adminTenantId = $adminScope['tenant_customer_id'];

        foreach ($assignmentList as $idx => $entry) {
            $custId = !empty($entry['customer_id']) ? (int)$entry['customer_id'] : null;
            $compId = !empty($entry['company_id']) ? (int)$entry['company_id'] : null;
            $divId  = !empty($entry['division_id']) ? (int)$entry['division_id'] : null;
            $plantId= !empty($entry['plant_id']) ? (int)$entry['plant_id'] : null;
            $whId   = !empty($entry['warehouse_id']) ? (int)$entry['warehouse_id'] : null;

            // 1. Tenant boundary check
            if ($custId !== null && $adminTenantId !== null && $custId !== (int)$adminTenantId) {
                throw new Exception("Security Violation: You cannot assign access to customer tenant ID {$custId}.");
            }

            // 2. Company scope check
            if ($compId !== null && !in_array($compId, $adminScope['company_ids'], true)) {
                throw new Exception("Security Violation: Company ID {$compId} is outside your administrative scope.");
            }

            // 3. Division scope check
            if ($divId !== null && !in_array($divId, $adminScope['division_ids'], true)) {
                throw new Exception("Security Violation: Division ID {$divId} is outside your administrative scope.");
            }

            // 4. Plant scope check
            if ($plantId !== null && !in_array($plantId, $adminScope['plant_ids'], true)) {
                throw new Exception("Security Violation: Plant ID {$plantId} is outside your administrative scope.");
            }

            // 5. Warehouse scope check
            if ($whId !== null && !in_array($whId, $adminScope['warehouse_ids'], true)) {
                throw new Exception("Security Violation: Warehouse ID {$whId} is outside your administrative scope.");
            }
        }

        return true;
    }
}

if (!function_exists('saveUserOrganizationAccess')) {
    /**
     * Saves user organizational access with audit logging and privilege validation.
     */
    function saveUserOrganizationAccess($targetUserId, array $assignments, $adminUserId = null)
    {
        $targetId = (int)$targetUserId;
        if ($targetId <= 0) {
            throw new Exception('Invalid target user ID.');
        }

        $adminId = $adminUserId !== null ? (int)$adminUserId : (int)($_SESSION['user_id'] ?? 0);

        // Enforce administrative boundaries
        if ($adminId > 0 && !canAdminManageUser($adminId, $targetId)) {
            throw new Exception('Permission Denied: You cannot manage organizational access for this user.');
        }

        // Prevent privilege escalation
        if ($adminId > 0) {
            validateScopeAssignment($adminId, $assignments);
        }

        $pdo = getDbConnection();

        try {
            $pdo->beginTransaction();

            // Remove existing assignments within the administrator's management scope
            if (!isPlatformAdmin($adminId)) {
                $adminTenantId = getUserCustomerTenantId($adminId);
                $delStmt = $pdo->prepare("
                    DELETE FROM user_organization_access 
                    WHERE user_id = :uid 
                      AND (customer_id = :cid OR customer_id IS NULL)
                ");
                $delStmt->execute([':uid' => $targetId, ':cid' => $adminTenantId]);
            } else {
                $delStmt = $pdo->prepare("DELETE FROM user_organization_access WHERE user_id = :uid");
                $delStmt->execute([':uid' => $targetId]);
            }

            // Insert new validated assignments (defensive against absence of period_id column)
            $hasUoaPeriod = hasUoaPeriodColumn($pdo);
            $insertCols = "user_id, customer_id, company_id, division_id, plant_id, warehouse_id" . ($hasUoaPeriod ? ", period_id" : "") . ", valid_from, valid_to, status";
            $insertVals = ":user_id, :customer_id, :company_id, :division_id, :plant_id, :warehouse_id" . ($hasUoaPeriod ? ", :period_id" : "") . ", :valid_from, :valid_to, 'Active'";

            $insStmt = $pdo->prepare("INSERT INTO user_organization_access ($insertCols) VALUES ($insertVals)");

            $savedCount = 0;
            foreach ($assignments as $a) {
                $custId   = !empty($a['customer_id']) ? (int)$a['customer_id'] : (!isPlatformAdmin($adminId) ? getUserCustomerTenantId($adminId) : null);
                $compId   = !empty($a['company_id']) ? (int)$a['company_id'] : null;
                $divId    = !empty($a['division_id']) ? (int)$a['division_id'] : null;
                $plantId  = !empty($a['plant_id']) ? (int)$a['plant_id'] : null;
                $whId     = !empty($a['warehouse_id']) ? (int)$a['warehouse_id'] : null;
                $periodId = !empty($a['period_id']) ? (int)$a['period_id'] : null;
                $vf       = !empty($a['valid_from']) ? trim($a['valid_from']) : date('Y-m-d');
                $vt       = !empty($a['valid_to']) ? trim($a['valid_to']) : null;

                // A period-only row is valid if period column exists; org-access rows require at least custId or compId
                if (!$hasUoaPeriod && $periodId && !$custId && !$compId) {
                    continue;
                }
                if (!$periodId && !$custId && !$compId) {
                    continue;
                }

                $params = [
                    ':user_id'      => $targetId,
                    ':customer_id'  => $custId,
                    ':company_id'   => $compId,
                    ':division_id'  => $divId,
                    ':plant_id'     => $plantId,
                    ':warehouse_id' => $whId,
                    ':valid_from'   => $vf,
                    ':valid_to'     => $vt
                ];
                if ($hasUoaPeriod) {
                    $params[':period_id'] = $periodId;
                }

                $insStmt->execute($params);
                $savedCount++;
            }

            // Audit log entry
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_log 
                (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES 
                (:uid, 'user_organization_access', :eid, 'UPDATE', NULL, :new_val, CURRENT_TIMESTAMP, :ip)
            ");
            $auditStmt->execute([
                ':uid'     => $adminId > 0 ? $adminId : null,
                ':eid'     => $targetId,
                ':new_val' => 'Updated organizational access scope. Total entries: ' . $savedCount,
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $pdo->commit();
            return ['success' => true, 'count' => $savedCount];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('getUserAccessSummary')) {
    /**
     * Formats a human-readable display summary of user's access for tables and profile cards.
     */
    function getUserAccessSummary($userId)
    {
        $scope = getUserEffectiveOrganizationScope($userId);

        if ($scope['is_global_admin']) {
            return [
                'badge'        => 'Global (Platform Scope)',
                'direct_count' => 1,
                'companies'    => count($scope['company_ids']),
                'plants'       => count($scope['plant_ids']),
                'warehouses'   => count($scope['warehouse_ids']),
                'direct'       => $scope['direct_assignments']
            ];
        }

        if (empty($scope['direct_assignments'])) {
            return [
                'badge'        => 'No Access Assigned',
                'direct_count' => 0,
                'companies'    => 0,
                'plants'       => 0,
                'warehouses'   => 0,
                'direct'       => []
            ];
        }

        $directLabels = [];
        foreach ($scope['direct_assignments'] as $d) {
            if (!empty($d['warehouse_name'])) {
                $directLabels[] = 'WH: ' . $d['warehouse_name'];
            } elseif (!empty($d['plant_name'])) {
                $directLabels[] = 'Plant: ' . $d['plant_name'];
            } elseif (!empty($d['division_name'])) {
                $directLabels[] = 'Div: ' . $d['division_name'];
            } elseif (!empty($d['company_name'])) {
                $directLabels[] = 'Co: ' . $d['company_name'];
            } elseif (!empty($d['customer_name'])) {
                $directLabels[] = 'Tenant: ' . $d['customer_name'];
            }
        }

        $badgeText = count($directLabels) === 1 ? $directLabels[0] : count($directLabels) . ' Direct Scopes';

        return [
            'badge'        => $badgeText,
            'direct_count' => count($scope['direct_assignments']),
            'companies'    => count($scope['company_ids']),
            'plants'       => count($scope['plant_ids']),
            'warehouses'   => count($scope['warehouse_ids']),
            'direct'       => $scope['direct_assignments']
        ];
    }
}