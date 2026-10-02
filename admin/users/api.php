<?php
/**
 * Cost360 - User Master API
 *
 * Works with the actual Cost360 database structure:
 *
 * users
 * user_roles
 * roles
 * categories
 * login_history
 * audit_log
 *
 * Existing User Master UI is kept unchanged.
 */

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
   AUTHENTICATION
   ============================================================ */

if (!isLoggedIn()) {

    jsonResponse(
        false,
        'Your session has expired. Please log in again.',
        [],
        401
    );
}


/* ============================================================
   DATABASE CONNECTION
   ============================================================ */

try {

    $pdo = getDbConnection();

} catch (Throwable $e) {

    error_log(
        'User Master DB connection error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to connect to the database.',
        [],
        500
    );
}


/* ============================================================
   CURRENT LOGGED-IN USER & PLATFORM ADMIN STATUS
   ============================================================ */

$currentUserId =
    (int)($_SESSION['user_id'] ?? 0);

$isPlatformAdmin =
    isPlatformAdmin($currentUserId);


/* ============================================================
   ADMIN ACCESSIBLE HIERARCHY TREE HELPER
   ============================================================ */

function getAdminAccessibleHierarchy(PDO $pdo, int $adminUserId): array {
    $isPlatform = isPlatformAdmin($adminUserId);
    $adminScope = getUserEffectiveOrganizationScope($adminUserId);

    // Customers
    $custSql = "SELECT customer_id, customer_code, customer_name FROM customers WHERE UPPER(status) = 'ACTIVE'";
    if (!$isPlatform) {
        $allowedCustIds = !empty($adminScope['customer_ids']) ? $adminScope['customer_ids'] : [-1];
        $inCust = implode(',', array_map('intval', $allowedCustIds));
        $custSql .= " AND customer_id IN ($inCust)";
    }
    $custSql .= " ORDER BY customer_name ASC";
    $customers = $pdo->query($custSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Companies
    $compSql = "SELECT company_id, customer_id, company_code, company_name FROM companies WHERE UPPER(status) = 'ACTIVE'";
    if (!$isPlatform) {
        $allowedCompIds = !empty($adminScope['company_ids']) ? $adminScope['company_ids'] : [-1];
        $inComp = implode(',', array_map('intval', $allowedCompIds));
        $compSql .= " AND company_id IN ($inComp)";
    }
    $compSql .= " ORDER BY company_name ASC";
    $companies = $pdo->query($compSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Divisions
    $divSql = "SELECT division_id, company_id, division_code, division_name FROM divisions WHERE UPPER(status) = 'ACTIVE'";
    if (!$isPlatform) {
        $allowedDivIds = !empty($adminScope['division_ids']) ? $adminScope['division_ids'] : [-1];
        $inDiv = implode(',', array_map('intval', $allowedDivIds));
        $divSql .= " AND division_id IN ($inDiv)";
    }
    $divSql .= " ORDER BY division_name ASC";
    $divisions = $pdo->query($divSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Plants
    $plantSql = "SELECT plant_id, division_id, plant_code, plant_name FROM plants WHERE UPPER(status) = 'ACTIVE'";
    if (!$isPlatform) {
        $allowedPlantIds = !empty($adminScope['plant_ids']) ? $adminScope['plant_ids'] : [-1];
        $inPlant = implode(',', array_map('intval', $allowedPlantIds));
        $plantSql .= " AND plant_id IN ($inPlant)";
    }
    $plantSql .= " ORDER BY plant_name ASC";
    $plants = $pdo->query($plantSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Warehouses
    $whSql = "SELECT warehouse_id, plant_id, warehouse_code, warehouse_name, status FROM warehouses WHERE UPPER(status) = 'ACTIVE'";
    if (!$isPlatform) {
        $allowedWhIds = !empty($adminScope['warehouse_ids']) ? $adminScope['warehouse_ids'] : [-1];
        $inWh = implode(',', array_map('intval', $allowedWhIds));
        $whSql .= " AND warehouse_id IN ($inWh)";
    }
    $whSql .= " ORDER BY warehouse_name ASC";
    $warehouses = $pdo->query($whSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Map plants by ID for quick parent lookup
    $plantMap = [];
    foreach ($plants as $p) {
        $plantMap[$p['plant_id']] = $p;
    }

    // Build unified locations by division (Plants + Warehouses as ONE common level)
    $locationsByDiv = [];
    foreach ($plants as $p) {
        $locationsByDiv[$p['division_id']][] = [
            'id'            => (int)$p['plant_id'],
            'location_id'   => (int)$p['plant_id'],
            'location_type' => 'Plant',
            'type_code'     => 'plant',
            'location_name' => $p['plant_name'],
            'location_code' => $p['plant_code'],
            'plant_id'      => (int)$p['plant_id'],
            'warehouse_id'  => null,
            'parent_plant'  => null
        ];
    }
    foreach ($warehouses as $wh) {
        $parent = $plantMap[$wh['plant_id']] ?? null;
        $divId = $parent ? $parent['division_id'] : null;
        if ($divId) {
            $locationsByDiv[$divId][] = [
                'id'            => (int)$wh['warehouse_id'],
                'location_id'   => (int)$wh['warehouse_id'],
                'location_type' => 'Warehouse',
                'type_code'     => 'warehouse',
                'location_name' => $wh['warehouse_name'],
                'location_code' => $wh['warehouse_code'],
                'plant_id'      => (int)$wh['plant_id'],
                'warehouse_id'  => (int)$wh['warehouse_id'],
                'parent_plant'  => $parent['plant_name'] ?? null
            ];
        }
    }

    // Sort locations in each division: Plants first, then Warehouses
    foreach ($locationsByDiv as $divId => &$locList) {
        usort($locList, function($a, $b) {
            if ($a['location_type'] !== $b['location_type']) {
                return ($a['location_type'] === 'Plant') ? -1 : 1;
            }
            return strcasecmp($a['location_name'], $b['location_name']);
        });
    }
    unset($locList);

    // Build nested tree
    $whByPlant = [];
    foreach ($warehouses as $wh) {
        $whByPlant[$wh['plant_id']][] = $wh;
    }

    $plantsByDiv = [];
    foreach ($plants as $p) {
        $p['warehouses'] = $whByPlant[$p['plant_id']] ?? [];
        $plantsByDiv[$p['division_id']][] = $p;
    }

    $divsByComp = [];
    foreach ($divisions as $d) {
        $d['plants']    = $plantsByDiv[$d['division_id']] ?? [];
        $d['locations'] = $locationsByDiv[$d['division_id']] ?? [];
        $divsByComp[$d['company_id']][] = $d;
    }

    $compsByCust = [];
    foreach ($companies as $c) {
        $c['divisions'] = $divsByComp[$c['company_id']] ?? [];
        $compsByCust[$c['customer_id']][] = $c;
    }

    $tree = [];
    foreach ($customers as $cust) {
        $cust['companies'] = $compsByCust[$cust['customer_id']] ?? [];
        $tree[] = $cust;
    }

    return $tree;
}


/* ============================================================
   USER MASTER MENU ACCESS
   ============================================================ */

if (!isPlatformAdmin($currentUserId) && !isCmaAdmin($currentUserId) && !userHasMenuAccess('USER_MASTER') && !userHasMenuAccess('CMA_USER_MASTER')) {

    jsonResponse(
        false,
        'Access denied. You do not have access to User Master.',
        [],
        403
    );
}


/* ============================================================
   GET ACTION
   ============================================================ */

$action = 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        trim($_POST['action'] ?? 'list');

} else {

    $action =
        trim($_GET['action'] ?? 'list');
}


/* ============================================================
   PERMISSION CHECK
   ============================================================ */

function requireUserMasterPermission(
    string $permission
): void {

    if (isPlatformAdmin() || isCmaAdmin()) {
        return;
    }

    if (
        !userHasPermission(
            $permission,
            'USER_MASTER'
        ) &&
        !userHasPermission(
            $permission,
            'CMA_USER_MASTER'
        )
    ) {

        jsonResponse(
            false,
            "You do not have {$permission} permission for User Master.",
            [],
            403
        );
    }
}


/* ============================================================
   STATUS NORMALIZATION
   ============================================================ */

function normalizeUserStatus(
    string $status
): string {

    $status =
        strtoupper(trim($status));

    $allowedStatuses = [
        'ACTIVE',
        'INACTIVE',
        'SUSPENDED',
        'LOCKED'
    ];

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {

        return 'ACTIVE';
    }

    return $status;
}


/* ============================================================
   GET ACTIVE ROLE
   ============================================================ */

function getRole(
    PDO $pdo,
    int $roleId
): array {

    if ($roleId <= 0) {

        throw new Exception(
            'Please select a valid role.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT
            r.role_id,
            r.category_id,
            r.role_code,
            r.role_name,
            r.description,
            r.status,
            c.category_code,
            c.category_name

        FROM roles r

        INNER JOIN categories c
            ON c.category_id = r.category_id

        WHERE r.role_id = :role_id
          AND r.status = 'Active'
          AND c.status = 'Active'

        LIMIT 1
    ");

    $stmt->execute([
        ':role_id' => $roleId
    ]);

    $role =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {

        throw new Exception(
            'Selected role does not exist or is inactive.'
        );
    }

    return $role;
}


/* ============================================================
   GET USER
   ============================================================ */

function getUser(
    PDO $pdo,
    int $userId
): ?array {

    $stmt = $pdo->prepare("
        SELECT
            u.user_id,
            u.category_id,
            u.full_name,
            u.email,
            u.phone,

            u.username,
            u.first_name,
            u.last_name,

            u.status,
            u.is_active,
            u.is_email_verified,

            u.failed_login_attempts,
            u.locked_until,

            u.last_login_at,

            u.created_at,
            u.created_by,
            u.updated_at,

            r.role_id,
            r.role_code,
            r.role_name

        FROM users u

        LEFT JOIN LATERAL
        (
            SELECT
                ur.role_id

            FROM user_roles ur

            WHERE ur.user_id = u.user_id
              AND ur.status = 'Active'

            ORDER BY
                ur.assigned_at DESC,
                ur.user_role_id DESC

            LIMIT 1

        ) cur_role
            ON TRUE

        LEFT JOIN roles r
            ON r.role_id = cur_role.role_id

        WHERE u.user_id = :user_id

        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' => $userId
    ]);

    $user =
        $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}


/* ============================================================
   AUDIT LOG
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
        // Map standard audit actions (Phase 17)
        $mappedAction = $action;
        if ($action === 'INSERT') {
            $mappedAction = 'USER_CREATED';
        } elseif ($action === 'UPDATE') {
            if (isset($newValue['status']) && isset($oldValue['status']) && $newValue['status'] !== $oldValue['status']) {
                $mappedAction = (strtoupper($newValue['status']) === 'ACTIVE') ? 'USER_ACTIVATED' : 'USER_DEACTIVATED';
            } else {
                $mappedAction = 'USER_UPDATED';
            }
        }

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
            ':entity_name' => 'users',
            ':entity_id' => $entityId,
            ':action' => $mappedAction,
            ':old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            ':new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        error_log("Write User Audit Log Warning: " . $e->getMessage());
        // Fallback to basic INSERT/UPDATE/DELETE if constraint still exists
        try {
            $fallbackAction = in_array(strtoupper($action), ['INSERT', 'UPDATE', 'DELETE'], true) ? strtoupper($action) : 'INSERT';
            $fallbackStmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES (:user_id, 'users', :entity_id, :action, :old_value, :new_value, CURRENT_TIMESTAMP, :ip_address)
            ");
            $fallbackStmt->execute([
                ':user_id' => $currentUserId > 0 ? $currentUserId : null,
                ':entity_id' => $entityId,
                ':action' => $fallbackAction,
                ':old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
                ':new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (Throwable $f) {
            error_log("Fallback User Audit Log Error: " . $f->getMessage());
        }
    }
}


/* ============================================================
   MAIN
   ============================================================ */

try {

    switch ($action) {


        /* ======================================================
           LIST USERS
           ====================================================== */

        case 'list':

            $search =
                trim($_GET['search'] ?? '');

            $roleId =
                trim($_GET['role_id'] ?? '');

            $status =
                trim($_GET['status'] ?? '');


            /*
             * Get latest ACTIVE role.
             *
             * A user can technically have multiple
             * records in user_roles because the schema
             * supports multiple role assignments.
             *
             * The current UI displays one role, so we
             * display the latest active role.
             */

            $sql = "
                SELECT

                    u.user_id,

                    u.username,

                    u.first_name,
                    u.last_name,
                    u.full_name,

                    u.email,

                    u.phone AS mobile_number,

                    u.status,

                    u.is_email_verified,

                    u.created_at,
                    u.created_by,
                    creator.username AS created_by_username,
                    creator.full_name AS created_by_name,
                    u.updated_at,

                    u.last_login_at,

                    r.role_id,
                    r.role_code,
                    r.role_name,
                    c.category_code,
                    c.category_name

                FROM users u

                LEFT JOIN users creator
                    ON creator.user_id = u.created_by

                LEFT JOIN LATERAL
                (
                    SELECT
                        ur.role_id

                    FROM user_roles ur

                    WHERE ur.user_id = u.user_id
                      AND ur.status = 'Active'

                    ORDER BY
                        ur.assigned_at DESC,
                        ur.user_role_id DESC

                    LIMIT 1

                ) cur_role
                    ON TRUE

                LEFT JOIN roles r
                    ON r.role_id =
                       cur_role.role_id

                LEFT JOIN categories c
                    ON c.category_id =
                       r.category_id

                WHERE 1 = 1
            ";

            $params = [];

            if (isCmaAdmin($currentUserId)) {
                $cmaCustId = getCmaAdminCustomerId($currentUserId);
                $sql .= " AND (
                    u.user_id = :cma_user_id 
                    OR (
                        EXISTS (
                            SELECT 1 FROM user_organization_access uoa 
                            WHERE uoa.user_id = u.user_id 
                              AND uoa.customer_id = :cma_cust_id 
                              AND UPPER(uoa.status) = 'ACTIVE'
                        )
                        AND COALESCE(r.role_code, '') NOT IN ('PLATFORM_ADMIN', 'SUPER_ADMIN', 'CMA_ADMIN')
                    )
                )";
                $params[':cma_user_id'] = $currentUserId;
                $params[':cma_cust_id'] = $cmaCustId ?: 0;
            }


            /* --------------------------------------------------
               SEARCH
               -------------------------------------------------- */

            if ($search !== '') {

                $sql .= "
                    AND
                    (
                        u.username ILIKE :search

                        OR u.email ILIKE :search

                        OR u.first_name ILIKE :search

                        OR u.last_name ILIKE :search

                        OR u.full_name ILIKE :search
                    )
                ";

                $params[':search'] =
                    '%' . $search . '%';
            }


            /* --------------------------------------------------
               ROLE FILTER
               -------------------------------------------------- */

            if (
                $roleId !== '' &&
                strtoupper($roleId) !== 'ALL'
            ) {

                if (!ctype_digit($roleId)) {

                    throw new Exception(
                        'Invalid role filter.'
                    );
                }

                $sql .= "
                    AND r.role_id = :role_id
                ";

                $params[':role_id'] =
                    (int)$roleId;
            }


            /* --------------------------------------------------
               STATUS FILTER
               -------------------------------------------------- */

            if (
                $status !== '' &&
                strtoupper($status) !== 'ALL'
            ) {

                $status =
                    normalizeUserStatus(
                        $status
                    );

                $sql .= "
                    AND UPPER(u.status) = :status
                ";

                $params[':status'] =
                    $status;
            }


            /* --------------------------------------------------
               STRICT TENANT & ROLE ISOLATION FOR CUSTOMER ADMIN
               -------------------------------------------------- */
            if (!$isPlatformAdmin) {
                // Resolve admin's tenant customer_id from their org access
                $adminTenantId = getUserCustomerTenantId($currentUserId);

                if ($adminTenantId) {
                    // Detect if customer_id column exists on users table to avoid 42703 error
                    $hasUserCustomerCol = false;
                    try {
                        $cCheck = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'customer_id' LIMIT 1");
                        $hasUserCustomerCol = (bool)$cCheck->fetchColumn();
                    } catch (Throwable $ignore) {}

                    $userCustExpr = $hasUserCustomerCol
                        ? "COALESCE(u.customer_id, (
                            SELECT COALESCE(uoa2.customer_id, comp2.customer_id)
                            FROM user_organization_access uoa2
                            LEFT JOIN companies comp2 ON comp2.company_id = uoa2.company_id
                            WHERE uoa2.user_id = u.user_id
                              AND (uoa2.customer_id IS NOT NULL OR comp2.customer_id IS NOT NULL)
                              AND UPPER(uoa2.status) = 'ACTIVE'
                            ORDER BY uoa2.access_id ASC
                            LIMIT 1
                        ))"
                        : "(
                            SELECT COALESCE(uoa2.customer_id, comp2.customer_id)
                            FROM user_organization_access uoa2
                            LEFT JOIN companies comp2 ON comp2.company_id = uoa2.company_id
                            WHERE uoa2.user_id = u.user_id
                              AND (uoa2.customer_id IS NOT NULL OR comp2.customer_id IS NOT NULL)
                              AND UPPER(uoa2.status) = 'ACTIVE'
                            ORDER BY uoa2.access_id ASC
                            LIMIT 1
                        )";

                    $sql .= "
                        AND (
                            u.user_id = :current_user_id
                            OR (
                                $userCustExpr = :admin_tenant_id
                                AND u.created_by = :creator_user_id
                            )
                        )
                    ";
                    $params[':admin_tenant_id']  = $adminTenantId;
                } else {
                    // No tenant resolved — fall back to created_by only
                    $sql .= "
                        AND (
                            u.user_id = :current_user_id
                            OR u.created_by = :creator_user_id
                        )
                    ";
                }
                $params[':current_user_id'] = $currentUserId;
                $params[':creator_user_id'] = $currentUserId;

                // Always block Platform/CMA admin accounts from appearing in Customer Admin view
                $sql .= " AND (r.role_code IS NULL OR UPPER(r.role_code) NOT IN ('PLATFORM_ADMIN', 'CMA_ADMIN', 'CMA_USER')) ";
                $sql .= " AND (c.category_code IS NULL OR c.category_code NOT IN ('PLAT_ADMIN', 'CMA_ADMIN')) ";
            }


            $sql .= "
                ORDER BY
                    u.user_id ASC
            ";


            $stmt =
                $pdo->prepare($sql);

            $stmt->execute($params);

            $users =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );


            $formattedUsers = [];

            require_once __DIR__ . '/../../config/subscription.php';

            // Pre-fetch access summaries for all listed users
            $userAccessSummaries = [];
            $userIdsList = array_column($users, 'user_id');
            if (!empty($userIdsList)) {
                $inUids = implode(',', array_map('intval', $userIdsList));
                $stmtAccSummary = $pdo->prepare("
                    SELECT 
                        uoa.user_id,
                        uoa.customer_id,
                        uoa.company_id,
                        c.company_name,
                        uoa.division_id,
                        d.division_name,
                        uoa.plant_id,
                        p.plant_name
                    FROM user_organization_access uoa
                    LEFT JOIN companies c ON c.company_id = uoa.company_id
                    LEFT JOIN divisions d ON d.division_id = uoa.division_id
                    LEFT JOIN plants p ON p.plant_id = uoa.plant_id
                    WHERE uoa.user_id IN ($inUids) AND UPPER(uoa.status) = 'ACTIVE'
                ");
                $stmtAccSummary->execute();
                $accRows = $stmtAccSummary->fetchAll(PDO::FETCH_ASSOC);

                $userAccGroup = [];
                foreach ($accRows as $ar) {
                    $uid = (int)$ar['user_id'];
                    if (!isset($userAccGroup[$uid])) $userAccGroup[$uid] = [];
                    $userAccGroup[$uid][] = $ar;
                }

                foreach ($userIdsList as $uid) {
                    $uRecs = $userAccGroup[$uid] ?? [];
                    if (empty($uRecs)) {
                        $userAccessSummaries[$uid] = 'No Access';
                        continue;
                    }

                    $hasFullAccess = false;
                    $compNames = [];
                    $divNames = [];
                    $plantNames = [];

                    foreach ($uRecs as $r) {
                        if (empty($r['company_id']) && empty($r['division_id']) && empty($r['plant_id'])) {
                            $hasFullAccess = true;
                            break;
                        }
                        if (!empty($r['company_name'])) $compNames[$r['company_name']] = true;
                        if (!empty($r['division_name'])) $divNames[$r['division_name']] = true;
                        if (!empty($r['plant_name'])) $plantNames[$r['plant_name']] = true;
                    }

                    if ($hasFullAccess) {
                        $userAccessSummaries[$uid] = 'Full Access';
                    } elseif (count($compNames) > 1) {
                        $userAccessSummaries[$uid] = count($compNames) . ' Companies';
                    } elseif (count($compNames) === 1) {
                        $cName = array_keys($compNames)[0];
                        if (!empty($plantNames)) {
                            $userAccessSummaries[$uid] = array_keys($plantNames)[0];
                        } elseif (!empty($divNames)) {
                            $userAccessSummaries[$uid] = array_keys($divNames)[0];
                        } else {
                            $userAccessSummaries[$uid] = $cName . ' (Full)';
                        }
                    } else {
                        $userAccessSummaries[$uid] = 'Custom Scope';
                    }
                }
            }


            foreach ($users as $user) {

                $firstName =
                    trim(
                        $user['first_name'] ?? ''
                    );

                $lastName =
                    trim(
                        $user['last_name'] ?? ''
                    );


                $fullName =
                    trim(
                        $firstName .
                        ' ' .
                        $lastName
                    );


                if ($fullName === '') {

                    $fullName =
                        trim(
                            $user['full_name'] ?? ''
                        );
                }


                if ($fullName === '') {

                    $fullName =
                        $user['username'] ?? '';
                }

                $uidInt = (int)$user['user_id'];


                $formattedUsers[] = [

                    'user_id' =>
                        $uidInt,

                    'username' =>
                        $user['username'] ?? '',

                    'first_name' =>
                        $firstName,

                    'last_name' =>
                        $lastName,

                    'full_name' =>
                        $fullName,

                    'email' =>
                        $user['email'] ?? '',

                    'mobile_number' =>
                        $user['mobile_number'] ?? '',

                    'status' =>
                        strtoupper(
                            $user['status']
                                ?? 'ACTIVE'
                        ),

                    'role_id' =>
                        $user['role_id'] !== null
                            ? (int)$user['role_id']
                            : null,

                    'role_code' =>
                        $user['role_code']
                            ?? 'UNASSIGNED',

                    'role_name' =>
                        $user['role_name']
                            ?? 'No Role Assigned',

                    'access_summary' =>
                        $userAccessSummaries[$uidInt] ?? 'No Access',

                    'created_at' =>
                        !empty(
                            $user['created_at']
                        )
                            ? date(
                                'd M Y, H:i',
                                strtotime(
                                    $user['created_at']
                                )
                            )
                            : '—',

                    'created_by' =>
                        (int)($user['created_by'] ?? 0),

                    'created_by_name' =>
                        trim($user['created_by_name'] ?? $user['created_by_username'] ?? '') ?: 'System',

                    // access_summary: real badge from DB via getUserAccessSummary()
                    'access_summary' => getUserAccessSummary((int)$user['user_id']),

                    // can_manage: computed inline from SQL row — NO DB round-trip.
                    // Platform Admin manages all. Others: self or users they created within their tenant.
                    'can_manage' =>
                        $isPlatformAdmin
                        || ((int)$user['user_id'] === $currentUserId)
                        || ((int)($user['created_by'] ?? 0) === $currentUserId),

                    'last_login_at' =>
                        !empty(
                            $user['last_login_at']
                        )
                            ? date(
                                'd M Y, H:i',
                                strtotime(
                                    $user['last_login_at']
                                )
                            )
                            : 'Never'
                ];
            }

            // Subscription limit payload for CMA Admin / Tenant awareness
            $subLimitInfo = null;
            if (isCmaAdmin($currentUserId)) {
                $cmaCustId = getCmaAdminCustomerId($currentUserId);
                if ($cmaCustId) {
                    $subLimitInfo = checkUserLimit($cmaCustId);
                }
            }


            jsonResponse(
                true,
                '',
                [
                    'count' =>
                        count($formattedUsers),

                    'users' =>
                        $formattedUsers,

                    'subscription_limit' =>
                        $subLimitInfo
                ]
            );

            break;


        /* ======================================================
           ROLES
           ====================================================== */

        case 'roles':

            /*
             * The role dropdown should contain only
             * active roles belonging to active categories.
             * Customer Admins are restricted from selecting Platform Admin roles.
             */

            $sql = "
                SELECT
                    r.role_id,
                    r.category_id,
                    r.role_code,
                    r.role_name,
                    r.description,
                    c.category_code,
                    c.category_name
                FROM roles r
                INNER JOIN categories c
                    ON c.category_id = r.category_id
                WHERE r.status = 'Active'
                  AND c.status = 'Active'
            ";

            if (!$isPlatformAdmin) {
                $sql .= " AND UPPER(r.role_code) NOT IN ('PLATFORM_ADMIN', 'CMA_ADMIN', 'CMA_USER') AND c.category_code NOT IN ('PLAT_ADMIN', 'CMA_ADMIN')";
            }

            if (function_exists('isCmaAdmin') && isCmaAdmin($currentUserId)) {
                $sql .= " AND r.role_code NOT IN ('PLATFORM_ADMIN', 'SUPER_ADMIN', 'CMA_ADMIN')";
            }

            $sql .= "
                ORDER BY
                    r.role_name ASC
            ";

            $stmt = $pdo->query($sql);
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);


            jsonResponse(
                true,
                '',
                [
                    'roles' => $roles
                ]
            );

            break;


        /* ======================================================
           GET USER
           ====================================================== */

        case 'get_user':

            $userId =
                (int)(
                    $_GET['user_id'] ?? 0
                );


            if ($userId <= 0) {

                throw new Exception(
                    'Invalid User ID.'
                );
            }


            if (!$isPlatformAdmin && !canAdminManageUser($currentUserId, $userId)) {

                throw new Exception(
                    'Access denied. You do not have permission to view this user.'
                );
            }


            $user =
                getUser(
                    $pdo,
                    $userId
                );


            if (!$user) {

                throw new Exception(
                    'User not found.'
                );
            }


            $userAccessSummary = getUserAccessSummary($userId);


            jsonResponse(
                true,
                '',
                [
                    'user' => [

                        'user_id' =>
                            (int)$user['user_id'],

                        'username' =>
                            $user['username'] ?? '',

                        'first_name' =>
                            $user['first_name'] ?? '',

                        'last_name' =>
                            $user['last_name'] ?? '',

                        'full_name' =>
                            $user['full_name'] ?? '',

                        'email' =>
                            $user['email'] ?? '',

                        'mobile_number' =>
                            $user['phone'] ?? '',

                        'status' =>
                            strtoupper(
                                $user['status']
                                    ?? 'ACTIVE'
                            ),

                        'role_id' =>
                            $user['role_id'] !== null
                                ? (int)$user['role_id']
                                : null,

                        'role_code' =>
                            $user['role_code'] ?? '',

                        'role_name' =>
                            $user['role_name'] ?? '',

                        'access_summary' =>
                            $userAccessSummary
                    ]
                ]
            );

            break;


        /* ======================================================
           CREATE USER
           ====================================================== */

        case 'create':

            requireUserMasterPermission(
                'CREATE'
            );


            if (
                $_SERVER['REQUEST_METHOD']
                !== 'POST'
            ) {

                throw new Exception(
                    'Invalid request method.'
                );
            }


            $username =
                trim(
                    $_POST['username'] ?? ''
                );

            $firstName =
                trim(
                    $_POST['first_name'] ?? ''
                );

            $lastName =
                trim(
                    $_POST['last_name'] ?? ''
                );

            $email =
                trim(
                    $_POST['email'] ?? ''
                );

            $password =
                $_POST['password'] ?? '';

            $confirmPassword =
                $_POST['confirm_password']
                ?? '';

            $roleId =
                (int)(
                    $_POST['role_id'] ?? 0
                );

            $status =
                normalizeUserStatus(
                    $_POST['status']
                        ?? 'ACTIVE'
                );


            /* --------------------------------------------------
               VALIDATION
               -------------------------------------------------- */

            if ($firstName === '') {

                throw new Exception(
                    'First name is required.'
                );
            }


            if ($username === '') {

                throw new Exception(
                    'Username is required.'
                );
            }


            if (
                strlen($username) > 150
            ) {

                throw new Exception(
                    'Username cannot exceed 150 characters.'
                );
            }


            if ($email === '') {

                throw new Exception(
                    'Email address is required.'
                );
            }


            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                throw new Exception(
                    'Please enter a valid email address.'
                );
            }


            if ($password === '') {

                throw new Exception(
                    'Password is required.'
                );
            }


            if (strlen($password) < 6) {

                throw new Exception(
                    'Password must be at least 6 characters long.'
                );
            }


            if (
                $password !==
                $confirmPassword
            ) {

                throw new Exception(
                    'Passwords do not match.'
                );
            }


            /* --------------------------------------------------
               ROLE
               -------------------------------------------------- */

            $role =
                getRole(
                    $pdo,
                    $roleId
                );

            if (!$isPlatformAdmin && (
                in_array(strtoupper($role['role_code'] ?? ''), ['PLATFORM_ADMIN', 'CMA_ADMIN', 'CMA_USER'], true) ||
                in_array(strtoupper($role['category_code'] ?? ''), ['PLAT_ADMIN', 'CMA_ADMIN'], true)
            )) {
                throw new Exception(
                    'Security Violation: Customer Admins can only assign Customer-level roles.'
                );
            }

            /* --------------------------------------------------
               CUSTOMER / TENANT & SUBSCRIPTION USER LIMIT ENFORCEMENT
               -------------------------------------------------- */
            $targetCustomerId = 0;
            if (function_exists('isCmaAdmin') && isCmaAdmin($currentUserId)) {
                $targetCustomerId = getCmaAdminCustomerId($currentUserId);
                if (!$targetCustomerId) {
                    throw new Exception('No customer organization assigned to this CMA Admin.');
                }
                if (in_array(strtoupper($role['role_code'] ?? ''), ['PLATFORM_ADMIN', 'SUPER_ADMIN', 'CMA_ADMIN'], true)) {
                    throw new Exception('CMA Admins can only assign operational customer roles.');
                }
            } elseif (!$isPlatformAdmin) {
                $targetCustomerId = (int)getUserCustomerTenantId($currentUserId);
            }
            if ($targetCustomerId <= 0 && !empty($_POST['customer_id'])) {
                $targetCustomerId = (int)$_POST['customer_id'];
            } elseif ($targetCustomerId <= 0 && !empty($_SESSION['active_customer_id'])) {
                $targetCustomerId = (int)$_SESSION['active_customer_id'];
            } else if ($targetCustomerId <= 0) {
                // Check if creator is a customer-scoped user (e.g. Customer Admin)
                $stmtScope = $pdo->prepare("
                    SELECT customer_id 
                    FROM user_organization_access 
                    WHERE user_id = :uid AND UPPER(status) = 'ACTIVE' AND customer_id IS NOT NULL 
                    ORDER BY access_id ASC LIMIT 1
                ");
                $stmtScope->execute([':uid' => $currentUserId]);
                $targetCustomerId = (int)($stmtScope->fetchColumn() ?: 0);
            }

            $isCmaUserCreation = (strtoupper($role['role_code'] ?? '') === 'CMA_USER') || isCmaAdmin($currentUserId);
            if ($targetCustomerId > 0 && $isCmaUserCreation) {
                require_once __DIR__ . '/../../config/subscription.php';
                $limitCheck = checkUserLimit($targetCustomerId);
                if (!$limitCheck['allowed']) {
                    throw new Exception($limitCheck['reason']);
                }
            }


            /* --------------------------------------------------
               DUPLICATE USERNAME
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                SELECT user_id
                FROM users

                WHERE LOWER(username)
                    = LOWER(:username)

                LIMIT 1
            ");

            $stmt->execute([
                ':username' =>
                    $username
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'A user with this username already exists.'
                );
            }


            /* --------------------------------------------------
               DUPLICATE EMAIL
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                SELECT user_id
                FROM users

                WHERE LOWER(email)
                    = LOWER(:email)

                LIMIT 1
            ");

            $stmt->execute([
                ':email' =>
                    $email
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'A user with this email address already exists.'
                );
            }


            /* --------------------------------------------------
               PASSWORD
               -------------------------------------------------- */

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            if ($passwordHash === false) {

                throw new Exception(
                    'Unable to securely create password.'
                );
            }


            $fullName =
                trim(
                    $firstName .
                    ' ' .
                    $lastName
                );


            /*
             * users.category_id is NOT NULL.
             *
             * Therefore category comes from
             * the selected role.
             */

            $categoryId =
                (int)$role['category_id'];


            $isActive =
                $status === 'ACTIVE';


            $pdo->beginTransaction();


            /* --------------------------------------------------
               INSERT USER
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                INSERT INTO users
                (
                    category_id,
                    full_name,
                    email,
                    phone,
                    password_hash,
                    is_active,
                    created_by,
                    updated_at,

                    username,
                    first_name,
                    last_name,
                    status,
                    is_email_verified,
                    failed_login_attempts,
                    locked_until,
                    last_login_at
                )

                VALUES
                (
                    :category_id,
                    :full_name,
                    :email,
                    NULL,
                    :password_hash,
                    :is_active,
                    :created_by,
                    CURRENT_TIMESTAMP,

                    :username,
                    :first_name,
                    :last_name,
                    :status,
                    FALSE,
                    0,
                    NULL,
                    NULL
                )

                RETURNING user_id
            ");


            $stmt->execute([

                ':category_id' =>
                    $categoryId,

                ':full_name' =>
                    $fullName,

                ':email' =>
                    $email,

                ':password_hash' =>
                    $passwordHash,

                ':is_active' =>
                    $isActive,

                ':created_by' =>
                    $currentUserId > 0
                        ? $currentUserId
                        : null,

                ':username' =>
                    $username,

                ':first_name' =>
                    $firstName,

                ':last_name' =>
                    $lastName,

                ':status' =>
                    $status
            ]);


            $newUserId =
                (int)$stmt->fetchColumn();


            if ($newUserId <= 0) {

                throw new Exception(
                    'Failed to create user.'
                );
            }


            /* --------------------------------------------------
               ASSIGN ROLE
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                INSERT INTO user_roles
                (
                    user_id,
                    role_id,
                    assigned_at,
                    assigned_by,
                    status
                )

                VALUES
                (
                    :user_id,
                    :role_id,
                    CURRENT_TIMESTAMP,
                    :assigned_by,
                    'Active'
                )
            ");

            $stmt->execute([

                ':user_id' =>
                    $newUserId,

                ':role_id' =>
                    $roleId,

                ':assigned_by' =>
                    $currentUserId > 0
                        ? $currentUserId
                        : null
            ]);

            /* --------------------------------------------------
               CUSTOMER TENANT SCOPE & HIERARCHICAL ACCESS
               -------------------------------------------------- */
            $hasExplicitAccess = false;
            if (!empty($_POST['organization_access'])) {
                $accessData = $_POST['organization_access'];
                if (is_string($accessData)) {
                    $accessData = json_decode($accessData, true);
                }
                if (is_array($accessData) && !empty($accessData)) {
                    saveUserOrganizationAccess($newUserId, $accessData, $currentUserId);
                    $hasExplicitAccess = true;
                }
            }

            if (!$hasExplicitAccess && $targetCustomerId > 0) {
                $stmtCustAccess = $pdo->prepare("
                    INSERT INTO user_organization_access
                    (
                        user_id,
                        customer_id,
                        valid_from,
                        status
                    )
                    VALUES
                    (
                        :user_id,
                        :customer_id,
                        CURRENT_DATE,
                        'Active'
                    )
                ");
                $stmtCustAccess->execute([
                    ':user_id' => $newUserId,
                    ':customer_id' => $targetCustomerId
                ]);
            }

            /* --------------------------------------------------
               AUDIT
               -------------------------------------------------- */

            writeAuditLog(
                $pdo,
                $currentUserId,
                $newUserId,
                'INSERT',
                null,
                [
                    'username' =>
                        $username,

                    'first_name' =>
                        $firstName,

                    'last_name' =>
                        $lastName,

                    'email' =>
                        $email,

                    'category_id' =>
                        $categoryId,

                    'role_id' =>
                        $roleId,

                    'role_code' =>
                        $role['role_code'],

                    'status' =>
                        $status
                ]
            );


            $pdo->commit();


            jsonResponse(
                true,
                "User '{$username}' created successfully.",
                [
                    'user_id' =>
                        $newUserId
                ]
            );

            break;


        /* ======================================================
           UPDATE USER
           ====================================================== */

        case 'update':

            requireUserMasterPermission(
                'EDIT'
            );


            if (
                $_SERVER['REQUEST_METHOD']
                !== 'POST'
            ) {

                throw new Exception(
                    'Invalid request method.'
                );
            }


            $userId =
                (int)(
                    $_POST['user_id'] ?? 0
                );

            $username =
                trim(
                    $_POST['username'] ?? ''
                );

            $firstName =
                trim(
                    $_POST['first_name'] ?? ''
                );

            $lastName =
                trim(
                    $_POST['last_name'] ?? ''
                );

            $email =
                trim(
                    $_POST['email'] ?? ''
                );

            $roleId =
                (int)(
                    $_POST['role_id'] ?? 0
                );

            $status =
                normalizeUserStatus(
                    $_POST['status']
                        ?? 'ACTIVE'
                );


            /* --------------------------------------------------
               VALIDATION
               -------------------------------------------------- */

            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }


            if (!$isPlatformAdmin && !canAdminManageUser($currentUserId, $userId)) {

                throw new Exception(
                    'Access denied. You do not have permission to manage this user.'
                );
            }


            if ($username === '') {

                throw new Exception(
                    'Username is required.'
                );
            }


            if ($firstName === '') {

                throw new Exception(
                    'First name is required.'
                );
            }


            if ($email === '') {

                throw new Exception(
                    'Email address is required.'
                );
            }


            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                throw new Exception(
                    'Please enter a valid email address.'
                );
            }


            /* --------------------------------------------------
               EXISTING USER
               -------------------------------------------------- */

            $oldUser =
                getUser(
                    $pdo,
                    $userId
                );


            if (!$oldUser) {

                throw new Exception(
                    'User not found.'
                );
            }


            /* --------------------------------------------------
               ROLE
               -------------------------------------------------- */

            $role =
                getRole(
                    $pdo,
                    $roleId
                );


            if (!$isPlatformAdmin && (
                in_array(strtoupper($role['role_code'] ?? ''), ['PLATFORM_ADMIN', 'CMA_ADMIN', 'CMA_USER'], true) ||
                in_array(strtoupper($role['category_code'] ?? ''), ['PLAT_ADMIN', 'CMA_ADMIN'], true)
            )) {
                throw new Exception(
                    'Security Violation: Customer Admins can only assign Customer-level roles.'
                );
            }


            /* --------------------------------------------------
               USERNAME DUPLICATE
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                SELECT user_id

                FROM users

                WHERE LOWER(username)
                    = LOWER(:username)

                  AND user_id <> :user_id

                LIMIT 1
            ");

            $stmt->execute([

                ':username' =>
                    $username,

                ':user_id' =>
                    $userId
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'Another user is already using this username.'
                );
            }


            /* --------------------------------------------------
               EMAIL DUPLICATE
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                SELECT user_id

                FROM users

                WHERE LOWER(email)
                    = LOWER(:email)

                  AND user_id <> :user_id

                LIMIT 1
            ");

            $stmt->execute([

                ':email' =>
                    $email,

                ':user_id' =>
                    $userId
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'Another user is already using this email address.'
                );
            }


            $fullName =
                trim(
                    $firstName .
                    ' ' .
                    $lastName
                );


            $categoryId =
                (int)$role['category_id'];


            $isActive =
                $status === 'ACTIVE';


            $pdo->beginTransaction();


            /* --------------------------------------------------
               UPDATE USER
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                UPDATE users

                SET
                    category_id = :category_id,

                    full_name = :full_name,

                    email = :email,

                    username = :username,

                    first_name = :first_name,

                    last_name = :last_name,

                    status = :status,

                    is_active = :is_active,

                    updated_at = CURRENT_TIMESTAMP

                WHERE user_id = :user_id
            ");

            $stmt->execute([

                ':category_id' =>
                    $categoryId,

                ':full_name' =>
                    $fullName,

                ':email' =>
                    $email,

                ':username' =>
                    $username,

                ':first_name' =>
                    $firstName,

                ':last_name' =>
                    $lastName,

                ':status' =>
                    $status,

                ':is_active' =>
                    $isActive,

                ':user_id' =>
                    $userId
            ]);


            /* --------------------------------------------------
               DEACTIVATE CURRENT ACTIVE ROLES
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                UPDATE user_roles

                SET status = 'Inactive'

                WHERE user_id = :user_id

                  AND status = 'Active'
            ");

            $stmt->execute([
                ':user_id' =>
                    $userId
            ]);


            /* --------------------------------------------------
               ASSIGN SELECTED ROLE
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                INSERT INTO user_roles
                (
                    user_id,
                    role_id,
                    assigned_at,
                    assigned_by,
                    status
                )

                VALUES
                (
                    :user_id,
                    :role_id,
                    CURRENT_TIMESTAMP,
                    :assigned_by,
                    'Active'
                )

                ON CONFLICT
                (
                    user_id,
                    role_id
                )

                DO UPDATE SET

                    status = 'Active',

                    assigned_at =
                        CURRENT_TIMESTAMP,

                    assigned_by =
                        EXCLUDED.assigned_by
            ");

            $stmt->execute([

                ':user_id' =>
                    $userId,

                ':role_id' =>
                    $roleId,

                ':assigned_by' =>
                    $currentUserId > 0
                        ? $currentUserId
                        : null
            ]);


            /* --------------------------------------------------
               AUDIT
               -------------------------------------------------- */

            writeAuditLog(
                $pdo,
                $currentUserId,
                $userId,
                'UPDATE',
                [
                    'username' =>
                        $oldUser['username'] ?? '',

                    'first_name' =>
                        $oldUser['first_name'] ?? '',

                    'last_name' =>
                        $oldUser['last_name'] ?? '',

                    'email' =>
                        $oldUser['email'] ?? '',

                    'category_id' =>
                        $oldUser['category_id'] ?? null,

                    'role_id' =>
                        $oldUser['role_id'] ?? null,

                    'status' =>
                        $oldUser['status'] ?? ''
                ],
                [
                    'username' =>
                        $username,

                    'first_name' =>
                        $firstName,

                    'last_name' =>
                        $lastName,

                    'email' =>
                        $email,

                    'category_id' =>
                        $categoryId,

                    'role_id' =>
                        $roleId,

                    'role_code' =>
                        $role['role_code'],

                    'status' =>
                        $status
                ]
            );


            $pdo->commit();


            /*
             * If current user edited themselves,
             * remove cached authentication data.
             */

            if (
                $userId ===
                $currentUserId
            ) {

                unset(
                    $_SESSION['auth_cache']
                );
            }


            jsonResponse(
                true,
                'User details updated successfully.'
            );

            break;


        /* ======================================================
           TOGGLE USER STATUS (ACTIVATE / DEACTIVATE)
           ====================================================== */

        case 'toggle_status':
            requireUserMasterPermission('EDIT');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method.');
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new Exception('Invalid user ID.');
            }
            $oldUser = getUser($pdo, $userId);
            if (!$oldUser) {
                throw new Exception('User not found.');
            }

            if (isCmaAdmin($currentUserId)) {
                $cmaCustId = getCmaAdminCustomerId($currentUserId);
                $stmtCheckScope = $pdo->prepare("
                    SELECT 1 FROM user_organization_access 
                    WHERE user_id = :uid AND customer_id = :cid AND UPPER(status) = 'ACTIVE'
                ");
                $stmtCheckScope->execute([':uid' => $userId, ':cid' => $cmaCustId]);
                if (!$stmtCheckScope->fetch() && $userId !== $currentUserId) {
                    throw new Exception('Access denied: Cannot modify user outside your customer organization.');
                }
            }

            $currentStatus = strtoupper(trim($oldUser['status'] ?? 'ACTIVE'));
            $newStatus = ($currentStatus === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE';
            $isActive = ($newStatus === 'ACTIVE');

            $pdo->beginTransaction();
            $stmtUpd = $pdo->prepare("
                UPDATE users 
                SET status = :status, is_active = :is_active, updated_at = CURRENT_TIMESTAMP 
                WHERE user_id = :user_id
            ");
            $stmtUpd->execute([
                ':status' => $newStatus,
                ':is_active' => $isActive ? 1 : 0,
                ':user_id' => $userId
            ]);

            writeAuditLog($pdo, $currentUserId, $userId, 'UPDATE', $oldUser, array_merge($oldUser, ['status' => $newStatus]));
            $pdo->commit();

            jsonResponse(true, "User '{$oldUser['username']}' is now {$newStatus}.", ['user_id' => $userId, 'status' => $newStatus]);
            break;


        /* ======================================================
           CHANGE PASSWORD
           ====================================================== */

        case 'change_password':

            requireUserMasterPermission(
                'EDIT'
            );


            if (
                $_SERVER['REQUEST_METHOD']
                !== 'POST'
            ) {

                throw new Exception(
                    'Invalid request method.'
                );
            }


            $userId =
                (int)(
                    $_POST['user_id'] ?? 0
                );

            $newPassword =
                $_POST['new_password'] ?? '';

            $confirmPassword =
                $_POST['confirm_password']
                ?? '';


            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }


            if (!$isPlatformAdmin && !canAdminManageUser($currentUserId, $userId)) {

                throw new Exception(
                    'Access denied. You do not have permission to change this user\'s password.'
                );
            }


            if (
                $newPassword === '' ||
                strlen($newPassword) < 6
            ) {

                throw new Exception(
                    'New password must be at least 6 characters long.'
                );
            }


            if (
                $newPassword !==
                $confirmPassword
            ) {

                throw new Exception(
                    'Passwords do not match.'
                );
            }


            $user =
                getUser(
                    $pdo,
                    $userId
                );


            if (!$user) {

                throw new Exception(
                    'User not found.'
                );
            }


            $passwordHash =
                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );


            if ($passwordHash === false) {

                throw new Exception(
                    'Unable to securely update password.'
                );
            }


            $pdo->beginTransaction();


            /*
             * The actual users table contains:
             *
             * password_hash
             * failed_login_attempts
             * locked_until
             *
             * It does NOT contain password_changed_at.
             */

            $stmt = $pdo->prepare("
                UPDATE users

                SET
                    password_hash = :password_hash,

                    failed_login_attempts = 0,

                    locked_until = NULL,

                    updated_at =
                        CURRENT_TIMESTAMP

                WHERE user_id = :user_id
            ");

            $stmt->execute([

                ':password_hash' =>
                    $passwordHash,

                ':user_id' =>
                    $userId
            ]);


            writeAuditLog(
                $pdo,
                $currentUserId,
                $userId,
                'UPDATE',
                [
                    'action' =>
                        'PASSWORD_CHANGE'
                ],
                [
                    'action' =>
                        'PASSWORD_CHANGED'
                ]
            );


            $pdo->commit();


            jsonResponse(
                true,
                'Password changed successfully.'
            );

            break;


        /* ======================================================
           DELETE USER
           ====================================================== */

        case 'delete':

            requireUserMasterPermission(
                'DELETE'
            );


            if (
                $_SERVER['REQUEST_METHOD']
                !== 'POST'
            ) {

                throw new Exception(
                    'Invalid request method.'
                );
            }


            $userId =
                (int)(
                    $_POST['user_id'] ?? 0
                );


            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }


            if (!$isPlatformAdmin && !canAdminManageUser($currentUserId, $userId)) {

                throw new Exception(
                    'Access denied. You do not have permission to delete this user.'
                );
            }


            /* --------------------------------------------------
               PREVENT SELF DELETE
               -------------------------------------------------- */

            if (
                $userId ===
                $currentUserId
            ) {

                throw new Exception(
                    'You cannot delete your own logged-in account.'
                );
            }


            /* --------------------------------------------------
               USER
               -------------------------------------------------- */

            $user =
                getUser(
                    $pdo,
                    $userId
                );


            if (!$user) {

                throw new Exception(
                    'User not found.'
                );
            }


            $pdo->beginTransaction();


            /* --------------------------------------------------
               AUDIT BEFORE DELETE
               -------------------------------------------------- */

            writeAuditLog(
                $pdo,
                $currentUserId,
                $userId,
                'DELETE',
                [
                    'username' =>
                        $user['username'] ?? '',

                    'first_name' =>
                        $user['first_name'] ?? '',

                    'last_name' =>
                        $user['last_name'] ?? '',

                    'email' =>
                        $user['email'] ?? '',

                    'role_id' =>
                        $user['role_id'] ?? null,

                    'status' =>
                        $user['status'] ?? ''
                ],
                null
            );


            /*
             * user_roles will be deleted automatically
             * because of ON DELETE CASCADE.
             *
             * login_history will also be deleted automatically
             * because of ON DELETE CASCADE.
             *
             * user_organization_access will also be deleted
             * automatically because of ON DELETE CASCADE.
             */

            $stmt = $pdo->prepare("
                DELETE FROM users

                WHERE user_id = :user_id
            ");

            $stmt->execute([
                ':user_id' =>
                    $userId
            ]);


            if (
                $stmt->rowCount() === 0
            ) {

                throw new Exception(
                    'User could not be deleted.'
                );
            }


            $pdo->commit();


            jsonResponse(
                true,
                'User deleted successfully.'
            );

            break;


        /* ======================================================
           GET USER HIERARCHICAL ACCESS & AVAILABLE TREE
           ====================================================== */

        case 'get_user_access':

            $userId =
                (int)(
                    $_GET['user_id']
                    ?? $_POST['user_id']
                    ?? 0
                );

            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }

            if (!$isPlatformAdmin && !canAdminManageUser($currentUserId, $userId)) {

                throw new Exception(
                    'Access denied. You do not have permission to view organizational access for this user.'
                );
            }

            $user =
                getUser(
                    $pdo,
                    $userId
                );

            if (!$user) {

                throw new Exception(
                    'User not found.'
                );
            }

            $hasUoaPeriod = hasUoaPeriodColumn($pdo);
            $periodColSql = $hasUoaPeriod ? "uoa.period_id," : "NULL AS period_id,";

            $stmt = $pdo->prepare("
                SELECT
                    uoa.access_id,
                    uoa.user_id,
                    uoa.customer_id,
                    uoa.company_id,
                    uoa.division_id,
                    uoa.plant_id,
                    uoa.warehouse_id,
                    $periodColSql
                    uoa.valid_from,
                    uoa.valid_to,
                    uoa.status,
                    cust.customer_name,
                    cust.customer_code,
                    comp.company_name,
                    comp.company_code,
                    d.division_name,
                    d.division_code,
                    p.plant_name,
                    p.plant_code,
                    w.warehouse_name,
                    w.warehouse_code
                FROM user_organization_access uoa
                LEFT JOIN customers cust ON cust.customer_id = uoa.customer_id
                LEFT JOIN companies comp ON comp.company_id = uoa.company_id
                LEFT JOIN divisions d ON d.division_id = uoa.division_id
                LEFT JOIN plants p ON p.plant_id = uoa.plant_id
                LEFT JOIN warehouses w ON w.warehouse_id = uoa.warehouse_id
                WHERE uoa.user_id = :uid
                  AND UPPER(uoa.status) = 'ACTIVE'
                ORDER BY uoa.access_id ASC
            ");
            $stmt->execute([':uid' => $userId]);
            $directAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $effectiveScope = getUserEffectiveOrganizationScope($userId);
            $summary = getUserAccessSummary($userId);
            $availableTree = getAdminAccessibleHierarchy($pdo, $currentUserId);

            // Accessible periods for this user (respects explicit period_id grants)
            $accessiblePeriods = getUserAccessiblePeriods(null, $userId);

            jsonResponse(
                true,
                '',
                [
                    'user' => [
                        'user_id' => (int)$user['user_id'],
                        'username' => $user['username'] ?? '',
                        'full_name' => $user['full_name'] ?? '',
                        'role_name' => $user['role_name'] ?? ''
                    ],
                    'direct_assignments' => $directAssignments,
                    'assignments' => $directAssignments,
                    'effective_scope' => $effectiveScope,
                    'summary' => $summary,
                    'available_tree' => $availableTree,
                    'hierarchy' => $availableTree,
                    'accessible_periods' => $accessiblePeriods
                ]
            );

            break;


        /* ======================================================
           PREVIEW USER HIERARCHICAL ACCESS
           ====================================================== */

        case 'preview_user_access':

            $userId =
                (int)(
                    $_POST['user_id']
                    ?? $_GET['user_id']
                    ?? 0
                );

            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }

            if (!$isPlatformAdmin && !canAdminManageUser($currentUserId, $userId)) {

                throw new Exception(
                    'Access denied. You do not have permission to preview access for this user.'
                );
            }

            $rawAssignments = $_POST['assignments'] ?? '[]';
            if (is_string($rawAssignments)) {
                $rawAssignments = json_decode($rawAssignments, true);
            }
            if (!is_array($rawAssignments)) {
                $rawAssignments = [];
            }

            // Privilege escalation prevention check
            validateScopeAssignment($currentUserId, $rawAssignments);

            // Compute hypothetical direct and inherited scope
            $previewCompIds = [];
            $previewDivIds = [];
            $previewPlantIds = [];
            $previewWhIds = [];

            foreach ($rawAssignments as $a) {
                if (!empty($a['warehouse_id'])) {
                    $previewWhIds[] = (int)$a['warehouse_id'];
                } elseif (!empty($a['plant_id'])) {
                    $previewPlantIds[] = (int)$a['plant_id'];
                } elseif (!empty($a['division_id'])) {
                    $previewDivIds[] = (int)$a['division_id'];
                } elseif (!empty($a['company_id'])) {
                    $previewCompIds[] = (int)$a['company_id'];
                } elseif (!empty($a['customer_id'])) {
                    $cStmt = $pdo->prepare("SELECT company_id FROM companies WHERE customer_id = :cid AND UPPER(status) = 'ACTIVE'");
                    $cStmt->execute([':cid' => (int)$a['customer_id']]);
                    $previewCompIds = array_merge($previewCompIds, $cStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                }
            }

            if (!empty($previewCompIds)) {
                $inC = implode(',', array_unique(array_map('intval', $previewCompIds)));
                $divStmt = $pdo->query("SELECT division_id FROM divisions WHERE company_id IN ($inC) AND UPPER(status) = 'ACTIVE'");
                $previewDivIds = array_merge($previewDivIds, $divStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }
            if (!empty($previewDivIds)) {
                $inD = implode(',', array_unique(array_map('intval', $previewDivIds)));
                $pStmt = $pdo->query("SELECT plant_id FROM plants WHERE division_id IN ($inD) AND UPPER(status) = 'ACTIVE'");
                $previewPlantIds = array_merge($previewPlantIds, $pStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }
            if (!empty($previewPlantIds)) {
                $inP = implode(',', array_unique(array_map('intval', $previewPlantIds)));
                $wStmt = $pdo->query("SELECT warehouse_id FROM warehouses WHERE plant_id IN ($inP) AND UPPER(status) = 'ACTIVE'");
                $previewWhIds = array_merge($previewWhIds, $wStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }

            jsonResponse(
                true,
                '',
                [
                    'preview' => [
                        'company_count' => count(array_unique(array_map('intval', $previewCompIds))),
                        'division_count' => count(array_unique(array_map('intval', $previewDivIds))),
                        'plant_count' => count(array_unique(array_map('intval', $previewPlantIds))),
                        'warehouse_count' => count(array_unique(array_map('intval', $previewWhIds))),
                        'direct_count' => count($rawAssignments)
                    ]
                ]
            );

            break;


        /* ======================================================
           SAVE USER HIERARCHICAL ACCESS
           ====================================================== */

        case 'save_user_access':

            requireUserMasterPermission('EDIT');

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

                throw new Exception(
                    'Invalid request method.'
                );
            }

            $userId =
                (int)(
                    $_POST['user_id']
                    ?? 0
                );

            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }

            if (!$isPlatformAdmin && !canAdminManageUser($currentUserId, $userId)) {

                throw new Exception(
                    'Access denied. You do not have permission to manage organizational access for this user.'
                );
            }

            $rawAssignments = $_POST['assignments'] ?? '[]';
            if (is_string($rawAssignments)) {
                $rawAssignments = json_decode($rawAssignments, true);
            }
            if (!is_array($rawAssignments)) {
                $rawAssignments = [];
            }

            $result = saveUserOrganizationAccess($userId, $rawAssignments, $currentUserId);
            $summary = getUserAccessSummary($userId);
            $effectiveScope = getUserEffectiveOrganizationScope($userId);

            jsonResponse(
                true,
                'Organizational access updated successfully.',
                [
                    'user_id' => $userId,
                    'count' => $result['count'] ?? 0,
                    'summary' => $summary,
                    'effective_scope' => $effectiveScope
                ]
            );

            break;


        /* ======================================================
           INVALID ACTION
           ====================================================== */

        default:

            jsonResponse(
                false,
                'Invalid User Master action.',
                [],
                400
            );
    }


} catch (Throwable $e) {

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();
    }


    error_log(
        'User Master API Error: ' .
        $e->getMessage()
    );


    jsonResponse(
        false,
        $e->getMessage(),
        [],
        400
    );
}