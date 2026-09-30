<?php
/**
 * Cost360 - Menu Master API Endpoint
 * Handles CRUD operations, hierarchy validation, and status toggles for menus.
 * Database: Central Neon PostgreSQL (menus base table & auth.menus view).
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
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Security check: Must be authenticated
if (!isLoggedIn()) {
    sendJsonResponse(['success' => false, 'error' => 'Unauthorized session. Please log in.'], 401);
}

// Security check: Authorized access check (Platform Admin, Menu Master access, or active session)
$currentUserId = $_SESSION['user_id'] ?? null;
$hasAccess = (function_exists('isPlatformAdmin') && isPlatformAdmin($currentUserId)) ||
             (function_exists('userHasMenuAccess') && (userHasMenuAccess('MENU_MASTER', $currentUserId) || userHasMenuAccess('ADMIN_MENUS', $currentUserId) || userHasMenuAccess('/admin/menus', $currentUserId) || userHasMenuAccess('/administration/menus', $currentUserId))) ||
             (function_exists('userHasPermission') && userHasPermission('can_view', 'MENU_MASTER', $currentUserId)) ||
             isLoggedIn();

if (!$hasAccess) {
    sendJsonResponse(['success' => false, 'error' => 'Access denied. You do not have permission to manage menus.'], 403);
}

// Helper: Inspect actual columns existing on the menus table to prevent undefined column errors
function getMenusTableColumns(PDO $pdo): array {
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    try {
        $stmt = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'menus' AND table_schema = 'public'
        ");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $cols = ['menu_id', 'parent_menu_id', 'menu_code', 'menu_name', 'route', 'icon', 'display_order', 'menu_type', 'status', 'created_at', 'updated_at'];
    }
    return $cols;
}

// Helper: Synchronize sequence with current max(menu_id) to avoid duplicate key errors
function syncMenusSequence(PDO $pdo): void {
    try {
        $pdo->query("SELECT setval('menus_menu_id_seq', (SELECT COALESCE(MAX(menu_id), 0) FROM menus))");
    } catch (Throwable $e) {
        // Silently continue if sequence name differs or insufficient privileges
    }
}

// Helper: Clear authentication and menu caches stored in session
function clearAuthSessionCache(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['auth_cache']);
        unset($_SESSION['authorized_menus']);
        unset($_SESSION['user_menus']);
    }
}

// Helper: Normalize menu type to DB-allowed CHECK constraint values ('Module', 'Menu', 'Submenu', 'Action')
function normalizeMenuType(string $type): string {
    $clean = strtoupper(trim($type));
    switch ($clean) {
        case 'MODULE':
            return 'Module';
        case 'SUBMENU':
            return 'Submenu';
        case 'ACTION':
            return 'Action';
        case 'PAGE':
        case 'MENU':
        default:
            return 'Menu';
    }
}

// Helper: Normalize status value to DB-allowed CHECK constraint values ('Active', 'Inactive')
function normalizeStatus($val): string {
    if (is_bool($val)) {
        return $val ? 'Active' : 'Inactive';
    }
    $str = strtoupper(trim((string)$val));
    if ($str === 'ACTIVE' || $str === '1' || $str === 'TRUE') {
        return 'Active';
    }
    return 'Inactive';
}

/**
 * Recursive Helper: Check if $menuId is an ancestor of $targetParentId to prevent circular dependency
 */
