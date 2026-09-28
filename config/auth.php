<?php
/**
 * Cost360 - Authentication & Role-Based Access Control (RBAC) Helper
 * Connects directly to Neon PostgreSQL auth schema tables.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth() {
        if (!isLoggedIn()) {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $loginUrl = 'login.php';
            if (strpos($script, '/admin/') !== false || strpos($script, '/administration/') !== false) {
                if (preg_match('#/(?:admin|administration)/[^/]+/#', $script) ||
                    preg_match('#/(?:admin|administration)/[^/]+/index\.php#', $script)) {
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

if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

if (!function_exists('getAuthUser')) {
    function getAuthUser() {
        if (!isLoggedIn()) {
            return null;
        }

        static $cachedUser = null;
        if ($cachedUser !== null) {
            return $cachedUser;
        }

        if (isset($_SESSION['auth_cache']['user'])) {
            $cachedUser = $_SESSION['auth_cache']['user'];
            return $cachedUser;
        }

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('
                SELECT user_id, username, email, first_name, last_name,
                       phone as mobile_number, status, is_email_verified, last_login_at, created_at
                FROM users
                WHERE user_id = :user_id
                LIMIT 1
            ');
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $cachedUser = $stmt->fetch();
            if ($cachedUser) {
                $_SESSION['auth_cache']['user'] = $cachedUser;
            }
            return $cachedUser;
        } catch (Exception $e) {
            error_log("Error fetching auth user: " . $e->getMessage());
            return [
                'user_id'    => $_SESSION['user_id'] ?? null,
                'email'      => $_SESSION['email'] ?? '',
                'username'   => $_SESSION['username'] ?? '',
                'first_name' => $_SESSION['first_name'] ?? '',
                'last_name'  => '',
                'status'     => 'ACTIVE'
            ];
        }
    }
}

if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole($userId = null) {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
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
                SELECT r.role_id, r.role_code, r.role_name, r.description, r.status
                FROM roles r
                INNER JOIN user_roles ur ON r.role_id = ur.role_id
                WHERE ur.user_id = :user_id AND r.status = \'Active\' AND ur.status = \'Active\'
                ORDER BY r.role_id ASC
                LIMIT 1
            ');
            $stmt->execute([':user_id' => $userId]);
            $cachedRole = $stmt->fetch();
            $cachedRolesMap[$userId] = $cachedRole ?: null;
            return $cachedRole;
        } catch (Exception $e) {
            error_log("Error fetching user role: " . $e->getMessage());
            return null;
        }
    }
}

// if (!function_exists('getUserAuthorizedMenus')) {
//     function getUserAuthorizedMenus($userId = null) {
//         if ($userId === null) {
//             $userId = $_SESSION['user_id'] ?? null;
//         }

//         if (!$userId) {
//             return [];
//         }

//         static $cachedMenusMap = [];
//         if (isset($cachedMenusMap[$userId])) {
//             return $cachedMenusMap[$userId];
//         }

//         try {
//             $pdo = getDbConnection();
//             $stmt = $pdo->prepare('
//                 SELECT DISTINCT m.menu_id, m.parent_menu_id, m.menu_code, m.menu_name,
//                        m.menu_type, m.route, m.icon, m.display_order, m.status,
//                        rm.can_view, rm.can_create, rm.can_edit, rm.can_delete, rm.can_upload,
//                        rm.can_download, rm.can_approve, rm.can_export, rm.can_print, rm.can_configure
//                 FROM menus m
//                 INNER JOIN role_menu rm ON m.menu_id = rm.menu_id
//                 INNER JOIN user_roles ur ON rm.role_id = ur.role_id
//                 INNER JOIN roles r ON ur.role_id = r.role_id
//                 WHERE ur.user_id = :user_id
//                   AND m.status = \'Active\'
//                   AND rm.is_active = true
//                   AND r.status = \'Active\'
//                   AND ur.status = \'Active\'
//                 ORDER BY m.display_order ASC, m.menu_id ASC
//             ');
//             $stmt->execute([':user_id' => $userId]);
//             $cachedMenus = $stmt->fetchAll();
//             $cachedMenusMap[$userId] = $cachedMenus ?: [];
//             return $cachedMenus;
//         } catch (Exception $e) {
//             error_log("Error fetching authorized menus: " . $e->getMessage());
//             return [];
//         }
//     }
// }

if (!function_exists('getUserAuthorizedMenus')) {
    function getUserAuthorizedMenus($userId = null)
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
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

            /*
             * First get menus directly assigned to the user's
             * active roles.
             *
             * Then recursively add their parent menus so that
             * the sidebar hierarchy remains intact.
             */

            $sql = "
                WITH RECURSIVE authorized_menu_tree AS (

                    /* -----------------------------------------
                       MENUS DIRECTLY ASSIGNED TO USER'S ROLE
                       ----------------------------------------- */

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


                    /* -----------------------------------------
                       ADD PARENT MENUS
                       ----------------------------------------- */

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

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ':user_id' => $userId
            ]);

            $menus =
                $stmt->fetchAll(PDO::FETCH_ASSOC);

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

