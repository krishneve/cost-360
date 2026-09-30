<?php
/**
 * Cost360 - CMA Admin Management API
 * Phase 2 & 3: CMA Admin creation, listing, status management and customer binding.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

function cmaJsonResponse(bool $success, string $message = '', array $data = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    $response = ['success' => $success];
    if ($message !== '') {
        if ($success) {
            $response['message'] = $message;
        } else {
            $response['error'] = $message;
        }
    }
    echo json_encode(array_merge($response, $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    cmaJsonResponse(false, 'Authentication required.', [], 401);
}

$currentUser = getAuthUser();
$currentUserId = (int)($currentUser['user_id'] ?? 0);

if (!isPlatformAdmin($currentUserId)) {
    cmaJsonResponse(false, 'Unauthorized. Only Platform Administrators can manage CMA Admins.', [], 403);
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? 'list');

try {
    $pdo = getDbConnection();

    switch ($action) {

        /* ============================================================
           ACTION: LIST CMA ADMINS
           ============================================================ */
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $status = trim($_GET['status'] ?? '');
            $customerId = (int)($_GET['customer_id'] ?? 0);

            $sql = "
                SELECT 
                    u.user_id,
                    u.username,
                    u.email,
                    u.first_name,
                    u.last_name,
                    u.full_name,
                    u.phone,
                    u.status,
                    u.is_active,
                    u.created_at,
                    u.last_login_at,
                    r.role_id,
                    r.role_code,
                    r.role_name,
                    c.customer_id,
                    c.customer_code,
                    c.customer_name,
                    (SELECT plan_name FROM customer_subscriptions cs 
                     JOIN subscription_plans sp ON cs.plan_id = sp.plan_id 
                     WHERE cs.customer_id = c.customer_id AND UPPER(cs.status) = 'ACTIVE' 
                     LIMIT 1) AS subscription_plan
                FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.user_id AND ur.status = 'Active'
                INNER JOIN roles r ON r.role_id = ur.role_id AND r.role_code = 'CMA_ADMIN'
                LEFT JOIN LATERAL (
                    SELECT uoa.customer_id
                    FROM user_organization_access uoa
                    WHERE uoa.user_id = u.user_id AND UPPER(uoa.status) = 'ACTIVE' AND uoa.customer_id IS NOT NULL
                    ORDER BY uoa.access_id ASC
                    LIMIT 1
                ) cust_access ON TRUE
                LEFT JOIN customers c ON c.customer_id = cust_access.customer_id
                WHERE 1 = 1
            ";

            $params = [];

            if ($search !== '') {
                $sql .= " AND (u.username ILIKE :search OR u.email ILIKE :search OR u.first_name ILIKE :search OR u.last_name ILIKE :search OR c.customer_name ILIKE :search OR c.customer_code ILIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            if ($status !== '' && strtoupper($status) !== 'ALL') {
                $sql .= " AND UPPER(u.status) = :status";
                $params[':status'] = strtoupper($status);
            }

            if ($customerId > 0) {
                $sql .= " AND c.customer_id = :customer_id";
                $params[':customer_id'] = $customerId;
            }

            $sql .= " ORDER BY u.created_at DESC, u.user_id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // KPIs
            $kpiTotal = count($admins);
            $kpiActive = 0;
            $kpiInactive = 0;
            $uniqueCustomers = [];

            foreach ($admins as $a) {
                if (strtoupper($a['status'] ?? '') === 'ACTIVE') {
                    $kpiActive++;
                } else {
                    $kpiInactive++;
                }
                if (!empty($a['customer_id'])) {
                    $uniqueCustomers[$a['customer_id']] = true;
                }
            }

            cmaJsonResponse(true, '', [
                'admins' => $admins,
                'kpis' => [
                    'total' => $kpiTotal,
                    'active' => $kpiActive,
                    'inactive' => $kpiInactive,
                    'customers_covered' => count($uniqueCustomers)
                ]
            ]);
            break;

        /* ============================================================
           ACTION: GET CUSTOMERS DROPDOWN
           ============================================================ */
        case 'get_customers':
            $stmt = $pdo->prepare("
                SELECT customer_id, customer_code, customer_name, status
                FROM customers
                WHERE UPPER(status) = 'ACTIVE'
                ORDER BY customer_name ASC, customer_id ASC
            ");
            $stmt->execute();
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            cmaJsonResponse(true, '', ['customers' => $customers]);
            break;

        /* ============================================================
           ACTION: GET SINGLE CMA ADMIN
           ============================================================ */
        case 'get_admin':
            $userId = (int)($_GET['user_id'] ?? 0);
            if ($userId <= 0) {
                cmaJsonResponse(false, 'Invalid User ID.', [], 400);
            }

            $stmt = $pdo->prepare("
                SELECT 
                    u.user_id, u.username, u.email, u.first_name, u.last_name, u.phone, u.status, u.is_active,
                    c.customer_id, c.customer_code, c.customer_name
                FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.user_id AND ur.status = 'Active'
                INNER JOIN roles r ON r.role_id = ur.role_id AND r.role_code = 'CMA_ADMIN'
                LEFT JOIN LATERAL (
                    SELECT uoa.customer_id
                    FROM user_organization_access uoa
                    WHERE uoa.user_id = u.user_id AND UPPER(uoa.status) = 'ACTIVE' AND uoa.customer_id IS NOT NULL
                    ORDER BY uoa.access_id ASC
                    LIMIT 1
                ) cust_access ON TRUE
                LEFT JOIN customers c ON c.customer_id = cust_access.customer_id
                WHERE u.user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin) {
                cmaJsonResponse(false, 'CMA Admin not found.', [], 404);
            }

            cmaJsonResponse(true, '', ['admin' => $admin]);
            break;

        /* ============================================================
           ACTION: CREATE CMA ADMIN (PHASE 2 & 3)
           ============================================================ */
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                cmaJsonResponse(false, 'Method not allowed.', [], 405);
            }

            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $phone = trim($_POST['phone'] ?? '');
            $status = strtoupper(trim($_POST['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';

            // Validation
            if ($firstName === '') {
                cmaJsonResponse(false, 'First name is required.', [], 400);
            }
            if ($username === '') {
                cmaJsonResponse(false, 'Username is required.', [], 400);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                cmaJsonResponse(false, 'A valid email address is required.', [], 400);
            }
            if ($password === '' || strlen($password) < 6) {
                cmaJsonResponse(false, 'Password must be at least 6 characters long.', [], 400);
            }
            if ($customerId <= 0) {
                cmaJsonResponse(false, 'Please select a valid customer organization.', [], 400);
            }

            // Verify Customer exists
            $stmtCust = $pdo->prepare("SELECT customer_id, customer_name, customer_code, status FROM customers WHERE customer_id = :cid");
            $stmtCust->execute([':cid' => $customerId]);
            $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);
            if (!$cust) {
                cmaJsonResponse(false, 'Selected customer does not exist.', [], 400);
            }

            // Check Duplicate Username or Email
            $stmtDup = $pdo->prepare("SELECT user_id, username, email FROM users WHERE LOWER(username) = LOWER(:uname) OR LOWER(email) = LOWER(:email) LIMIT 1");
            $stmtDup->execute([':uname' => $username, ':email' => $email]);
            $dup = $stmtDup->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                if (strcasecmp($dup['username'] ?? '', $username) === 0) {
                    cmaJsonResponse(false, 'Username is already taken by another account.', [], 400);
                }
                cmaJsonResponse(false, 'Email address is already registered in the system.', [], 400);
            }

            // Fetch CMA_ADMIN role_id
            $stmtRole = $pdo->prepare("SELECT role_id, category_id FROM roles WHERE role_code = 'CMA_ADMIN' AND status = 'Active' LIMIT 1");
            $stmtRole->execute();
            $cmaRole = $stmtRole->fetch(PDO::FETCH_ASSOC);
            if (!$cmaRole) {
                cmaJsonResponse(false, 'CMA_ADMIN role not found or inactive.', [], 500);
            }
            $cmaRoleId = (int)$cmaRole['role_id'];
            $cmaCategoryId = (int)$cmaRole['category_id'];

            $pdo->beginTransaction();

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $fullName = trim($firstName . ' ' . $lastName);
            $isActive = ($status === 'ACTIVE');

            // 1. Insert User
            $stmtUser = $pdo->prepare("
                INSERT INTO users (
                    category_id, full_name, first_name, last_name, username, email, phone,
                    password_hash, is_active, status, is_email_verified, created_by, created_at, updated_at
                ) VALUES (
                    :cat_id, :full_name, :fname, :lname, :uname, :email, :phone,
                    :phash, :is_active, :status, TRUE, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) RETURNING user_id
            ");
            $stmtUser->execute([
                ':cat_id'     => $cmaCategoryId,
                ':full_name'  => $fullName,
                ':fname'      => $firstName,
                ':lname'      => $lastName,
                ':uname'      => $username,
                ':email'      => $email,
                ':phone'      => $phone ?: null,
                ':phash'      => $passwordHash,
                ':is_active'  => $isActive ? 1 : 0,
                ':status'     => $status,
                ':created_by' => $currentUserId
            ]);
            $newUserId = (int)$stmtUser->fetchColumn();

            // 2. Assign CMA_ADMIN Role in user_roles
            $stmtUr = $pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by, status)
                VALUES (:uid, :rid, CURRENT_TIMESTAMP, :assigned_by, 'Active')
            ");
            $stmtUr->execute([
                ':uid'         => $newUserId,
                ':rid'         => $cmaRoleId,
                ':assigned_by' => $currentUserId
            ]);

            // 3. Assign Customer Scope in user_organization_access
            $stmtScope = $pdo->prepare("
                INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status)
                VALUES (:uid, :cid, NULL, NULL, NULL, NULL, CURRENT_DATE, :status)
            ");
            $stmtScope->execute([
                ':uid'    => $newUserId,
                ':cid'    => $customerId,
                ':status' => $isActive ? 'Active' : 'Inactive'
            ]);

            // 4. Audit Log
            $stmtAudit = $pdo->prepare("
                INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES (:uid, 'cma_admins', :eid, 'CMA_ADMIN_CREATED', NULL, :new_val, CURRENT_TIMESTAMP, :ip)
            ");
            $stmtAudit->execute([
                ':uid'     => $currentUserId,
                ':eid'     => $newUserId,
                ':new_val' => json_encode([
                    'user_id'       => $newUserId,
                    'username'      => $username,
                    'email'         => $email,
                    'full_name'     => $fullName,
                    'customer_id'   => $customerId,
                    'customer_name' => $cust['customer_name'],
                    'role_code'     => 'CMA_ADMIN',
                    'status'        => $status
                ], JSON_UNESCAPED_UNICODE),
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $pdo->commit();

            cmaJsonResponse(true, "CMA Admin '{$fullName}' created successfully and linked to customer '{$cust['customer_name']}'.", [
                'user_id' => $newUserId
            ]);
            break;

        /* ============================================================
           ACTION: UPDATE CMA ADMIN
           ============================================================ */
        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                cmaJsonResponse(false, 'Method not allowed.', [], 405);
            }

            $userId = (int)($_POST['user_id'] ?? 0);
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $phone = trim($_POST['phone'] ?? '');
            $status = strtoupper(trim($_POST['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';
            $password = $_POST['password'] ?? '';

            if ($userId <= 0) {
                cmaJsonResponse(false, 'Invalid User ID.', [], 400);
            }
            if ($firstName === '') {
                cmaJsonResponse(false, 'First name is required.', [], 400);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                cmaJsonResponse(false, 'A valid email address is required.', [], 400);
            }
            if ($customerId <= 0) {
                cmaJsonResponse(false, 'Please select a valid customer organization.', [], 400);
            }

            // Verify Customer exists
            $stmtCust = $pdo->prepare("SELECT customer_id, customer_name, customer_code FROM customers WHERE customer_id = :cid");
            $stmtCust->execute([':cid' => $customerId]);
            $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);
            if (!$cust) {
                cmaJsonResponse(false, 'Selected customer does not exist.', [], 400);
            }

            // Check Duplicate Email with other users
            $stmtDup = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(:email) AND user_id != :uid LIMIT 1");
            $stmtDup->execute([':email' => $email, ':uid' => $userId]);
            if ($stmtDup->fetch()) {
                cmaJsonResponse(false, 'Email address is already in use by another account.', [], 400);
            }

            $pdo->beginTransaction();

            $fullName = trim($firstName . ' ' . $lastName);
            $isActive = ($status === 'ACTIVE');

            // Fetch old record for audit
            $stmtOld = $pdo->prepare("SELECT username, email, full_name, status, is_active FROM users WHERE user_id = :uid");
            $stmtOld->execute([':uid' => $userId]);
            $oldUser = $stmtOld->fetch(PDO::FETCH_ASSOC);

            // Update Users table
            if (!empty($password)) {
                if (strlen($password) < 6) {
                    throw new Exception('New password must be at least 6 characters long.');
                }
                $phash = password_hash($password, PASSWORD_DEFAULT);
                $stmtUpdate = $pdo->prepare("
                    UPDATE users 
                    SET first_name = :fname, last_name = :lname, full_name = :full_name, email = :email,
                        phone = :phone, password_hash = :phash, status = :status, is_active = :is_active, updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = :uid
                ");
                $stmtUpdate->execute([
                    ':fname'     => $firstName,
                    ':lname'     => $lastName,
                    ':full_name' => $fullName,
                    ':email'     => $email,
                    ':phone'     => $phone ?: null,
                    ':phash'     => $phash,
                    ':status'    => $status,
                    ':is_active' => $isActive ? 1 : 0,
                    ':uid'       => $userId
                ]);
            } else {
                $stmtUpdate = $pdo->prepare("
                    UPDATE users 
                    SET first_name = :fname, last_name = :lname, full_name = :full_name, email = :email,
                        phone = :phone, status = :status, is_active = :is_active, updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = :uid
                ");
                $stmtUpdate->execute([
                    ':fname'     => $firstName,
                    ':lname'     => $lastName,
                    ':full_name' => $fullName,
                    ':email'     => $email,
                    ':phone'     => $phone ?: null,
                    ':status'    => $status,
                    ':is_active' => $isActive ? 1 : 0,
                    ':uid'       => $userId
                ]);
            }

            // Update Customer Scope in user_organization_access
            $stmtChkScope = $pdo->prepare("SELECT access_id FROM user_organization_access WHERE user_id = :uid AND customer_id IS NOT NULL LIMIT 1");
            $stmtChkScope->execute([':uid' => $userId]);
            $existingAccessId = $stmtChkScope->fetchColumn();

            if ($existingAccessId) {
                $stmtUpScope = $pdo->prepare("
                    UPDATE user_organization_access 
                    SET customer_id = :cid, status = :status
                    WHERE access_id = :aid
                ");
                $stmtUpScope->execute([
                    ':cid'    => $customerId,
                    ':status' => $isActive ? 'Active' : 'Inactive',
                    ':aid'    => $existingAccessId
                ]);
            } else {
                $stmtInScope = $pdo->prepare("
                    INSERT INTO user_organization_access (user_id, customer_id, valid_from, status)
                    VALUES (:uid, :cid, CURRENT_DATE, :status)
                ");
                $stmtInScope->execute([
                    ':uid'    => $userId,
                    ':cid'    => $customerId,
                    ':status' => $isActive ? 'Active' : 'Inactive'
                ]);
            }

            // Audit
            $stmtAudit = $pdo->prepare("
                INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES (:uid, 'cma_admins', :eid, 'CMA_ADMIN_UPDATED', :old_val, :new_val, CURRENT_TIMESTAMP, :ip)
            ");
            $stmtAudit->execute([
                ':uid'     => $currentUserId,
                ':eid'     => $userId,
                ':old_val' => json_encode($oldUser, JSON_UNESCAPED_UNICODE),
                ':new_val' => json_encode([
                    'user_id'       => $userId,
                    'full_name'     => $fullName,
                    'email'         => $email,
                    'customer_id'   => $customerId,
                    'customer_name' => $cust['customer_name'],
                    'status'        => $status
                ], JSON_UNESCAPED_UNICODE),
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $pdo->commit();

            cmaJsonResponse(true, "CMA Admin '{$fullName}' updated successfully.");
            break;

        /* ============================================================
           ACTION: TOGGLE STATUS (ACTIVATE / DEACTIVATE)
           ============================================================ */
        case 'toggle_status':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                cmaJsonResponse(false, 'Method not allowed.', [], 405);
            }

            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatus = strtoupper(trim($_POST['status'] ?? ''));

            if ($userId <= 0 || !in_array($newStatus, ['ACTIVE', 'INACTIVE'], true)) {
                cmaJsonResponse(false, 'Invalid request parameters.', [], 400);
            }

            $isActive = ($newStatus === 'ACTIVE');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = :status, is_active = :is_active, updated_at = CURRENT_TIMESTAMP 
                WHERE user_id = :uid
            ");
            $stmt->execute([
                ':status'    => $newStatus,
                ':is_active' => $isActive ? 1 : 0,
                ':uid'       => $userId
            ]);

            // Also update user_organization_access status
            $stmtScope = $pdo->prepare("
                UPDATE user_organization_access 
                SET status = :status 
                WHERE user_id = :uid
            ");
            $stmtScope->execute([
                ':status' => $isActive ? 'Active' : 'Inactive',
                ':uid'    => $userId
            ]);

            // Audit
            $auditAction = $isActive ? 'USER_ACTIVATED' : 'USER_DEACTIVATED';
            $stmtAudit = $pdo->prepare("
                INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES (:uid, 'cma_admins', :eid, :action, NULL, :new_val, CURRENT_TIMESTAMP, :ip)
            ");
            $stmtAudit->execute([
                ':uid'     => $currentUserId,
                ':eid'     => $userId,
                ':action'  => $auditAction,
                ':new_val' => json_encode(['status' => $newStatus], JSON_UNESCAPED_UNICODE),
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $pdo->commit();

            cmaJsonResponse(true, "CMA Admin status changed to '{$newStatus}'.", ['status' => $newStatus]);
            break;

        default:
            cmaJsonResponse(false, "Unknown action '{$action}'.", [], 400);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cmaJsonResponse(false, $e->getMessage(), [], 500);
}
