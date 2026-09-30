<?php
/**
 * Cost360 - Role Master API Endpoint
 * Handles Role CRUD operations, status toggle, and role-menu permission matrix.
 * Database: Neon PostgreSQL (auth/public schemas).
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

/* ============================================================
   RESPONSE HELPER
   ============================================================ */
function jsonResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $httpCode = 200
): void {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($httpCode);

    $response = [
        'success' => $success
    ];

    if ($success) {
        if ($message !== '') {
            $response['message'] = $message;
        }
    } else {
        $response['error'] = $message;
    }

    $response = array_merge($response, $data);

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/* ============================================================
   AUTHENTICATION & AUTHORIZATION
   ============================================================ */
if (!isLoggedIn()) {
    jsonResponse(
        false,
        'Your session has expired. Please log in again.',
        [],
        401
    );
}

// Security check: Must have Role Master / Admin Roles access or be Platform Admin
$hasAccess = isPlatformAdmin() || userHasMenuAccess('ROLE_MASTER') || userHasMenuAccess('ADMIN_ROLES') || userHasPermission('can_view', 'ROLE_MASTER');
if (!$hasAccess) {
    jsonResponse(
        false,
        'Access denied. You do not have permission to access Role Master.',
        [],
        403
    );
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

/* ============================================================
   DATABASE CONNECTION
   ============================================================ */
try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    error_log('Role Master DB connection error: ' . $e->getMessage());
    jsonResponse(
        false,
        'Unable to connect to the database.',
        [],
        500
    );
}

/* ============================================================
   AUDIT LOG HELPER
   ============================================================ */
function writeAuditLog(
    PDO $pdo,
    int $currentUserId,
    int $entityId,
    string $action,
    ?array $oldValue,
    ?array $newValue
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log
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
                :entity_name,
                :entity_id,
                :action,
                :old_value,
                :new_value,
                CURRENT_TIMESTAMP,
                :ip_address
            )
        ");

        $stmt->execute([
            ':user_id' => $currentUserId > 0 ? $currentUserId : null,
            ':entity_name' => 'roles',
            ':entity_id' => $entityId,
            ':action' => $action,
            ':old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            ':new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        error_log('Audit log failure in Role Master: ' . $e->getMessage());
    }
}

/* ============================================================
   REQUEST METHOD & ACTION ROUTING
   ============================================================ */
$action = 'list';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST') {
    $action = trim($_POST['action'] ?? 'list');
} else {
    $action = trim($_GET['action'] ?? 'list');
}