if (!function_exists('getUserAuthorizedPermissions')) {
    function getUserAuthorizedPermissions($userId = null) {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return [];
        }

        static $cachedPermissions = null;
        if ($cachedPermissions !== null) {
            return $cachedPermissions;
        }

        if (isset($_SESSION['auth_cache']['permissions'])) {
            $cachedPermissions = $_SESSION['auth_cache']['permissions'];
            // Force cache refresh if the old structure is cached
            if (!empty($cachedPermissions) && isset($cachedPermissions[0]['menu_name'])) {
                return $cachedPermissions;
            }
        }

        try {
            $menus = getUserAuthorizedMenus($userId);
            $perms = [];
            foreach ($menus as $m) {
                $mCode = $m['menu_code'];
                $mName = $m['menu_name'];
                if ($m['can_view']) $perms[] = ['permission_code' => 'VIEW', 'permission_name' => 'View', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_create']) $perms[] = ['permission_code' => 'CREATE', 'permission_name' => 'Create', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_edit']) $perms[] = ['permission_code' => 'EDIT', 'permission_name' => 'Edit', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_delete']) $perms[] = ['permission_code' => 'DELETE', 'permission_name' => 'Delete', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_upload']) $perms[] = ['permission_code' => 'UPLOAD', 'permission_name' => 'Upload', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_download']) $perms[] = ['permission_code' => 'DOWNLOAD', 'permission_name' => 'Download', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_approve']) $perms[] = ['permission_code' => 'APPROVE', 'permission_name' => 'Approve', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_export']) $perms[] = ['permission_code' => 'EXPORT', 'permission_name' => 'Export', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_print']) $perms[] = ['permission_code' => 'PRINT', 'permission_name' => 'Print', 'menu_code' => $mCode, 'menu_name' => $mName];
                if ($m['can_configure']) $perms[] = ['permission_code' => 'CONFIGURE', 'permission_name' => 'Configure', 'menu_code' => $mCode, 'menu_name' => $mName];
            }
            $_SESSION['auth_cache']['permissions'] = $perms;
            return $perms;
        } catch (Exception $e) {
            error_log("Error generating user permissions: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('userHasMenuAccess')) {
    function userHasMenuAccess($menuRouteOrCode, $userId = null) {
        $menus = getUserAuthorizedMenus($userId);
        $cleanTarget = strtolower(trim($menuRouteOrCode));
        foreach ($menus as $m) {
            $mCode = strtolower(trim($m['menu_code']));
            $mRoute = strtolower(trim($m['route']));
            if ($mCode === $cleanTarget || $mRoute === $cleanTarget) {
                return true;
            }
            if ($mRoute !== '' && (str_contains($mRoute, $cleanTarget) || str_contains($cleanTarget, $mCode))) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('userHasPermission')) {
    function userHasPermission($permissionCode, $menuCode = null, $userId = null) {
        $permissions = getUserAuthorizedPermissions($userId);
        foreach ($permissions as $p) {
            if (strcasecmp($p['permission_code'], $permissionCode) === 0) {
                if ($menuCode === null || strcasecmp($p['menu_code'], $menuCode) === 0) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission($permissionCode, $menuCode = null) {
        requireAuth();
        if (!userHasPermission($permissionCode, $menuCode)) {
            header('HTTP/1.1 403 Forbidden');
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access Denied - Cost360</title><style>body{font-family:sans-serif;background:#0A0E17;color:#fff;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}.card{background:#1E293B;padding:2rem;border-radius:12px;text-align:center;max-width:400px;}h1{color:#F87171;}a{color:#00CFEF;text-decoration:none;font-weight:600;}</style></head><body><div class="card"><h1>Access Denied</h1><p>You do not have permission (' . htmlspecialchars($permissionCode) . ') to access this resource.</p><p><a href="dashboard.php">Return to Dashboard</a></p></div></body></html>';
            exit;
        }
    }
}