function isParentAncestor(PDO $pdo, int $menuId, int $targetParentId): bool {
    if ($menuId <= 0 || $targetParentId <= 0) {
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

        $stmt = $pdo->prepare("SELECT parent_menu_id FROM menus WHERE menu_id = :id LIMIT 1");
        $stmt->execute([':id' => $currParentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

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
function sanitizeRouteScheme(?string $route): string {
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

$action = 'list';
$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
} elseif (isset($_GET['action'])) {
    $action = trim($_GET['action']);
}

try {
    $pdo = getDbConnection();
    $existingColumns = getMenusTableColumns($pdo);
    $hasIsVisible = in_array('is_visible', $existingColumns, true);
    $hasDescription = in_array('description', $existingColumns, true);

    switch ($action) {
        /* ========================================================
           1. LIST MENUS (WITH FILTERS & HIERARCHY DETAILS)
           ======================================================== */
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $typeFilter = trim($_GET['menu_type'] ?? 'ALL');
            $statusFilter = trim($_GET['status'] ?? 'ALL');
            $visibilityFilter = trim($_GET['visibility'] ?? 'ALL');
            $parentFilter = trim($_GET['parent_id'] ?? 'ALL');

            $selectVisible = $hasIsVisible ? "m.is_visible," : "";
            $selectDesc    = $hasDescription ? "m.description," : "";

            $query = "
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
                    {$selectVisible}
                    {$selectDesc}
                    p.menu_name AS parent_menu_name,
                    p.menu_code AS parent_menu_code,
                    (SELECT COUNT(*) FROM menus c WHERE c.parent_menu_id = m.menu_id) AS children_count
                FROM menus m
                LEFT JOIN menus p ON m.parent_menu_id = p.menu_id
                WHERE 1=1
            ";

            $params = [];

            if ($search !== '') {
                $query .= " AND (m.menu_code ILIKE :search OR m.menu_name ILIKE :search OR m.route ILIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            if ($typeFilter !== '' && $typeFilter !== 'ALL') {
                $normType = normalizeMenuType($typeFilter);
                if (strtoupper($typeFilter) === 'PAGE') {
                    // Match either Menu, Submenu or Action for PAGE filter
                    $query .= " AND UPPER(m.menu_type) IN ('MENU', 'SUBMENU', 'ACTION', 'PAGE')";
                } else {
                    $query .= " AND UPPER(m.menu_type) = :menu_type";
                    $params[':menu_type'] = strtoupper($normType);
                }
            }

            if ($statusFilter !== '' && $statusFilter !== 'ALL') {
                $normStatus = normalizeStatus($statusFilter);
                $query .= " AND LOWER(m.status) = LOWER(:status)";
                $params[':status'] = $normStatus;
            }

            if ($visibilityFilter !== '' && $visibilityFilter !== 'ALL') {
                $isVis = (strtoupper($visibilityFilter) === 'VISIBLE');
                if ($hasIsVisible) {
                    $query .= " AND m.is_visible = " . ($isVis ? "true" : "false");
                } else {
                    // Graceful fallback: Active menus are Visible, Inactive are Hidden
                    $query .= " AND LOWER(m.status) = " . ($isVis ? "'active'" : "'inactive'");
                }
            }

            if ($parentFilter !== '' && $parentFilter !== 'ALL') {
                if ($parentFilter === 'ROOT') {
                    $query .= " AND m.parent_menu_id IS NULL";
                } elseif (is_numeric($parentFilter)) {
                    $query .= " AND m.parent_menu_id = :parent_id";
                    $params[':parent_id'] = (int)$parentFilter;
                }
            }

            // Hierarchy-aligned ordering: groups parents and children predictably
            $query .= " ORDER BY COALESCE(m.parent_menu_id, m.menu_id) ASC, m.parent_menu_id NULLS FIRST, m.display_order ASC, m.menu_id ASC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedMenus = array_map(function($m) use ($hasIsVisible, $hasDescription) {
                $statusStr = $m['status'] ?? 'Active';
                $isActive  = (strcasecmp($statusStr, 'Active') === 0);

                if ($hasIsVisible && isset($m['is_visible'])) {
                    $isVisible = (bool)$m['is_visible'];
                } else {
                    $isVisible = $isActive;
                }

                $desc = '';
                if ($hasDescription && isset($m['description'])) {
                    $desc = (string)$m['description'];
                }

                return [
                    'menu_id'          => (int)$m['menu_id'],
                    'parent_menu_id'   => $m['parent_menu_id'] !== null ? (int)$m['parent_menu_id'] : null,
                    'parent_menu_name' => $m['parent_menu_name'] ?: 'None (Root Menu)',
                    'parent_menu_code' => $m['parent_menu_code'] ?? null,
                    'menu_code'        => $m['menu_code'],
                    'menu_name'        => $m['menu_name'],
                    'menu_type'        => $m['menu_type'],
                    'route'            => $m['route'] ?? '',
                    'icon'             => $m['icon'] ?? '',
                    'display_order'    => (int)($m['display_order'] ?? 0),
                    'visibility'       => $isVisible ? 'Visible' : 'Hidden',
                    'is_visible'       => $isVisible,
                    'status'           => $statusStr,
                    'is_active'        => $isActive,
                    'description'      => $desc,
                    'children_count'   => (int)($m['children_count'] ?? 0),
                    'has_children'     => ((int)($m['children_count'] ?? 0)) > 0
                ];
            }, $menus);

            // Compute summary KPIs
            $totalCount    = count($formattedMenus);
            $activeCount   = 0;
            $inactiveCount = 0;
            foreach ($formattedMenus as $item) {
                if ($item['is_active']) {
                    $activeCount++;
                } else {
                    $inactiveCount++;
                }
            }

            sendJsonResponse([
                'success' => true,
                'count'   => $totalCount,
                'kpis'    => [
                    'total'    => $totalCount,
                    'active'   => $activeCount,
                    'inactive' => $inactiveCount
                ],
                'menus'   => $formattedMenus
            ]);
            break;

        /* ========================================================
           2. GET PARENT MENU DROPDOWN OPTIONS (PREVENTS CYCLES)
           ======================================================== */
        case 'parents':
            $excludeId = (int)($_GET['exclude_id'] ?? 0);

            // If an exclude_id is provided, recursively exclude it and all of its descendants
            if ($excludeId > 0) {
                $sql = "
                    WITH RECURSIVE descendants AS (
                        SELECT menu_id FROM menus WHERE menu_id = :exclude_id
                        UNION ALL
                        SELECT m.menu_id FROM menus m JOIN descendants d ON m.parent_menu_id = d.menu_id
                    )
                    SELECT menu_id, menu_code, menu_name, menu_type, display_order
                    FROM menus
                    WHERE LOWER(status) = 'active'
                      AND menu_id NOT IN (SELECT menu_id FROM descendants)
                    ORDER BY display_order ASC, menu_name ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':exclude_id' => $excludeId]);
            } else {
                $sql = "
                    SELECT menu_id, menu_code, menu_name, menu_type, display_order
                    FROM menus
                    WHERE LOWER(status) = 'active'
                    ORDER BY display_order ASC, menu_name ASC
                ";
                $stmt = $pdo->query($sql);
            }

            $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJsonResponse([
                'success' => true,
                'parents' => array_map(function($p) {
                    return [
                        'menu_id'       => (int)$p['menu_id'],
                        'menu_code'     => $p['menu_code'],
                        'menu_name'     => $p['menu_name'],
                        'menu_type'     => $p['menu_type'],
                        'display_order' => (int)$p['display_order']
                    ];
                }, $parents)
            ]);
            break;

        /* ========================================================
           3. GET SINGLE MENU DETAILS
           ======================================================== */
        case 'get_menu':
            $menuId = (int)($_GET['menu_id'] ?? 0);
            if ($menuId <= 0) {
                throw new Exception("Invalid Menu ID.");
            }

            $selectVisible = $hasIsVisible ? "m.is_visible," : "";
            $selectDesc    = $hasDescription ? "m.description," : "";

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
                    {$selectVisible}
                    {$selectDesc}
                    p.menu_name AS parent_menu_name
                FROM menus m
                LEFT JOIN menus p ON m.parent_menu_id = p.menu_id
                WHERE m.menu_id = :menu_id
                LIMIT 1
            ");
            $stmt->execute([':menu_id' => $menuId]);
            $menuData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$menuData) {
                throw new Exception("Menu not found.");
            }

            $statusStr = $menuData['status'] ?? 'Active';
            $isActive  = (strcasecmp($statusStr, 'Active') === 0);

            if ($hasIsVisible && isset($menuData['is_visible'])) {
                $isVisible = (bool)$menuData['is_visible'];
            } else {
                $isVisible = $isActive;
            }

            $desc = '';
            if ($hasDescription && isset($menuData['description'])) {
                $desc = (string)$menuData['description'];
            }

            sendJsonResponse([
                'success' => true,
                'menu'    => [
                    'menu_id'          => (int)$menuData['menu_id'],
                    'parent_menu_id'   => $menuData['parent_menu_id'] !== null ? (int)$menuData['parent_menu_id'] : null,
                    'parent_menu_name' => $menuData['parent_menu_name'] ?: 'None (Root Menu)',
                    'menu_code'        => $menuData['menu_code'],
                    'menu_name'        => $menuData['menu_name'],
                    'menu_type'        => $menuData['menu_type'],
                    'route'            => $menuData['route'] ?? '',
                    'icon'             => $menuData['icon'] ?? '',
                    'display_order'    => (int)($menuData['display_order'] ?? 0),
                    'visibility'       => $isVisible ? 'Visible' : 'Hidden',
                    'is_visible'       => $isVisible,
                    'status'           => $statusStr,
                    'is_active'        => $isActive,
                    'description'      => $desc
                ]
            ]);
            break;

        /* ========================================================
           4. CREATE MENU
           ======================================================== */
        case 'create':
            if ($reqMethod !== 'POST') {
                throw new Exception("Invalid request method. POST required.");
            }

            $menuCode     = strtoupper(trim($_POST['menu_code'] ?? ''));
            $menuName     = trim($_POST['menu_name'] ?? '');
            $rawMenuType  = trim($_POST['menu_type'] ?? 'Menu');
            $menuType     = normalizeMenuType($rawMenuType);
            $parentMenuId = (!empty($_POST['parent_menu_id']) && is_numeric($_POST['parent_menu_id'])) ? (int)$_POST['parent_menu_id'] : null;
            $route        = sanitizeRouteScheme($_POST['route'] ?? '');
            $icon         = trim($_POST['icon'] ?? '');
            $displayOrder = (isset($_POST['display_order']) && is_numeric($_POST['display_order'])) ? (int)$_POST['display_order'] : 10;
            
            // Status resolution (supports 'status' or 'is_active')
            $statusInput = $_POST['status'] ?? ($_POST['is_active'] ?? 'Active');
            $status = normalizeStatus($statusInput);

            // Visibility resolution
            $visibilityInput = $_POST['visibility'] ?? ($_POST['is_visible'] ?? 'true');
            $isVisible = filter_var($visibilityInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isVisible === null) {
                $isVisible = (strcasecmp((string)$visibilityInput, 'visible') === 0);
            }

            $description = trim($_POST['description'] ?? '');

            // Field validations
            if (empty($menuCode)) {
                throw new Exception("Menu Code is required.");
            }
            if (empty($menuName)) {
                throw new Exception("Menu Name is required.");
            }

            // Route check: Non-module menus should have a route
            if ($menuType !== 'Module' && empty($route)) {
                $route = strtolower(str_replace('_', '-', $menuCode));
            }

            // Parent validity check
            if ($parentMenuId !== null) {
                $pStmt = $pdo->prepare("SELECT menu_id FROM menus WHERE menu_id = :id LIMIT 1");
                $pStmt->execute([':id' => $parentMenuId]);
                if (!$pStmt->fetch()) {
                    throw new Exception("Selected parent menu does not exist.");
                }
            }

            // Duplicate code check
            $chkStmt = $pdo->prepare("SELECT menu_id FROM menus WHERE UPPER(menu_code) = :code LIMIT 1");
            $chkStmt->execute([':code' => $menuCode]);
            if ($chkStmt->fetch()) {
                throw new Exception("A menu with code '{$menuCode}' already exists.");
            }

            // Ensure sequence is aligned to avoid duplicate key violations
            syncMenusSequence($pdo);

            // Build dynamic insert based on existing table columns
            $insertFields = ['parent_menu_id', 'menu_code', 'menu_name', 'menu_type', 'route', 'icon', 'display_order', 'status'];
            $insertValues = [':parent_menu_id', ':menu_code', ':menu_name', ':menu_type', ':route', ':icon', ':display_order', ':status'];
            $execParams = [
                ':parent_menu_id' => $parentMenuId,
                ':menu_code'      => $menuCode,
                ':menu_name'      => $menuName,
                ':menu_type'      => $menuType,
                ':route'          => $route,
                ':icon'           => $icon,
                ':display_order'  => $displayOrder,
                ':status'         => $status
            ];

            if ($hasIsVisible) {
                $insertFields[] = 'is_visible';
                $insertValues[] = ':is_visible';
                $execParams[':is_visible'] = $isVisible ? 'true' : 'false';
            }

            if ($hasDescription) {
                $insertFields[] = 'description';
                $insertValues[] = ':description';
                $execParams[':description'] = $description;
            }

            $fieldsStr = implode(', ', $insertFields);
            $valuesStr = implode(', ', $insertValues);

            $stmt = $pdo->prepare("
                INSERT INTO menus ({$fieldsStr})
                VALUES ({$valuesStr})
                RETURNING menu_id
            ");
            $stmt->execute($execParams);
            $newRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $newId = $newRow ? (int)$newRow['menu_id'] : 0;

            // Auto-grant access in role_menu for Platform Admin role (role_id 1 or PLATFORM_ADMIN)
            if ($newId > 0) {
                try {
                    $grantStmt = $pdo->prepare("
                        INSERT INTO role_menu (
                            role_id, menu_id, can_view, can_create, can_edit, can_delete,
                            can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active
                        )
                        SELECT role_id, :menu_id, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE
                        FROM roles
                        WHERE UPPER(role_code) = 'PLATFORM_ADMIN' OR category_id = 1
                        ON CONFLICT (role_id, menu_id) DO NOTHING
                    ");
                    $grantStmt->execute([':menu_id' => $newId]);
                } catch (Throwable $e) {
                    // Non-fatal if role_menu table differs
                }
            }

            clearAuthSessionCache();

            sendJsonResponse([
                'success' => true,
                'message' => 'Menu created successfully.',
                'menu_id' => $newId
            ]);
            break;

        /* ========================================================
           5. UPDATE MENU
           ======================================================== */
        case 'update':
            if ($reqMethod !== 'POST') {
                throw new Exception("Invalid request method. POST required.");
            }

            $menuId       = (int)($_POST['menu_id'] ?? 0);
            $menuCode     = strtoupper(trim($_POST['menu_code'] ?? ''));
            $menuName     = trim($_POST['menu_name'] ?? '');
            $rawMenuType  = trim($_POST['menu_type'] ?? 'Menu');
            $menuType     = normalizeMenuType($rawMenuType);
            $parentMenuId = (!empty($_POST['parent_menu_id']) && is_numeric($_POST['parent_menu_id'])) ? (int)$_POST['parent_menu_id'] : null;
            $route        = sanitizeRouteScheme($_POST['route'] ?? '');
            $icon         = trim($_POST['icon'] ?? '');
            $displayOrder = (isset($_POST['display_order']) && is_numeric($_POST['display_order'])) ? (int)$_POST['display_order'] : 10;
            
            $statusInput = $_POST['status'] ?? ($_POST['is_active'] ?? 'Active');
            $status = normalizeStatus($statusInput);

            $visibilityInput = $_POST['visibility'] ?? ($_POST['is_visible'] ?? 'true');
            $isVisible = filter_var($visibilityInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isVisible === null) {
                $isVisible = (strcasecmp((string)$visibilityInput, 'visible') === 0);
            }

            $description = trim($_POST['description'] ?? '');

            if ($menuId <= 0) {
                throw new Exception("Invalid Menu ID provided for update.");
            }

            $chkExist = $pdo->prepare("SELECT menu_id FROM menus WHERE menu_id = :id LIMIT 1");
            $chkExist->execute([':id' => $menuId]);
            if (!$chkExist->fetch()) {
                throw new Exception("Menu record with ID #{$menuId} does not exist.");
            }

            if (empty($menuCode)) {
                throw new Exception("Menu Code is required.");
            }
            if (empty($menuName)) {
                throw new Exception("Menu Name is required.");
            }

            if ($menuType !== 'Module' && empty($route)) {
                $route = strtolower(str_replace('_', '-', $menuCode));
            }

            // Duplicate code check (excluding current menu)
            $chkDup = $pdo->prepare("SELECT menu_id FROM menus WHERE UPPER(menu_code) = :code AND menu_id != :id LIMIT 1");
            $chkDup->execute([':code' => $menuCode, ':id' => $menuId]);
            if ($chkDup->fetch()) {
                throw new Exception("Another menu with code '{$menuCode}' already exists.");
            }

            // Hierarchy circular dependency prevention
            if ($parentMenuId === $menuId) {
                throw new Exception("A menu cannot be assigned as its own parent.");
            }

            if ($parentMenuId !== null && isParentAncestor($pdo, $menuId, $parentMenuId)) {
                throw new Exception("Circular hierarchy detected: You cannot select a child or descendant menu as parent.");
            }

            $setClauses = [
                "parent_menu_id = :parent_menu_id",
                "menu_code = :menu_code",
                "menu_name = :menu_name",
                "menu_type = :menu_type",
                "route = :route",
                "icon = :icon",
                "display_order = :display_order",
                "status = :status",
                "updated_at = CURRENT_TIMESTAMP"
            ];

            $updateParams = [
                ':parent_menu_id' => $parentMenuId,
                ':menu_code'      => $menuCode,
                ':menu_name'      => $menuName,
                ':menu_type'      => $menuType,
                ':route'          => $route,
                ':icon'           => $icon,
                ':display_order'  => $displayOrder,
                ':status'         => $status,
                ':menu_id'        => $menuId
            ];

            if ($hasIsVisible) {
                $setClauses[] = "is_visible = :is_visible";
                $updateParams[':is_visible'] = $isVisible ? 'true' : 'false';
            }

            if ($hasDescription) {
                $setClauses[] = "description = :description";
                $updateParams[':description'] = $description;
            }

            $setSql = implode(', ', $setClauses);

            $stmt = $pdo->prepare("
                UPDATE menus
                SET {$setSql}
                WHERE menu_id = :menu_id
            ");
            $stmt->execute($updateParams);

            clearAuthSessionCache();

            sendJsonResponse([
                'success' => true,
                'message' => 'Menu updated successfully.'
            ]);
            break;

        /* ========================================================
           6. TOGGLE STATUS (ACTIVE / INACTIVE)
           ======================================================== */
        case 'toggle_status':
            if ($reqMethod !== 'POST') {
                throw new Exception("Invalid request method. POST required.");
            }

            $menuId = (int)($_POST['menu_id'] ?? 0);
            if ($menuId <= 0) {
                throw new Exception("Invalid Menu ID.");
            }

            $stmtCurr = $pdo->prepare("SELECT menu_id, menu_name, status FROM menus WHERE menu_id = :id LIMIT 1");
            $stmtCurr->execute([':id' => $menuId]);
            $currRow = $stmtCurr->fetch(PDO::FETCH_ASSOC);

            if (!$currRow) {
                throw new Exception("Menu record not found.");
            }

            $currStatus = $currRow['status'] ?? 'Active';
            $newStatus = (strcasecmp($currStatus, 'Active') === 0) ? 'Inactive' : 'Active';
            $isActive = ($newStatus === 'Active');

            $stmtUpd = $pdo->prepare("
                UPDATE menus 
                SET status = :status, updated_at = CURRENT_TIMESTAMP 
                WHERE menu_id = :id
            ");
            $stmtUpd->execute([
                ':status' => $newStatus,
                ':id'     => $menuId
            ]);

            clearAuthSessionCache();

            sendJsonResponse([
                'success'   => true,
                'message'   => "Menu '{$currRow['menu_name']}' is now {$newStatus}.",
                'status'    => $newStatus,
                'is_active' => $isActive
            ]);
            break;

        /* ========================================================
           7. DELETE MENU
           ======================================================== */
        case 'delete':
            if ($reqMethod !== 'POST') {
                throw new Exception("Invalid request method. POST required.");
            }

            $menuId = (int)($_POST['menu_id'] ?? 0);
            if ($menuId <= 0) {
                throw new Exception("Invalid Menu ID provided for deletion.");
            }

            // Verify existence
            $checkStmt = $pdo->prepare("SELECT menu_id, menu_name FROM menus WHERE menu_id = :id LIMIT 1");
            $checkStmt->execute([':id' => $menuId]);
            $menu = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$menu) {
                throw new Exception("Menu record not found or already deleted.");
            }

            // Check if menu has active children
            $childStmt = $pdo->prepare("SELECT COUNT(*) FROM menus WHERE parent_menu_id = :id");
            $childStmt->execute([':id' => $menuId]);
            $childCount = (int)$childStmt->fetchColumn();

            if ($childCount > 0) {
                throw new Exception("Cannot delete '{$menu['menu_name']}' because it has {$childCount} sub-menu(s). Please reassign or delete child menus first.");
            }

            // Execute delete in a transaction (cascade role_menu entries safely)
            $pdo->beginTransaction();
            try {
                // Delete any linked role permissions first if not cascaded
                $delRoleMenu = $pdo->prepare("DELETE FROM role_menu WHERE menu_id = :id");
                $delRoleMenu->execute([':id' => $menuId]);

                $delStmt = $pdo->prepare("DELETE FROM menus WHERE menu_id = :id");
                $delStmt->execute([':id' => $menuId]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw new Exception("Database error while deleting menu: " . $e->getMessage());
            }

            clearAuthSessionCache();

            sendJsonResponse([
                'success' => true,
                'message' => "Menu '{$menu['menu_name']}' deleted successfully."
            ]);
            break;

        default:
            throw new Exception("Unsupported action '{$action}'.");
    }

} catch (PDOException $e) {
    // Graceful PostgreSQL error handling without leaking credentials or database hosts
    $msg = $e->getMessage();
    if (strpos($msg, 'violates unique constraint') !== false) {
        $friendlyError = "A menu with that code already exists. Please use a unique Menu Code.";
    } elseif (strpos($msg, 'violates check constraint') !== false) {
        $friendlyError = "Invalid menu field value provided. Please review Menu Type or Status.";
    } elseif (strpos($msg, 'foreign key constraint') !== false) {
        $friendlyError = "The referenced parent menu does not exist or has dependent records.";
    } else {
        $friendlyError = "Database error: " . $msg;
    }

    sendJsonResponse([
        'success' => false,
        'error'   => $friendlyError
    ], 400);

} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);
}
