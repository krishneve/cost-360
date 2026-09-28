<?php
/**
 * Cost360 - Menu Master API Endpoint
 * Handles CRUD operations, hierarchy validation, and status toggles for auth.menus.
 * Database: Central Neon PostgreSQL (code-360-v1).
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

// Helper to return clean JSON response
function sendJsonResponse($data, $statusCode = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Security check: Must be authenticated
if (!isLoggedIn()) {
    sendJsonResponse(['success' => false, 'error' => 'Unauthorized session. Please log in.'], 401);
}

// Security check: Authorized logged-in session check
$hasAccess = isLoggedIn();

if (!$hasAccess) {
    sendJsonResponse(['success' => false, 'error' => 'Access denied. You do not have permission to manage menus.'], 403);
}

$action = 'list';
$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
} elseif (isset($_GET['action'])) {
    $action = $_GET['action'];
}

/**
 * Recursive Helper: Check if $menuId is an ancestor of $targetParentId to prevent circular dependency
 */
function isParentAncestor($pdo, $menuId, $targetParentId) {
    if (!$targetParentId || $menuId <= 0 || $targetParentId <= 0) {
        return false;
    }

    if ($menuId === $targetParentId) {
        return true;
    }

    $currParentId = $targetParentId;
    $visited = [];

    while ($currParentId) {
        if ($currParentId === $menuId) {
            return true;
        }

        if (in_array($currParentId, $visited, true)) {
            break;
        }
        $visited[] = $currParentId;

        $stmt = $pdo->prepare("SELECT parent_menu_id FROM auth.menus WHERE menu_id = :id LIMIT 1");
        $stmt->execute([':id' => $currParentId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['parent_menu_id'])) {
            break;
        }
        $currParentId = (int)$row['parent_menu_id'];
    }

    return false;
}

/**
 * Helper: Validate route security (prevent dangerous schemes)
 */
function sanitizeRouteScheme($route) {
    if (empty($route)) {
        return '';
    }
    $trimmed = trim($route);
    $lower = strtolower($trimmed);

    if (strpos($lower, 'javascript:') === 0 ||
        strpos($lower, 'data:') === 0 ||
        strpos($lower, 'vbscript:') === 0) {
        throw new Exception("Invalid route URI scheme.");
    }

    return $trimmed;
}

