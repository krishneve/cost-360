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
            header('Location: login.php');
            exit;
        }
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

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('
                SELECT user_id, username, email, first_name, last_name,
                       mobile_number, status, is_email_verified, last_login_at, created_at
                FROM auth.users
                WHERE user_id = :user_id
                LIMIT 1
            ');
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $cachedUser = $stmt->fetch();
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

        static $cachedRole = null;
        if ($cachedRole !== null) {
            return $cachedRole;
        }

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('
                SELECT r.role_id, r.role_code, r.role_name, r.description, r.is_system_role, r.is_active, ur.is_primary_role
                FROM auth.roles r
                INNER JOIN auth.user_role ur ON r.role_id = ur.role_id
                WHERE ur.user_id = :user_id AND r.is_active = true
                ORDER BY ur.is_primary_role DESC, r.role_id ASC
                LIMIT 1
            ');
            $stmt->execute([':user_id' => $userId]);
            $cachedRole = $stmt->fetch();
            return $cachedRole;
        } catch (Exception $e) {
            error_log("Error fetching user role: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('getUserAuthorizedMenus')) {
    function getUserAuthorizedMenus($userId = null) {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return [];
        }

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('
                SELECT DISTINCT m.menu_id, m.parent_menu_id, m.menu_code, m.menu_name,
                       m.menu_type, m.route, m.icon, m.display_order, m.is_visible, m.is_active, m.description
                FROM auth.menus m
                INNER JOIN auth.role_menu rm ON m.menu_id = rm.menu_id
                INNER JOIN auth.user_role ur ON rm.role_id = ur.role_id
                INNER JOIN auth.roles r ON ur.role_id = r.role_id
                WHERE ur.user_id = :user_id
                  AND m.is_active = true
                  AND m.is_visible = true
                  AND rm.is_visible = true
                  AND r.is_active = true
                ORDER BY m.display_order ASC, m.menu_id ASC
            ');
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error fetching authorized menus: " . $e->getMessage());
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

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('
                SELECT DISTINCT rmp.role_id, rmp.menu_id, rmp.permission_id,
                       m.menu_code, m.menu_name, p.permission_code, p.permission_name, p.description
                FROM auth.role_menu_permission rmp
                INNER JOIN auth.user_role ur ON rmp.role_id = ur.role_id
                INNER JOIN auth.roles r ON ur.role_id = r.role_id
                INNER JOIN auth.menus m ON rmp.menu_id = m.menu_id
                INNER JOIN auth.permissions p ON rmp.permission_id = p.permission_id
                WHERE ur.user_id = :user_id
                  AND r.is_active = true
                  AND m.is_active = true
                  AND p.is_active = true
                ORDER BY m.menu_name ASC, rmp.permission_id ASC
            ');
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error fetching user permissions: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('userHasMenuAccess')) {
    function userHasMenuAccess($menuRouteOrCode, $userId = null) {
        $menus = getUserAuthorizedMenus($userId);
        foreach ($menus as $m) {
            if (strcasecmp($m['menu_code'], $menuRouteOrCode) === 0 || strcasecmp($m['route'], $menuRouteOrCode) === 0) {
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