try {
    switch ($action) {

        /* ========================================================
           1. LIST ROLES (WITH FILTERS & KPIS)
           ======================================================== */
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $categoryFilter = trim($_GET['category_id'] ?? 'ALL');
            $statusFilter = trim($_GET['status'] ?? 'ALL');

            $sql = "
                SELECT 
                    r.role_id,
                    r.category_id,
                    r.role_code,
                    r.role_name,
                    r.description,
                    r.status,
                    TO_CHAR(r.created_at, 'YYYY-MM-DD HH24:MI') AS created_at_formatted,
                    TO_CHAR(r.updated_at, 'YYYY-MM-DD HH24:MI') AS updated_at_formatted,
                    r.created_at,
                    r.updated_at,
                    c.category_name,
                    c.category_code,
                    (
                        SELECT COUNT(*)
                        FROM user_roles ur
                        WHERE ur.role_id = r.role_id AND LOWER(ur.status) = 'active'
                    ) AS assigned_users_count
                FROM roles r
                LEFT JOIN categories c ON r.category_id = c.category_id
                WHERE 1=1
            ";

            $params = [];

            if ($search !== '') {
                $sql .= " AND (r.role_code ILIKE :search OR r.role_name ILIKE :search OR r.description ILIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            if ($categoryFilter !== '' && $categoryFilter !== 'ALL' && is_numeric($categoryFilter)) {
                $sql .= " AND r.category_id = :category_id";
                $params[':category_id'] = (int)$categoryFilter;
            }

            if ($statusFilter !== '' && $statusFilter !== 'ALL') {
                $sql .= " AND LOWER(r.status) = LOWER(:status)";
                $params[':status'] = $statusFilter;
            }

            $sql .= " ORDER BY r.role_id ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $roles = $stmt->fetchAll();

            // Overall system role KPIs from DB
            $kpiStmt = $pdo->query("
                SELECT 
                    COUNT(*) AS total_roles,
                    COUNT(*) FILTER (WHERE LOWER(status) = 'active') AS active_roles,
                    COUNT(*) FILTER (WHERE LOWER(status) = 'inactive') AS inactive_roles
                FROM roles
            ");
            $kpis = $kpiStmt->fetch() ?: [
                'total_roles' => 0,
                'active_roles' => 0,
                'inactive_roles' => 0
            ];

            jsonResponse(true, '', [
                'roles' => $roles,
                'kpis' => [
                    'total' => (int)($kpis['total_roles'] ?? 0),
                    'active' => (int)($kpis['active_roles'] ?? 0),
                    'inactive' => (int)($kpis['inactive_roles'] ?? 0)
                ]
            ]);
            break;

        /* ========================================================
           2. GET ACTIVE CATEGORIES
           ======================================================== */
        case 'categories':
            $stmt = $pdo->query("
                SELECT category_id, category_code, category_name, description
                FROM categories
                WHERE LOWER(status) = 'active'
                ORDER BY category_id ASC
            ");
            $categories = $stmt->fetchAll();

            jsonResponse(true, '', ['categories' => $categories]);
            break;

        /* ========================================================
           3. GET SINGLE ROLE DETAILS
           ======================================================== */
        case 'get':
            $roleId = (int)($_GET['role_id'] ?? 0);
            if ($roleId <= 0) {
                jsonResponse(false, 'Invalid Role ID provided.', [], 400);
            }

            $stmt = $pdo->prepare("
                SELECT 
                    r.role_id,
                    r.category_id,
                    r.role_code,
                    r.role_name,
                    r.description,
                    r.status,
                    TO_CHAR(r.created_at, 'YYYY-MM-DD HH24:MI:SS') AS created_at_formatted,
                    TO_CHAR(r.updated_at, 'YYYY-MM-DD HH24:MI:SS') AS updated_at_formatted,
                    r.created_at,
                    r.updated_at,
                    c.category_name,
                    c.category_code,
                    (
                        SELECT COUNT(*)
                        FROM user_roles ur
                        WHERE ur.role_id = r.role_id AND LOWER(ur.status) = 'active'
                    ) AS assigned_users_count
                FROM roles r
                LEFT JOIN categories c ON r.category_id = c.category_id
                WHERE r.role_id = :role_id
                LIMIT 1
            ");
            $stmt->execute([':role_id' => $roleId]);
            $role = $stmt->fetch();

            if (!$role) {
                jsonResponse(false, 'Role not found.', [], 404);
            }

            jsonResponse(true, '', ['role' => $role]);
            break;

        /* ========================================================
           4. CREATE NEW ROLE
           ======================================================== */
        case 'create':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $rawRoleCode = trim($_POST['role_code'] ?? '');
            $roleName = trim($_POST['role_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = trim($_POST['status'] ?? 'Active');

            if ($categoryId <= 0) {
                jsonResponse(false, 'Please select a valid Role Category.', [], 422);
            }

            // Verify category exists
            $catStmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_id = :cat_id LIMIT 1");
            $catStmt->execute([':cat_id' => $categoryId]);
            if (!$catStmt->fetch()) {
                jsonResponse(false, 'Selected category does not exist.', [], 422);
            }

            if ($rawRoleCode === '') {
                jsonResponse(false, 'Role Code is required.', [], 422);
            }

            // Normalize role code: uppercase, replace spaces/hyphens with underscore, strip disallowed chars
            $normalizedRoleCode = strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '_', $rawRoleCode));
            $normalizedRoleCode = preg_replace('/_+/', '_', $normalizedRoleCode);
            $normalizedRoleCode = trim($normalizedRoleCode, '_');

            if (strlen($normalizedRoleCode) < 3 || strlen($normalizedRoleCode) > 30) {
                jsonResponse(false, 'Role Code must be between 3 and 30 alphanumeric characters.', [], 422);
            }

            // Check uniqueness
            $chkStmt = $pdo->prepare("SELECT role_id FROM roles WHERE UPPER(role_code) = UPPER(:code) LIMIT 1");
            $chkStmt->execute([':code' => $normalizedRoleCode]);
            if ($chkStmt->fetch()) {
                jsonResponse(false, "A role with code '{$normalizedRoleCode}' already exists. Please choose a different code.", [], 409);
            }

            if ($roleName === '' || strlen($roleName) < 2 || strlen($roleName) > 100) {
                jsonResponse(false, 'Role Name must be between 2 and 100 characters.', [], 422);
            }

            $validStatus = (strcasecmp($status, 'Inactive') === 0) ? 'Inactive' : 'Active';

            $insertStmt = $pdo->prepare("
                INSERT INTO roles
                (
                    category_id,
                    role_code,
                    role_name,
                    description,
                    status,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :category_id,
                    :role_code,
                    :role_name,
                    :description,
                    :status,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                RETURNING role_id
            ");

            $insertStmt->execute([
                ':category_id' => $categoryId,
                ':role_code' => $normalizedRoleCode,
                ':role_name' => $roleName,
                ':description' => $description !== '' ? $description : null,
                ':status' => $validStatus
            ]);

            $newRoleId = (int)$insertStmt->fetchColumn();

            writeAuditLog(
                $pdo,
                $currentUserId,
                $newRoleId,
                'INSERT',
                null,
                [
                    'category_id' => $categoryId,
                    'role_code' => $normalizedRoleCode,
                    'role_name' => $roleName,
                    'description' => $description,
                    'status' => $validStatus
                ]
            );

            jsonResponse(true, "Role '{$normalizedRoleCode}' created successfully.", [
                'role_id' => $newRoleId
            ], 201);
            break;

        /* ========================================================
           5. EDIT ROLE DETAILS
           ======================================================== */
        case 'update':
            $roleId = (int)($_POST['role_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $roleName = trim($_POST['role_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($roleId <= 0) {
                jsonResponse(false, 'Invalid Role ID.', [], 400);
            }

            $curStmt = $pdo->prepare("SELECT * FROM roles WHERE role_id = :role_id LIMIT 1");
            $curStmt->execute([':role_id' => $roleId]);
            $oldRole = $curStmt->fetch();

            if (!$oldRole) {
                jsonResponse(false, 'Role not found.', [], 404);
            }

            if ($categoryId <= 0) {
                jsonResponse(false, 'Please select a valid Role Category.', [], 422);
            }

            $catStmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_id = :cat_id LIMIT 1");
            $catStmt->execute([':cat_id' => $categoryId]);
            if (!$catStmt->fetch()) {
                jsonResponse(false, 'Selected category does not exist.', [], 422);
            }

            if ($roleName === '' || strlen($roleName) < 2 || strlen($roleName) > 100) {
                jsonResponse(false, 'Role Name must be between 2 and 100 characters.', [], 422);
            }

            // Note: role_code remains read-only as mandated
            $updateStmt = $pdo->prepare("
                UPDATE roles
                SET 
                    category_id = :category_id,
                    role_name = :role_name,
                    description = :description,
                    updated_at = CURRENT_TIMESTAMP
                WHERE role_id = :role_id
            ");

            $updateStmt->execute([
                ':category_id' => $categoryId,
                ':role_name' => $roleName,
                ':description' => $description !== '' ? $description : null,
                ':role_id' => $roleId
            ]);

            writeAuditLog(
                $pdo,
                $currentUserId,
                $roleId,
                'UPDATE',
                [
                    'category_id' => $oldRole['category_id'],
                    'role_name' => $oldRole['role_name'],
                    'description' => $oldRole['description']
                ],
                [
                    'category_id' => $categoryId,
                    'role_name' => $roleName,
                    'description' => $description
                ]
            );

            jsonResponse(true, "Role '{$oldRole['role_code']}' updated successfully.");
            break;

        /* ========================================================
           6. TOGGLE STATUS (ACTIVE / INACTIVE)
           ======================================================== */
        case 'status':
            $roleId = (int)($_POST['role_id'] ?? 0);
            $targetStatus = trim($_POST['status'] ?? '');

            if ($roleId <= 0) {
                jsonResponse(false, 'Invalid Role ID provided.', [], 400);
            }

            $roleStmt = $pdo->prepare("SELECT * FROM roles WHERE role_id = :role_id LIMIT 1");
            $roleStmt->execute([':role_id' => $roleId]);
            $role = $roleStmt->fetch();

            if (!$role) {
                jsonResponse(false, 'Role not found.', [], 404);
            }

            // Normalize target status
            $newStatus = (strcasecmp($targetStatus, 'Active') === 0 || $targetStatus === '1' || strtolower($targetStatus) === 'true')
                ? 'Active'
                : 'Inactive';

            // Protect PLATFORM_ADMIN from being deactivated
            if (strtoupper($role['role_code']) === 'PLATFORM_ADMIN' && $newStatus === 'Inactive') {
                jsonResponse(false, 'The Platform Administrator role cannot be deactivated.', [], 403);
            }

            $updStmt = $pdo->prepare("
                UPDATE roles
                SET 
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP
                WHERE role_id = :role_id
            ");
            $updStmt->execute([
                ':status' => $newStatus,
                ':role_id' => $roleId
            ]);

            writeAuditLog(
                $pdo,
                $currentUserId,
                $roleId,
                'UPDATE',
                ['status' => $role['status']],
                ['status' => $newStatus]
            );

            $actionText = ($newStatus === 'Active') ? 'activated' : 'deactivated';

            jsonResponse(true, "Role '{$role['role_code']}' has been {$actionText}.", [
                'role_id' => $roleId,
                'new_status' => $newStatus
            ]);
            break;

        /* ========================================================
           7. GET ROLE PERMISSIONS MATRIX
           ======================================================== */
        case 'permissions':
            $roleId = (int)($_GET['role_id'] ?? 0);
            if ($roleId <= 0) {
                jsonResponse(false, 'Invalid Role ID provided.', [], 400);
            }

            // Verify role exists
            $rStmt = $pdo->prepare("SELECT role_id, role_code, role_name, description, status FROM roles WHERE role_id = :role_id LIMIT 1");
            $rStmt->execute([':role_id' => $roleId]);
            $role = $rStmt->fetch();

            if (!$role) {
                jsonResponse(false, 'Role not found.', [], 404);
            }

            // Fetch all active menus dynamically ordered hierarchically
            $menuStmt = $pdo->query("
                SELECT 
                    m.menu_id,
                    m.parent_menu_id,
                    m.menu_code,
                    m.menu_name,
                    m.menu_type,
                    m.route,
                    m.display_order,
                    p.menu_name AS parent_menu_name,
                    p.menu_code AS parent_menu_code
                FROM menus m
                LEFT JOIN menus p ON m.parent_menu_id = p.menu_id
                WHERE LOWER(m.status) = 'active'
                ORDER BY 
                    COALESCE(p.display_order, m.display_order) ASC,
                    m.parent_menu_id NULLS FIRST,
                    m.display_order ASC,
                    m.menu_id ASC
            ");
            $allMenus = $menuStmt->fetchAll();

            // Fetch existing role_menu permissions for this role
            $permStmt = $pdo->prepare("
                SELECT 
                    role_menu_id,
                    role_id,
                    menu_id,
                    can_view,
                    can_create,
                    can_edit,
                    can_delete,
                    can_upload,
                    can_download,
                    can_approve,
                    can_export,
                    can_print,
                    can_configure,
                    is_active
                FROM role_menu
                WHERE role_id = :role_id
            ");
            $permStmt->execute([':role_id' => $roleId]);
            $roleMenus = $permStmt->fetchAll();

            // Key existing permissions by menu_id
            $permMap = [];
            foreach ($roleMenus as $rm) {
                $permMap[(int)$rm['menu_id']] = $rm;
            }

            // Merge into unified list
            $matrix = [];
            foreach ($allMenus as $m) {
                $mId = (int)$m['menu_id'];
                $existing = $permMap[$mId] ?? null;

                $matrix[] = [
                    'menu_id' => $mId,
                    'parent_menu_id' => $m['parent_menu_id'] ? (int)$m['parent_menu_id'] : null,
                    'parent_menu_name' => $m['parent_menu_name'] ?: 'None',
                    'menu_code' => $m['menu_code'],
                    'menu_name' => $m['menu_name'],
                    'menu_type' => $m['menu_type'],
                    'route' => $m['route'],
                    'display_order' => (int)$m['display_order'],
                    'has_assignment' => ($existing !== null && !empty($existing['is_active'])),
                    'can_view' => !empty($existing['can_view']),
                    'can_create' => !empty($existing['can_create']),
                    'can_edit' => !empty($existing['can_edit']),
                    'can_delete' => !empty($existing['can_delete']),
                    'can_upload' => !empty($existing['can_upload']),
                    'can_download' => !empty($existing['can_download']),
                    'can_approve' => !empty($existing['can_approve']),
                    'can_export' => !empty($existing['can_export']),
                    'can_print' => !empty($existing['can_print']),
                    'can_configure' => !empty($existing['can_configure']),
                    'is_active' => ($existing !== null) ? !empty($existing['is_active']) : true
                ];
            }

            jsonResponse(true, '', [
                'role' => $role,
                'menus' => $matrix
            ]);
            break;

        /* ========================================================
           8. SAVE ROLE PERMISSIONS MATRIX
           ======================================================== */
        case 'save_permissions':
            $roleId = (int)($_POST['role_id'] ?? 0);
            if ($roleId <= 0) {
                jsonResponse(false, 'Invalid Role ID provided.', [], 400);
            }

            // Verify role exists
            $rStmt = $pdo->prepare("SELECT role_id, role_code, role_name FROM roles WHERE role_id = :role_id LIMIT 1");
            $rStmt->execute([':role_id' => $roleId]);
            $role = $rStmt->fetch();

            if (!$role) {
                jsonResponse(false, 'Role not found.', [], 404);
            }

            // Permissions can be sent as raw JSON or as POST array
            $permissionsData = $_POST['permissions'] ?? null;
            if (is_string($permissionsData)) {
                $decoded = json_decode($permissionsData, true);
                if (is_array($decoded)) {
                    $permissionsData = $decoded;
                }
            }

            if (!is_array($permissionsData)) {
                jsonResponse(false, 'Invalid permissions payload format.', [], 422);
            }

            // Execute in an isolated database transaction
            $pdo->beginTransaction();

            try {
                // Upsert statement for PostgreSQL using UNIQUE(role_id, menu_id)
                $upsertStmt = $pdo->prepare("
                    INSERT INTO role_menu
                    (
                        role_id,
                        menu_id,
                        can_view,
                        can_create,
                        can_edit,
                        can_delete,
                        can_upload,
                        can_download,
                        can_approve,
                        can_export,
                        can_print,
                        can_configure,
                        is_active
                    )
                    VALUES
                    (
                        :role_id,
                        :menu_id,
                        :can_view,
                        :can_create,
                        :can_edit,
                        :can_delete,
                        :can_upload,
                        :can_download,
                        :can_approve,
                        :can_export,
                        :can_print,
                        :can_configure,
                        :is_active
                    )
                    ON CONFLICT (role_id, menu_id) DO UPDATE SET
                        can_view = EXCLUDED.can_view,
                        can_create = EXCLUDED.can_create,
                        can_edit = EXCLUDED.can_edit,
                        can_delete = EXCLUDED.can_delete,
                        can_upload = EXCLUDED.can_upload,
                        can_download = EXCLUDED.can_download,
                        can_approve = EXCLUDED.can_approve,
                        can_export = EXCLUDED.can_export,
                        can_print = EXCLUDED.can_print,
                        can_configure = EXCLUDED.can_configure,
                        is_active = EXCLUDED.is_active
                ");

                $processedMenuIds = [];

                foreach ($permissionsData as $item) {
                    $menuId = (int)($item['menu_id'] ?? 0);
                    if ($menuId <= 0) {
                        continue;
                    }

                    $canView = !empty($item['can_view']);
                    $canCreate = !empty($item['can_create']);
                    $canEdit = !empty($item['can_edit']);
                    $canDelete = !empty($item['can_delete']);
                    $canUpload = !empty($item['can_upload']);
                    $canDownload = !empty($item['can_download']);
                    $canApprove = !empty($item['can_approve']);
                    $canExport = !empty($item['can_export']);
                    $canPrint = !empty($item['can_print']);
                    $canConfigure = !empty($item['can_configure']);

                    $hasAnyAction = ($canView || $canCreate || $canEdit || $canDelete ||
                                     $canUpload || $canDownload || $canApprove ||
                                     $canExport || $canPrint || $canConfigure);

                    // If any permission is selected, row is active
                    $isActive = $hasAnyAction && (isset($item['is_active']) ? !empty($item['is_active']) : true);

                    $upsertStmt->execute([
                        ':role_id' => $roleId,
                        ':menu_id' => $menuId,
                        ':can_view' => $canView ? 1 : 0,
                        ':can_create' => $canCreate ? 1 : 0,
                        ':can_edit' => $canEdit ? 1 : 0,
                        ':can_delete' => $canDelete ? 1 : 0,
                        ':can_upload' => $canUpload ? 1 : 0,
                        ':can_download' => $canDownload ? 1 : 0,
                        ':can_approve' => $canApprove ? 1 : 0,
                        ':can_export' => $canExport ? 1 : 0,
                        ':can_print' => $canPrint ? 1 : 0,
                        ':can_configure' => $canConfigure ? 1 : 0,
                        ':is_active' => $isActive ? 1 : 0
                    ]);

                    $processedMenuIds[] = $menuId;
                }

                // Update role's updated_at timestamp
                $touchStmt = $pdo->prepare("UPDATE roles SET updated_at = CURRENT_TIMESTAMP WHERE role_id = :role_id");
                $touchStmt->execute([':role_id' => $roleId]);

                $pdo->commit();

                writeAuditLog(
                    $pdo,
                    $currentUserId,
                    $roleId,
                    'UPDATE',
                    null,
                    [
                        'action' => 'save_permissions',
                        'role_code' => $role['role_code'],
                        'configured_menus_count' => count($processedMenuIds)
                    ]
                );

                jsonResponse(true, "Permissions for '{$role['role_code']}' saved successfully.");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Error saving role permissions: ' . $e->getMessage());
                jsonResponse(false, 'Failed to save permissions. Database rolled back safely.', [], 500);
            }
            break;

        /* ========================================================
           9. DELETE ROLE (WITH SAFETY CHECKS)
           ======================================================== */
        case 'delete':
            $roleId = (int)($_POST['role_id'] ?? 0);
            if ($roleId <= 0) {
                jsonResponse(false, 'Invalid Role ID provided.', [], 400);
            }

            $rStmt = $pdo->prepare("SELECT * FROM roles WHERE role_id = :role_id LIMIT 1");
            $rStmt->execute([':role_id' => $roleId]);
            $role = $rStmt->fetch();

            if (!$role) {
                jsonResponse(false, 'Role not found.', [], 404);
            }

            if (strtoupper($role['role_code']) === 'PLATFORM_ADMIN') {
                jsonResponse(false, 'The Platform Administrator role cannot be deleted.', [], 403);
            }

            // Check if any users are assigned to this role
            $depStmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id");
            $depStmt->execute([':role_id' => $roleId]);
            $assignedCount = (int)$depStmt->fetchColumn();

            if ($assignedCount > 0) {
                jsonResponse(false, 'This role cannot be deleted because it is currently in use.', [], 409);
            }

            // Check if role has active role_menu mappings
            $rmCountStmt = $pdo->prepare("SELECT COUNT(*) FROM role_menu WHERE role_id = :role_id AND (can_view = TRUE OR is_active = TRUE)");
            $rmCountStmt->execute([':role_id' => $roleId]);
            $activeMappings = (int)$rmCountStmt->fetchColumn();

            if ($activeMappings > 0) {
                jsonResponse(false, 'This role cannot be deleted because it is currently in use.', [], 409);
            }

            // Safe to delete
            $delStmt = $pdo->prepare("DELETE FROM roles WHERE role_id = :role_id");
            $delStmt->execute([':role_id' => $roleId]);

            writeAuditLog(
                $pdo,
                $currentUserId,
                $roleId,
                'DELETE',
                $role,
                null
            );

            jsonResponse(true, "Role '{$role['role_code']}' deleted successfully.");
            break;

        default:
            jsonResponse(false, "Unknown action: '{$action}'", [], 400);
            break;
    }
} catch (Throwable $e) {
    error_log('Role Master API Exception: ' . $e->getMessage());
    jsonResponse(false, 'A server error occurred while processing your request.', [], 500);
}