try {
    $pdo = getDbConnection();

    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $typeFilter = trim($_GET['menu_type'] ?? 'ALL');
            $statusFilter = trim($_GET['status'] ?? 'ALL');
            $visibilityFilter = trim($_GET['visibility'] ?? 'ALL');
            $parentFilter = trim($_GET['parent_id'] ?? 'ALL');

            $query = "
                SELECT m.menu_id, m.parent_menu_id, m.menu_code, m.menu_name,
                       m.menu_type, m.route, m.icon, m.display_order, m.is_visible,
                       m.is_active, m.description,
                       p.menu_name AS parent_menu_name,
                       p.menu_code AS parent_menu_code
                FROM auth.menus m
                LEFT JOIN auth.menus p ON m.parent_menu_id = p.menu_id
                WHERE 1=1
            ";

            $params = [];

            if ($search !== '') {
                $query .= " AND (m.menu_code ILIKE :search OR m.menu_name ILIKE :search OR m.route ILIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            if ($typeFilter !== '' && $typeFilter !== 'ALL') {
                $query .= " AND UPPER(m.menu_type) = :menu_type";
                $params[':menu_type'] = strtoupper($typeFilter);
            }

            if ($statusFilter !== '' && $statusFilter !== 'ALL') {
                if (strtoupper($statusFilter) === 'ACTIVE') {
                    $query .= " AND m.is_active = true";
                } elseif (strtoupper($statusFilter) === 'INACTIVE') {
                    $query .= " AND m.is_active = false";
                }
            }

            if ($visibilityFilter !== '' && $visibilityFilter !== 'ALL') {
                if (strtoupper($visibilityFilter) === 'VISIBLE') {
                    $query .= " AND m.is_visible = true";
                } elseif (strtoupper($visibilityFilter) === 'HIDDEN') {
                    $query .= " AND m.is_visible = false";
                }
            }

            if ($parentFilter !== '' && $parentFilter !== 'ALL') {
                if ($parentFilter === 'ROOT') {
                    $query .= " AND m.parent_menu_id IS NULL";
                } else {
                    $query .= " AND m.parent_menu_id = :parent_id";
                    $params[':parent_id'] = (int)$parentFilter;
                }
            }

            $query .= " ORDER BY COALESCE(m.parent_menu_id, m.menu_id) ASC, m.parent_menu_id NULLS FIRST, m.display_order ASC, m.menu_id ASC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $menus = $stmt->fetchAll();

            $formattedMenus = array_map(function($m) {
                return [
                    'menu_id'          => (int)$m['menu_id'],
                    'parent_menu_id'   => $m['parent_menu_id'] ? (int)$m['parent_menu_id'] : null,
                    'parent_menu_name' => $m['parent_menu_name'] ?? 'Root Menu / None',
                    'parent_menu_code' => $m['parent_menu_code'] ?? null,
                    'menu_code'        => $m['menu_code'],
                    'menu_name'        => $m['menu_name'],
                    'menu_type'        => strtoupper($m['menu_type']),
                    'route'            => $m['route'] ?? '',
                    'icon'             => $m['icon'] ?? '',
                    'display_order'    => (int)($m['display_order'] ?? 0),
                    'is_visible'       => (bool)($m['is_visible'] ?? true),
                    'is_active'        => (bool)($m['is_active'] ?? true),
                    'description'      => $m['description'] ?? ''
                ];
            }, $menus);

            sendJsonResponse([
                'success' => true,
                'count'   => count($formattedMenus),
                'menus'   => $formattedMenus
            ]);
            break;

        case 'parents':
            $excludeId = (int)($_GET['exclude_id'] ?? 0);
            $sql = "SELECT menu_id, menu_code, menu_name, menu_type FROM auth.menus WHERE is_active = true";
            $params = [];

            if ($excludeId > 0) {
                $sql .= " AND menu_id != :exclude_id";
                $params[':exclude_id'] = $excludeId;
            }
            $sql .= " ORDER BY display_order ASC, menu_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $parents = $stmt->fetchAll();

            sendJsonResponse([
                'success' => true,
                'parents' => $parents
            ]);
            break;

        case 'get_menu':
            $menuId = (int)($_GET['menu_id'] ?? 0);
            if ($menuId <= 0) {
                throw new Exception("Invalid Menu ID.");
            }

            $stmt = $pdo->prepare("
                SELECT menu_id, parent_menu_id, menu_code, menu_name,
                       menu_type, route, icon, display_order, is_visible,
                       is_active, description
                FROM auth.menus
                WHERE menu_id = :menu_id
                LIMIT 1
            ");
            $stmt->execute([':menu_id' => $menuId]);
            $menuData = $stmt->fetch();

            if (!$menuData) {
                throw new Exception("Menu not found.");
            }

            $menuData['menu_id']        = (int)$menuData['menu_id'];
            $menuData['parent_menu_id'] = $menuData['parent_menu_id'] ? (int)$menuData['parent_menu_id'] : null;
            $menuData['display_order']  = (int)$menuData['display_order'];
            $menuData['is_visible']     = (bool)$menuData['is_visible'];
            $menuData['is_active']      = (bool)$menuData['is_active'];

            sendJsonResponse([
                'success' => true,
                'menu'    => $menuData
            ]);
            break;

        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Invalid request method.");
            }

            $menuCode     = strtoupper(trim($_POST['menu_code'] ?? ''));
            $menuName     = trim($_POST['menu_name'] ?? '');
            $menuType     = strtoupper(trim($_POST['menu_type'] ?? ''));
            $parentMenuId = !empty($_POST['parent_menu_id']) ? (int)$_POST['parent_menu_id'] : null;
            $route        = sanitizeRouteScheme($_POST['route'] ?? '');
            $icon         = trim($_POST['icon'] ?? '');
            $displayOrder = isset($_POST['display_order']) && is_numeric($_POST['display_order']) ? (int)$_POST['display_order'] : 10;
            $isVisible    = isset($_POST['is_visible']) ? (filter_var($_POST['is_visible'], FILTER_VALIDATE_BOOLEAN)) : true;
            $isActive     = isset($_POST['is_active']) ? (filter_var($_POST['is_active'], FILTER_VALIDATE_BOOLEAN)) : true;
            $description  = trim($_POST['description'] ?? '');

            // Input Validations
            if (empty($menuCode)) {
                throw new Exception("Menu code is required.");
            }
            if (empty($menuName)) {
                throw new Exception("Menu name is required.");
            }
            if (!in_array($menuType, ['GROUP', 'PAGE'], true)) {
                throw new Exception("Valid menu type (GROUP or PAGE) is required.");
            }
            if ($menuType === 'PAGE' && empty($route)) {
                throw new Exception("Route is required for PAGE menu type.");
            }

            if ($parentMenuId !== null) {
                $pStmt = $pdo->prepare("SELECT menu_id FROM auth.menus WHERE menu_id = :id LIMIT 1");
                $pStmt->execute([':id' => $parentMenuId]);
                if (!$pStmt->fetch()) {
                    throw new Exception("Selected parent menu does not exist.");
                }
            }

            $chkStmt = $pdo->prepare("SELECT menu_id FROM auth.menus WHERE UPPER(menu_code) = :code LIMIT 1");
            $chkStmt->execute([':code' => $menuCode]);
            if ($chkStmt->fetch()) {
                throw new Exception("A menu with code '{$menuCode}' already exists.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO auth.menus (
                    parent_menu_id, menu_code, menu_name, menu_type,
                    route, icon, display_order, is_visible, is_active, description
                ) VALUES (
                    :parent_menu_id, :menu_code, :menu_name, :menu_type,
                    :route, :icon, :display_order, :is_visible, :is_active, :description
                ) RETURNING menu_id
            ");

            $stmt->execute([
                ':parent_menu_id' => $parentMenuId,
                ':menu_code'      => $menuCode,
                ':menu_name'      => $menuName,
                ':menu_type'      => $menuType,
                ':route'          => $route,
                ':icon'           => $icon,
                ':display_order'  => $displayOrder,
                ':is_visible'     => $isVisible ? 'true' : 'false',
                ':is_active'      => $isActive ? 'true' : 'false',
                ':description'    => $description
            ]);

            $newRow = $stmt->fetch();
            $newId = $newRow ? (int)$newRow['menu_id'] : 0;

            if (isset($_SESSION['auth_cache'])) {
                unset($_SESSION['auth_cache']);
            }

            sendJsonResponse([
                'success' => true,
                'message' => 'Menu created successfully.',
                'menu_id' => $newId
            ]);
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Invalid request method.");
            }

            $menuId       = (int)($_POST['menu_id'] ?? 0);
            $menuCode     = strtoupper(trim($_POST['menu_code'] ?? ''));
            $menuName     = trim($_POST['menu_name'] ?? '');
            $menuType     = strtoupper(trim($_POST['menu_type'] ?? ''));
            $parentMenuId = !empty($_POST['parent_menu_id']) ? (int)$_POST['parent_menu_id'] : null;
            $route        = sanitizeRouteScheme($_POST['route'] ?? '');
            $icon         = trim($_POST['icon'] ?? '');
            $displayOrder = isset($_POST['display_order']) && is_numeric($_POST['display_order']) ? (int)$_POST['display_order'] : 10;
            $isVisible    = isset($_POST['is_visible']) ? (filter_var($_POST['is_visible'], FILTER_VALIDATE_BOOLEAN)) : true;
            $isActive     = isset($_POST['is_active']) ? (filter_var($_POST['is_active'], FILTER_VALIDATE_BOOLEAN)) : true;
            $description  = trim($_POST['description'] ?? '');

            if ($menuId <= 0) {
                throw new Exception("Invalid Menu ID for update.");
            }

            $chkExist = $pdo->prepare("SELECT menu_id FROM auth.menus WHERE menu_id = :id LIMIT 1");
            $chkExist->execute([':id' => $menuId]);
            if (!$chkExist->fetch()) {
                throw new Exception("Target menu record not found.");
            }

            if (empty($menuCode)) {
                throw new Exception("Menu code is required.");
            }
            if (empty($menuName)) {
                throw new Exception("Menu name is required.");
            }
            if (!in_array($menuType, ['GROUP', 'PAGE'], true)) {
                throw new Exception("Valid menu type (GROUP or PAGE) is required.");
            }
            if ($menuType === 'PAGE' && empty($route)) {
                throw new Exception("Route is required for PAGE menu type.");
            }

            $chkDup = $pdo->prepare("SELECT menu_id FROM auth.menus WHERE UPPER(menu_code) = :code AND menu_id != :id LIMIT 1");
            $chkDup->execute([':code' => $menuCode, ':id' => $menuId]);
            if ($chkDup->fetch()) {
                throw new Exception("Another menu with code '{$menuCode}' already exists.");
            }

            if ($parentMenuId === $menuId) {
                throw new Exception("A menu cannot be set as its own parent.");
            }

            if ($parentMenuId !== null && isParentAncestor($pdo, $menuId, $parentMenuId)) {
                throw new Exception("Circular hierarchy detected: Cannot set a child or descendant menu as parent.");
            }

            $stmt = $pdo->prepare("
                UPDATE auth.menus
                SET parent_menu_id = :parent_menu_id,
                    menu_code      = :menu_code,
                    menu_name      = :menu_name,
                    menu_type      = :menu_type,
                    route          = :route,
                    icon           = :icon,
                    display_order  = :display_order,
                    is_visible     = :is_visible,
                    is_active      = :is_active,
                    description    = :description
                WHERE menu_id = :menu_id
            ");

            $stmt->execute([
                ':parent_menu_id' => $parentMenuId,
                ':menu_code'      => $menuCode,
                ':menu_name'      => $menuName,
                ':menu_type'      => $menuType,
                ':route'          => $route,
                ':icon'           => $icon,
                ':display_order'  => $displayOrder,
                ':is_visible'     => $isVisible ? 'true' : 'false',
                ':is_active'      => $isActive ? 'true' : 'false',
                ':description'    => $description,
                ':menu_id'        => $menuId
            ]);

            if (isset($_SESSION['auth_cache'])) {
                unset($_SESSION['auth_cache']);
            }

            sendJsonResponse([
                'success' => true,
                'message' => 'Menu updated successfully.'
            ]);
            break;

        case 'toggle_status':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Invalid request method.");
            }

            $menuId = (int)($_POST['menu_id'] ?? 0);
            if ($menuId <= 0) {
                throw new Exception("Invalid Menu ID.");
            }

            $stmtCurr = $pdo->prepare("SELECT is_active FROM auth.menus WHERE menu_id = :id LIMIT 1");
            $stmtCurr->execute([':id' => $menuId]);
            $currRow = $stmtCurr->fetch();

            if (!$currRow) {
                throw new Exception("Menu record not found.");
            }

            $newStatus = !((bool)$currRow['is_active']);

            $stmtUpd = $pdo->prepare("UPDATE auth.menus SET is_active = :status WHERE menu_id = :id");
            $stmtUpd->execute([
                ':status' => $newStatus ? 'true' : 'false',
                ':id'     => $menuId
            ]);

            if (isset($_SESSION['auth_cache'])) {
                unset($_SESSION['auth_cache']);
            }

            sendJsonResponse([
                'success'   => true,
                'message'   => 'Menu status updated successfully.',
                'is_active' => $newStatus
            ]);
            break;

        default:
            throw new Exception("Unsupported action '{$action}'.");
    }

} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);
}
