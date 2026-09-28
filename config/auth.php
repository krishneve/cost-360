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
        if (!isLoggedIn()) {

            $script = $_SERVER['SCRIPT_NAME'] ?? '';

            $loginUrl = 'login.php';

            if (
                strpos($script, '/admin/') !== false ||
                strpos($script, '/administration/') !== false
            ) {

                if (
                    preg_match(
                        '#/(?:admin|administration)/[^/]+/#',
                        $script
                    ) ||
                    preg_match(
                        '#/(?:admin|administration)/[^/]+/index\.php#',
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
        requireAuth();

        /*
         * Platform Admin does not need to select a company.
         *
         * Platform Admin works across the Cost360 platform
         * and is responsible for administration and assigning
         * organization access to users.
         */

        if (isPlatformAdmin()) {

            return true;
        }


        /*
         * Normal users must have an active company.
         */

        if (
            !isset($_SESSION['active_company_id']) ||
            empty($_SESSION['active_company_id'])
        ) {

            header(
                'Location: company-selection.php'
            );

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

if (!function_exists('getUserAccessibleCompanies')) {

    function getUserAccessibleCompanies($userId = null)
    {
        if ($userId === null) {
            $userId =
                $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return [];
        }

        static $companyCache = [];

        if (isset($companyCache[$userId])) {
            return $companyCache[$userId];
        }

        try {

            $pdo = getDbConnection();

            /*
             * The organization hierarchy is:
             *
             * customers
             *    ↓
             * companies
             *    ↓
             * divisions
             *    ↓
             * plants
             *    ↓
             * warehouses
             *
             * user_organization_access may be assigned at
             * any of these levels.
             */

            $sql = '

                SELECT DISTINCT

                    c.company_id,
                    c.company_code,
                    c.company_name,
                    c.customer_id,
                    c.address,
                    c.status

                FROM companies c

                INNER JOIN user_organization_access uoa

                    ON
                    (
                        /* Customer-level access */
                        (
                            uoa.customer_id = c.customer_id
                            AND uoa.company_id IS NULL
                            AND uoa.division_id IS NULL
                            AND uoa.plant_id IS NULL
                            AND uoa.warehouse_id IS NULL
                        )

                        OR

                        /* Company-level access */
                        (
                            uoa.company_id = c.company_id
                        )

                        OR

                        /* Division-level access */
                        (
                            uoa.division_id IS NOT NULL

                            AND EXISTS (
                                SELECT 1
                                FROM divisions d
                                WHERE d.division_id =
                                      uoa.division_id
                                  AND d.company_id =
                                      c.company_id
                            )
                        )

                        OR

                        /* Plant-level access */
                        (
                            uoa.plant_id IS NOT NULL

                            AND EXISTS (
                                SELECT 1

                                FROM plants p

                                INNER JOIN divisions d
                                    ON d.division_id =
                                       p.division_id

                                WHERE p.plant_id =
                                      uoa.plant_id

                                  AND d.company_id =
                                      c.company_id
                            )
                        )

                        OR

                        /* Warehouse-level access */
                        (
                            uoa.warehouse_id IS NOT NULL

                            AND EXISTS (
                                SELECT 1

                                FROM warehouses w

                                INNER JOIN plants p
                                    ON p.plant_id =
                                       w.plant_id

                                INNER JOIN divisions d
                                    ON d.division_id =
                                       p.division_id

                                WHERE w.warehouse_id =
                                      uoa.warehouse_id

                                  AND d.company_id =
                                      c.company_id
                            )
                        )
                    )

                WHERE uoa.user_id = :user_id

                  AND UPPER(uoa.status) = \'ACTIVE\'

                  AND CURRENT_DATE >= uoa.valid_from

                  AND (
                      uoa.valid_to IS NULL
                      OR CURRENT_DATE <= uoa.valid_to
                  )

                  AND UPPER(c.status) = \'ACTIVE\'

                ORDER BY
                    c.company_name ASC,
                    c.company_id ASC
            ';

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ':user_id' => $userId
            ]);

            $companies =
                $stmt->fetchAll(PDO::FETCH_ASSOC);

            $companyCache[$userId] =
                $companies ?: [];

            return $companyCache[$userId];

        } catch (Throwable $e) {

            error_log(
                'Error fetching accessible companies: ' .
                $e->getMessage()
            );

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

                $_SESSION['active_company_id'] =
                    $company['company_id'];

                $_SESSION['active_company_code'] =
                    $company['company_code'];

                $_SESSION['active_company_name'] =
                    $company['company_name'];

                $_SESSION['active_customer_id'] =
                    $company['customer_id'];

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
                $_SESSION['active_customer_id'] ?? null
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
            $_SESSION['active_customer_id']
        );
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


                    UNION


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

                SELECT DISTINCT

                    menu_id,
                    parent_menu_id,
                    menu_code,
                    menu_name,
                    menu_type,
                    route,
                    icon,
                    display_order,
                    status,

                    can_view,
                    can_create,
                    can_edit,
                    can_delete,
                    can_upload,
                    can_download,
                    can_approve,
                    can_export,
                    can_print,
                    can_configure

                FROM authorized_menu_tree

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