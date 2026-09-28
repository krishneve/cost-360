<?php
/**
 * Cost360 - Customer Management & Tenant Provisioning API
 * Handles complete Tenant Lifecycle, Organization Hierarchy, Customer Admin Onboarding,
 * Scoped User Access Management, and Audit Logging.
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

function sendApiResponse(bool $success, string $message = '', array $data = [], int $httpCode = 200): void {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($httpCode);
    $response = ['success' => $success];
    if ($success) {
        if ($message !== '') {
            $response['message'] = $message;
        }
    } else {
        $response['error'] = $message;
    }
    $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Authenticate session
if (!isLoggedIn()) {
    sendApiResponse(false, 'Your session has expired. Please log in again.', [], 401);
}

$authUser = getAuthUser();
if (!$authUser) {
    sendApiResponse(false, 'Unable to identify authenticated user.', [], 401);
}

$currentRole = getCurrentUserRole();
$userRoleId = (int)($authUser['role_id'] ?? 0);
$userRoleCode = strtoupper($authUser['role_code'] ?? ($currentRole['role_code'] ?? ''));
$authUserId = (int)$authUser['user_id'];

// 2. Check RBAC menu permissions
$canAccessCustomerMgmt = userHasMenuAccess('CUSTOMER_MGMT') || 
                         userHasMenuAccess('CUSTOMER_MASTER') || 
                         $userRoleCode === 'PLATFORM_ADMIN' || 
                         $userRoleCode === 'CUSTOMER_ADMIN' ||
                         $userRoleCode === 'CUSTOMER_MANAGER' ||
                         $userRoleCode === 'CMA_ADMIN';

if (!$canAccessCustomerMgmt) {
    sendApiResponse(false, 'Access Denied: You do not have permission to access Customer Management.', [], 403);
}

$pdo = getDbConnection();

// 3. Resolve user's tenant scope
$userScopedCustomerId = null;
if ($userRoleCode !== 'PLATFORM_ADMIN' && $userRoleCode !== 'CMA_ADMIN') {
    $scopeStmt = $pdo->prepare("
        SELECT customer_id 
        FROM user_organization_access 
        WHERE user_id = :uid AND status = 'Active' AND customer_id IS NOT NULL 
        ORDER BY access_id ASC LIMIT 1
    ");
    $scopeStmt->execute([':uid' => $authUserId]);
    $userScopedCustomerId = $scopeStmt->fetchColumn();
    if (!$userScopedCustomerId && $userRoleCode === 'CUSTOMER_ADMIN') {
        sendApiResponse(false, 'Access Denied: Customer Administrator is not bound to a valid customer tenant.', [], 403);
    }
}

function enforceCustomerScope(?int $targetCustomerId, ?int $userScopedCustomerId, string $userRoleCode): void {
    if ($userRoleCode === 'PLATFORM_ADMIN' || $userRoleCode === 'CMA_ADMIN') {
        return; // Platform-level access
    }
    if ($targetCustomerId === null || (int)$targetCustomerId !== (int)$userScopedCustomerId) {
        sendApiResponse(false, 'Unauthorized: You do not have permission to access or modify this customer tenant.', [], 403);
    }
}

function logAudit(PDO $pdo, int $userId, string $entityName, int $entityId, string $action, ?string $oldVal = null, ?string $newVal = null): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
            VALUES (:uid, :entity, :eid, :action, :old, :new, CURRENT_TIMESTAMP, :ip)
        ");
        $stmt->execute([
            ':uid'    => $userId,
            ':entity' => $entityName,
            ':eid'    => $entityId,
            ':action' => $action,
            ':old'    => $oldVal,
            ':new'    => $newVal,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

$action = 'list';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'list';
} elseif (isset($_GET['action'])) {
    $action = $_GET['action'];
}

try {
    switch ($action) {

        /* ============================================================
           ACTION: LIST CUSTOMERS
           ============================================================ */
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $statusFilter = trim($_GET['status'] ?? 'ALL');

            $sql = "
                SELECT 
                    c.customer_id,
                    c.customer_code,
                    c.customer_name,
                    c.address,
                    c.contact_person,
                    c.contact_email,
                    c.contact_phone,
                    c.status,
                    TO_CHAR(c.created_at, 'DD Mon YYYY, HH24:MI') AS created_at_formatted,
                    COALESCE(comp_cnt.total_companies, 0) AS total_companies,
                    COALESCE(plt_cnt.total_plants, 0) AS total_plants,
                    COALESCE(wh_cnt.total_warehouses, 0) AS total_warehouses,
                    COALESCE(usr_cnt.total_users, 0) AS total_users,
                    admin_u.full_name AS admin_name,
                    admin_u.email AS admin_email
                FROM customers c
                
                -- Aggregated companies count
                LEFT JOIN (
                    SELECT customer_id, COUNT(*) AS total_companies 
                    FROM companies 
                    GROUP BY customer_id
                ) comp_cnt ON comp_cnt.customer_id = c.customer_id

                -- Aggregated plants count
                LEFT JOIN (
                    SELECT comp.customer_id, COUNT(p.plant_id) AS total_plants
                    FROM plants p
                    JOIN divisions d ON p.division_id = d.division_id
                    JOIN companies comp ON d.company_id = comp.company_id
                    GROUP BY comp.customer_id
                ) plt_cnt ON plt_cnt.customer_id = c.customer_id

                -- Aggregated warehouses count
                LEFT JOIN (
                    SELECT comp.customer_id, COUNT(w.warehouse_id) AS total_warehouses
                    FROM warehouses w
                    JOIN plants p ON w.plant_id = p.plant_id
                    JOIN divisions d ON p.division_id = d.division_id
                    JOIN companies comp ON d.company_id = comp.company_id
                    GROUP BY comp.customer_id
                ) wh_cnt ON wh_cnt.customer_id = c.customer_id

                -- Aggregated scoped users count
                LEFT JOIN (
                    SELECT customer_id, COUNT(DISTINCT user_id) AS total_users
                    FROM user_organization_access
                    WHERE status = 'Active'
                    GROUP BY customer_id
                ) usr_cnt ON usr_cnt.customer_id = c.customer_id

                -- Linked Customer Admin info
                LEFT JOIN LATERAL (
                    SELECT u.full_name, u.email
                    FROM user_organization_access uoa
                    JOIN users u ON u.user_id = uoa.user_id
                    JOIN user_roles ur ON ur.user_id = u.user_id
                    JOIN roles r ON r.role_id = ur.role_id
                    WHERE uoa.customer_id = c.customer_id
                      AND r.role_code = 'CUSTOMER_ADMIN'
                      AND u.status = 'ACTIVE'
                    ORDER BY uoa.access_id ASC
                    LIMIT 1
                ) admin_u ON TRUE

                WHERE 1 = 1
            ";

            $params = [];

            // Apply tenant isolation scope
            if ($userScopedCustomerId !== null) {
                $sql .= " AND c.customer_id = :scoped_cust_id";
                $params[':scoped_cust_id'] = $userScopedCustomerId;
            }

            // Apply search
            if ($search !== '') {
                $sql .= " AND (c.customer_code ILIKE :search OR c.customer_name ILIKE :search OR c.contact_person ILIKE :search OR c.contact_email ILIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            // Apply status filter
            if ($statusFilter !== '' && $statusFilter !== 'ALL') {
                $sql .= " AND UPPER(c.status) = :status";
                $params[':status'] = strtoupper($statusFilter);
            }

            $sql .= " ORDER BY c.customer_id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate overall KPI summary metrics
            $totalActive = 0;
            $totalCompAll = 0;
            $totalPltAll = 0;
            $totalUsersAll = 0;

            foreach ($customers as $row) {
                if (strcasecmp($row['status'], 'Active') === 0) {
                    $totalActive++;
                }
                $totalCompAll += (int)$row['total_companies'];
                $totalPltAll += (int)$row['total_plants'];
                $totalUsersAll += (int)$row['total_users'];
            }

            sendApiResponse(true, '', [
                'customers' => $customers,
                'stats' => [
                    'total_customers' => count($customers),
                    'active_customers' => $totalActive,
                    'total_companies' => $totalCompAll,
                    'total_plants' => $totalPltAll,
                    'total_users' => $totalUsersAll
                ]
            ]);
            break;

        /* ============================================================
           ACTION: GET CUSTOMER DETAILS (WITH ORG TREE & USERS)
           ============================================================ */
        case 'get_customer':
            $customerId = (int)($_GET['customer_id'] ?? 0);
            if ($customerId <= 0) {
                sendApiResponse(false, 'Valid Customer ID is required.');
            }

            enforceCustomerScope($customerId, $userScopedCustomerId, $userRoleCode);

            $stmt = $pdo->prepare("
                SELECT 
                    customer_id, customer_code, customer_name, address,
                    contact_person, contact_email, contact_phone, status,
                    TO_CHAR(created_at, 'DD Mon YYYY, HH24:MI') AS created_at_formatted
                FROM customers 
                WHERE customer_id = :id
            ");
            $stmt->execute([':id' => $customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                sendApiResponse(false, 'Customer record not found.');
            }

            // 1. Fetch Customer Admin
            $adminStmt = $pdo->prepare("
                SELECT u.user_id, u.username, u.email, u.full_name, u.phone, u.status,
                       TO_CHAR(u.created_at, 'DD Mon YYYY') AS created_at_formatted,
                       TO_CHAR(u.last_login_at, 'DD Mon YYYY, HH24:MI') AS last_login_formatted
                FROM user_organization_access uoa
                JOIN users u ON u.user_id = uoa.user_id
                JOIN user_roles ur ON ur.user_id = u.user_id
                JOIN roles r ON r.role_id = ur.role_id
                WHERE uoa.customer_id = :cid
                  AND r.role_code = 'CUSTOMER_ADMIN'
                ORDER BY uoa.access_id ASC
                LIMIT 1
            ");
            $adminStmt->execute([':cid' => $customerId]);
            $adminUser = $adminStmt->fetch(PDO::FETCH_ASSOC);

            // 2. Build full Organization Hierarchy Tree
            $compStmt = $pdo->prepare("SELECT * FROM companies WHERE customer_id = :cid ORDER BY company_name ASC");
            $compStmt->execute([':cid' => $customerId]);
            $companies = $compStmt->fetchAll(PDO::FETCH_ASSOC);

            $orgTree = [];
            $totalDivs = 0;
            $totalPlts = 0;
            $totalWhs = 0;

            foreach ($companies as $comp) {
                $compNode = [
                    'company_id'   => $comp['company_id'],
                    'company_code' => $comp['company_code'],
                    'company_name' => $comp['company_name'],
                    'address'      => $comp['address'],
                    'status'       => $comp['status'],
                    'divisions'    => []
                ];

                $divStmt = $pdo->prepare("SELECT * FROM divisions WHERE company_id = :comp_id ORDER BY division_name ASC");
                $divStmt->execute([':comp_id' => $comp['company_id']]);
                $divisions = $divStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($divisions as $div) {
                    $totalDivs++;
                    $divNode = [
                        'division_id'   => $div['division_id'],
                        'division_code' => $div['division_code'],
                        'division_name' => $div['division_name'],
                        'status'        => $div['status'],
                        'plants'        => []
                    ];

                    $pltStmt = $pdo->prepare("SELECT * FROM plants WHERE division_id = :div_id ORDER BY plant_name ASC");
                    $pltStmt->execute([':div_id' => $div['division_id']]);
                    $plants = $pltStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($plants as $plt) {
                        $totalPlts++;
                        $pltNode = [
                            'plant_id'   => $plt['plant_id'],
                            'plant_code' => $plt['plant_code'],
                            'plant_name' => $plt['plant_name'],
                            'location'   => $plt['location'],
                            'status'     => $plt['status'],
                            'warehouses' => []
                        ];

                        $whStmt = $pdo->prepare("SELECT * FROM warehouses WHERE plant_id = :plt_id ORDER BY warehouse_name ASC");
                        $whStmt->execute([':plt_id' => $plt['plant_id']]);
                        $warehouses = $whStmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($warehouses as $wh) {
                            $totalWhs++;
                            $pltNode['warehouses'][] = [
                                'warehouse_id'   => $wh['warehouse_id'],
                                'warehouse_code' => $wh['warehouse_code'],
                                'warehouse_name' => $wh['warehouse_name'],
                                'status'         => $wh['status']
                            ];
                        }

                        $divNode['plants'][] = $pltNode;
                    }

                    $compNode['divisions'][] = $divNode;
                }

                $orgTree[] = $compNode;
            }

            // 3. Fetch Scoped Users
            $usersStmt = $pdo->prepare("
                SELECT DISTINCT
                    u.user_id, u.username, u.email, u.full_name, u.status AS user_status,
                    r.role_id, r.role_code, r.role_name,
                    uoa.access_id, uoa.company_id, uoa.division_id, uoa.plant_id, uoa.warehouse_id,
                    c.company_name, d.division_name, p.plant_name, w.warehouse_name
                FROM user_organization_access uoa
                JOIN users u ON u.user_id = uoa.user_id
                LEFT JOIN user_roles ur ON ur.user_id = u.user_id
                LEFT JOIN roles r ON r.role_id = ur.role_id
                LEFT JOIN companies c ON c.company_id = uoa.company_id
                LEFT JOIN divisions d ON d.division_id = uoa.division_id
                LEFT JOIN plants p ON p.plant_id = uoa.plant_id
                LEFT JOIN warehouses w ON w.warehouse_id = uoa.warehouse_id
                WHERE uoa.customer_id = :cid
                ORDER BY u.user_id ASC, uoa.access_id ASC
            ");
            $usersStmt->execute([':cid' => $customerId]);
            $scopedUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Fetch Audit History for this customer
            $auditStmt = $pdo->prepare("
                SELECT 
                    a.audit_id, a.action, a.entity_name, a.entity_id,
                    a.old_value, a.new_value, a.ip_address,
                    TO_CHAR(a.timestamp, 'DD Mon YYYY, HH24:MI') AS timestamp_formatted,
                    u.full_name AS actor_name, u.email AS actor_email
                FROM audit_log a
                LEFT JOIN users u ON u.user_id = a.user_id
                WHERE (a.entity_name = 'customers' AND a.entity_id = :cid)
                   OR (a.entity_name IN ('companies', 'divisions', 'plants', 'warehouses', 'user_organization_access') AND a.new_value ILIKE :cid_str)
                ORDER BY a.audit_id DESC
                LIMIT 30
            ");
            $auditStmt->execute([
                ':cid'     => $customerId,
                ':cid_str' => '%"customer_id":' . $customerId . '%'
            ]);
            $auditLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

            sendApiResponse(true, '', [
                'customer'    => $customer,
                'admin_user'  => $adminUser,
                'org_tree'    => $orgTree,
                'users'       => $scopedUsers,
                'audit_logs'  => $auditLogs,
                'metrics'     => [
                    'companies'  => count($orgTree),
                    'divisions'  => $totalDivs,
                    'plants'     => $totalPlts,
                    'warehouses' => $totalWhs,
                    'users'      => count($scopedUsers)
                ]
            ]);
            break;

        /* ============================================================
           ACTION: CREATE CUSTOMER (ATOMIC MULTI-ENTITY PROVISIONING)
           ============================================================ */
        case 'create_customer':
            if ($userRoleCode !== 'PLATFORM_ADMIN') {
                sendApiResponse(false, 'Access Denied: Only Platform Administrators can onboard new customer tenants.', [], 403);
            }

            // Step 1: Basic Information
            $customerCode  = strtoupper(trim($_POST['customer_code'] ?? ''));
            $customerName  = trim($_POST['customer_name'] ?? '');
            $address       = trim($_POST['address'] ?? '');
            $contactPerson = trim($_POST['contact_person'] ?? '');
            $contactEmail  = strtolower(trim($_POST['contact_email'] ?? ''));
            $contactPhone  = trim($_POST['contact_phone'] ?? '');

            if (empty($customerCode) || empty($customerName)) {
                sendApiResponse(false, 'Customer Code and Customer Name are mandatory.');
            }

            if (!preg_match('/^[A-Z0-9_\-]+$/', $customerCode)) {
                sendApiResponse(false, 'Customer Code must contain only alphanumeric characters, dashes, or underscores.');
            }

            // Check unique customer code
            $chkCode = $pdo->prepare("SELECT customer_id FROM customers WHERE UPPER(customer_code) = :code LIMIT 1");
            $chkCode->execute([':code' => $customerCode]);
            if ($chkCode->fetch()) {
                sendApiResponse(false, "Customer Code '{$customerCode}' is already registered.");
            }

            // Step 4: Customer Administrator Details
            $adminFullName = trim($_POST['admin_full_name'] ?? '');
            $adminEmail    = strtolower(trim($_POST['admin_email'] ?? ''));
            $adminUsername = trim($_POST['admin_username'] ?? '');
            $adminPhone    = trim($_POST['admin_phone'] ?? '');
            $adminPassword = $_POST['admin_password'] ?? '';

            if (empty($adminFullName) || empty($adminEmail) || empty($adminPassword)) {
                sendApiResponse(false, 'Customer Admin Full Name, Email, and Password are required.');
            }

            if (strlen($adminPassword) < 6) {
                sendApiResponse(false, 'Password must be at least 6 characters long.');
            }

            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                sendApiResponse(false, 'Invalid Customer Admin email format.');
            }

            if (empty($adminUsername)) {
                $adminUsername = $adminEmail;
            }

            // Check if admin email or username is already taken
            $chkUser = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = :email OR LOWER(username) = :uname LIMIT 1");
            $chkUser->execute([':email' => $adminEmail, ':uname' => strtolower($adminUsername)]);
            if ($chkUser->fetch()) {
                sendApiResponse(false, "User with email '{$adminEmail}' or username '{$adminUsername}' already exists.");
            }

            // Step 5: Initial Organization Setup (Optional in wizard)
            $companyCode = strtoupper(trim($_POST['company_code'] ?? ''));
            $companyName = trim($_POST['company_name'] ?? '');
            $divisionCode = strtoupper(trim($_POST['division_code'] ?? ''));
            $divisionName = trim($_POST['division_name'] ?? '');
            $plantCode    = strtoupper(trim($_POST['plant_code'] ?? ''));
            $plantName    = trim($_POST['plant_name'] ?? '');
            $warehouseCode = strtoupper(trim($_POST['warehouse_code'] ?? ''));
            $warehouseName = trim($_POST['warehouse_name'] ?? '');

            // Begin Atomic Database Transaction
            $pdo->beginTransaction();

            try {
                // 1. Insert Customer Record
                $custStmt = $pdo->prepare("
                    INSERT INTO customers (customer_code, customer_name, address, contact_person, contact_email, contact_phone, status, created_at)
                    VALUES (:code, :name, :addr, :cperson, :cemail, :cphone, 'Active', CURRENT_TIMESTAMP)
                    RETURNING customer_id
                ");
                $custStmt->execute([
                    ':code'    => $customerCode,
                    ':name'    => $customerName,
                    ':addr'    => $address ?: null,
                    ':cperson' => $contactPerson ?: null,
                    ':cemail'  => $contactEmail ?: null,
                    ':cphone'  => $contactPhone ?: null
                ]);
                $newCustomerId = (int)$custStmt->fetchColumn();

                // 2. Resolve Customer Admin Category & Role dynamically
                $catStmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_code = 'CUST_ADMIN' LIMIT 1");
                $catStmt->execute();
                $custAdminCategoryId = (int)$catStmt->fetchColumn();
                if (!$custAdminCategoryId) {
                    $custAdminCategoryId = 4; // Fallback to category 4
                }

                $roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_code = 'CUSTOMER_ADMIN' LIMIT 1");
                $roleStmt->execute();
                $custAdminRoleId = (int)$roleStmt->fetchColumn();
                if (!$custAdminRoleId) {
                    $custAdminRoleId = 4; // Fallback to role 4
                }

                // 3. Create Customer Admin User
                $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
                $firstName = explode(' ', $adminFullName)[0];
                $lastName = substr(strstr($adminFullName, ' '), 1) ?: '';

                $userStmt = $pdo->prepare("
                    INSERT INTO users (category_id, full_name, first_name, last_name, username, email, phone, password_hash, is_active, status, is_email_verified, created_by, created_at)
                    VALUES (:cat_id, :full_name, :fname, :lname, :uname, :email, :phone, :phash, TRUE, 'ACTIVE', TRUE, :created_by, CURRENT_TIMESTAMP)
                    RETURNING user_id
                ");
                $userStmt->execute([
                    ':cat_id'     => $custAdminCategoryId,
                    ':full_name'  => $adminFullName,
                    ':fname'      => $firstName,
                    ':lname'      => $lastName,
                    ':uname'      => $adminUsername,
                    ':email'      => $adminEmail,
                    ':phone'      => $adminPhone ?: null,
                    ':phash'      => $passwordHash,
                    ':created_by' => $authUserId
                ]);
                $newAdminUserId = (int)$userStmt->fetchColumn();

                // 4. Assign CUSTOMER_ADMIN Role to User
                $urStmt = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by, status)
                    VALUES (:uid, :rid, CURRENT_TIMESTAMP, :assigned_by, 'Active')
                ");
                $urStmt->execute([
                    ':uid'         => $newAdminUserId,
                    ':rid'         => $custAdminRoleId,
                    ':assigned_by' => $authUserId
                ]);

                // 5. Assign Full Customer Scope in user_organization_access
                $scopeStmt = $pdo->prepare("
                    INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status)
                    VALUES (:uid, :cid, NULL, NULL, NULL, NULL, CURRENT_DATE, 'Active')
                ");
                $scopeStmt->execute([
                    ':uid' => $newAdminUserId,
                    ':cid' => $newCustomerId
                ]);

                // 6. Optional Initial Organization Setup
                $createdCompanyId = null;
                $createdDivId = null;
                $createdPltId = null;
                $createdWhId = null;

                if (!empty($companyCode) && !empty($companyName)) {
                    $compStmt = $pdo->prepare("
                        INSERT INTO companies (customer_id, company_code, company_name, address, status)
                        VALUES (:cid, :code, :name, :addr, 'Active')
                        RETURNING company_id
                    ");
                    $compStmt->execute([
                        ':cid'  => $newCustomerId,
                        ':code' => $companyCode,
                        ':name' => $companyName,
                        ':addr' => $address ?: null
                    ]);
                    $createdCompanyId = (int)$compStmt->fetchColumn();

                    if (!empty($divisionCode) && !empty($divisionName)) {
                        $divStmt = $pdo->prepare("
                            INSERT INTO divisions (company_id, division_code, division_name, status)
                            VALUES (:comp_id, :code, :name, 'Active')
                            RETURNING division_id
                        ");
                        $divStmt->execute([
                            ':comp_id' => $createdCompanyId,
                            ':code'    => $divisionCode,
                            ':name'    => $divisionName
                        ]);
                        $createdDivId = (int)$divStmt->fetchColumn();

                        if (!empty($plantCode) && !empty($plantName)) {
                            $pltStmt = $pdo->prepare("
                                INSERT INTO plants (division_id, plant_code, plant_name, location, status)
                                VALUES (:div_id, :code, :name, :loc, 'Active')
                                RETURNING plant_id
                            ");
                            $pltStmt->execute([
                                ':div_id' => $createdDivId,
                                ':code'   => $plantCode,
                                ':name'   => $plantName,
                                ':loc'    => $address ?: null
                            ]);
                            $createdPltId = (int)$pltStmt->fetchColumn();

                            if (!empty($warehouseCode) && !empty($warehouseName)) {
                                $whStmt = $pdo->prepare("
                                    INSERT INTO warehouses (plant_id, warehouse_code, warehouse_name, status)
                                    VALUES (:plt_id, :code, :name, 'Active')
                                    RETURNING warehouse_id
                                ");
                                $whStmt->execute([
                                    ':plt_id' => $createdPltId,
                                    ':code'   => $warehouseCode,
                                    ':name'   => $warehouseName
                                ]);
                                $createdWhId = (int)$whStmt->fetchColumn();
                            }
                        }
                    }
                }

                // 7. Audit Log
                logAudit(
                    $pdo,
                    $authUserId,
                    'customers',
                    $newCustomerId,
                    'INSERT',
                    null,
                    json_encode([
                        'customer_id'   => $newCustomerId,
                        'customer_code' => $customerCode,
                        'customer_name' => $customerName,
                        'admin_user_id' => $newAdminUserId,
                        'admin_email'   => $adminEmail,
                        'company_id'    => $createdCompanyId
                    ])
                );

                $pdo->commit();

                sendApiResponse(true, 'Customer tenant and Customer Administrator created successfully.', [
                    'customer_id'   => $newCustomerId,
                    'customer_code' => $customerCode,
                    'customer_name' => $customerName,
                    'admin_user_id' => $newAdminUserId,
                    'admin_email'   => $adminEmail,
                    'status'        => 'Active'
                ]);

            } catch (Exception $txErr) {
                $pdo->rollBack();
                sendApiResponse(false, 'Transaction Error: ' . $txErr->getMessage(), [], 500);
            }
            break;

        /* ============================================================
           ACTION: UPDATE CUSTOMER MASTER
           ============================================================ */
        case 'update_customer':
            $customerId    = (int)($_POST['customer_id'] ?? 0);
            $customerName  = trim($_POST['customer_name'] ?? '');
            $address       = trim($_POST['address'] ?? '');
            $contactPerson = trim($_POST['contact_person'] ?? '');
            $contactEmail  = strtolower(trim($_POST['contact_email'] ?? ''));
            $contactPhone  = trim($_POST['contact_phone'] ?? '');
            $status        = trim($_POST['status'] ?? 'Active');

            if ($customerId <= 0 || empty($customerName)) {
                sendApiResponse(false, 'Customer ID and Customer Name are required.');
            }

            enforceCustomerScope($customerId, $userScopedCustomerId, $userRoleCode);

            // Fetch old record for audit
            $oldStmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = :id");
            $oldStmt->execute([':id' => $customerId]);
            $oldCust = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldCust) {
                sendApiResponse(false, 'Customer record not found.');
            }

            $updStmt = $pdo->prepare("
                UPDATE customers SET
                    customer_name  = :name,
                    address        = :addr,
                    contact_person = :cperson,
                    contact_email  = :cemail,
                    contact_phone  = :cphone,
                    status         = :status
                WHERE customer_id  = :id
            ");
            $updStmt->execute([
                ':name'    => $customerName,
                ':addr'    => $address ?: null,
                ':cperson' => $contactPerson ?: null,
                ':cemail'  => $contactEmail ?: null,
                ':cphone'  => $contactPhone ?: null,
                ':status'  => $status,
                ':id'      => $customerId
            ]);

            logAudit(
                $pdo,
                $authUserId,
                'customers',
                $customerId,
                'UPDATE',
                json_encode($oldCust),
                json_encode([
                    'customer_name'  => $customerName,
                    'address'        => $address,
                    'contact_person' => $contactPerson,
                    'contact_email'  => $contactEmail,
                    'contact_phone'  => $contactPhone,
                    'status'         => $status
                ])
            );

            sendApiResponse(true, 'Customer details updated successfully.');
            break;

        /* ============================================================
           ACTION: TOGGLE CUSTOMER STATUS (DEACTIVATE / ACTIVATE)
           ============================================================ */
        case 'toggle_status':
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $newStatus  = trim($_POST['status'] ?? '');

            if ($customerId <= 0 || !in_array($newStatus, ['Active', 'Inactive'])) {
                sendApiResponse(false, 'Valid Customer ID and target Status (Active/Inactive) are required.');
            }

            enforceCustomerScope($customerId, $userScopedCustomerId, $userRoleCode);

            $stmt = $pdo->prepare("UPDATE customers SET status = :status WHERE customer_id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $customerId]);

            logAudit($pdo, $authUserId, 'customers', $customerId, 'UPDATE', null, json_encode(['status' => $newStatus]));

            sendApiResponse(true, "Customer status updated to {$newStatus}.");
            break;

        /* ============================================================
           ACTION: SAVE ORGANIZATION NODE (COMPANY / DIVISION / PLANT / WAREHOUSE)
           ============================================================ */
        case 'save_org_node':
            $nodeType   = strtolower(trim($_POST['node_type'] ?? ''));
            $nodeId     = (int)($_POST['node_id'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? 0);

            if ($customerId <= 0) {
                sendApiResponse(false, 'Valid Customer ID is required.');
            }

            enforceCustomerScope($customerId, $userScopedCustomerId, $userRoleCode);

            switch ($nodeType) {
                case 'company':
                    $code = strtoupper(trim($_POST['company_code'] ?? ''));
                    $name = trim($_POST['company_name'] ?? '');
                    $addr = trim($_POST['address'] ?? '');

                    if (empty($code) || empty($name)) {
                        sendApiResponse(false, 'Company Code and Company Name are required.');
                    }

                    if ($nodeId > 0) {
                        $stmt = $pdo->prepare("
                            UPDATE companies SET company_name = :name, address = :addr WHERE company_id = :id AND customer_id = :cid
                        ");
                        $stmt->execute([':name' => $name, ':addr' => $addr ?: null, ':id' => $nodeId, ':cid' => $customerId]);
                        logAudit($pdo, $authUserId, 'companies', $nodeId, 'UPDATE', null, json_encode(['customer_id' => $customerId, 'name' => $name]));
                        sendApiResponse(true, 'Company updated successfully.');
                    } else {
                        $chk = $pdo->prepare("SELECT company_id FROM companies WHERE customer_id = :cid AND UPPER(company_code) = :code");
                        $chk->execute([':cid' => $customerId, ':code' => $code]);
                        if ($chk->fetch()) {
                            sendApiResponse(false, "Company Code '{$code}' already exists for this customer.");
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO companies (customer_id, company_code, company_name, address, status)
                            VALUES (:cid, :code, :name, :addr, 'Active') RETURNING company_id
                        ");
                        $stmt->execute([':cid' => $customerId, ':code' => $code, ':name' => $name, ':addr' => $addr ?: null]);
                        $newId = $stmt->fetchColumn();
                        logAudit($pdo, $authUserId, 'companies', $newId, 'INSERT', null, json_encode(['customer_id' => $customerId, 'code' => $code, 'name' => $name]));
                        sendApiResponse(true, 'Company created successfully.');
                    }
                    break;

                case 'division':
                    $companyId = (int)($_POST['company_id'] ?? 0);
                    $code = strtoupper(trim($_POST['division_code'] ?? ''));
                    $name = trim($_POST['division_name'] ?? '');

                    if ($companyId <= 0 || empty($code) || empty($name)) {
                        sendApiResponse(false, 'Parent Company, Division Code, and Division Name are required.');
                    }

                    // Verify company belongs to customer
                    $chkComp = $pdo->prepare("SELECT company_id FROM companies WHERE company_id = :id AND customer_id = :cid");
                    $chkComp->execute([':id' => $companyId, ':cid' => $customerId]);
                    if (!$chkComp->fetch()) {
                        sendApiResponse(false, 'Invalid parent company for this customer.');
                    }

                    if ($nodeId > 0) {
                        $stmt = $pdo->prepare("UPDATE divisions SET division_name = :name WHERE division_id = :id AND company_id = :comp_id");
                        $stmt->execute([':name' => $name, ':id' => $nodeId, ':comp_id' => $companyId]);
                        logAudit($pdo, $authUserId, 'divisions', $nodeId, 'UPDATE', null, json_encode(['customer_id' => $customerId, 'name' => $name]));
                        sendApiResponse(true, 'Division updated successfully.');
                    } else {
                        $chk = $pdo->prepare("SELECT division_id FROM divisions WHERE company_id = :cid AND UPPER(division_code) = :code");
                        $chk->execute([':cid' => $companyId, ':code' => $code]);
                        if ($chk->fetch()) {
                            sendApiResponse(false, "Division Code '{$code}' already exists under this company.");
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO divisions (company_id, division_code, division_name, status)
                            VALUES (:comp_id, :code, :name, 'Active') RETURNING division_id
                        ");
                        $stmt->execute([':comp_id' => $companyId, ':code' => $code, ':name' => $name]);
                        $newId = $stmt->fetchColumn();
                        logAudit($pdo, $authUserId, 'divisions', $newId, 'INSERT', null, json_encode(['customer_id' => $customerId, 'company_id' => $companyId, 'code' => $code]));
                        sendApiResponse(true, 'Division created successfully.');
                    }
                    break;

                case 'plant':
                    $divisionId = (int)($_POST['division_id'] ?? 0);
                    $code = strtoupper(trim($_POST['plant_code'] ?? ''));
                    $name = trim($_POST['plant_name'] ?? '');
                    $location = trim($_POST['location'] ?? '');

                    if ($divisionId <= 0 || empty($code) || empty($name)) {
                        sendApiResponse(false, 'Parent Division, Plant Code, and Plant Name are required.');
                    }

                    if ($nodeId > 0) {
                        $stmt = $pdo->prepare("UPDATE plants SET plant_name = :name, location = :loc WHERE plant_id = :id AND division_id = :div_id");
                        $stmt->execute([':name' => $name, ':loc' => $location ?: null, ':id' => $nodeId, ':div_id' => $divisionId]);
                        logAudit($pdo, $authUserId, 'plants', $nodeId, 'UPDATE', null, json_encode(['customer_id' => $customerId, 'name' => $name]));
                        sendApiResponse(true, 'Plant updated successfully.');
                    } else {
                        $chk = $pdo->prepare("SELECT plant_id FROM plants WHERE division_id = :div_id AND UPPER(plant_code) = :code");
                        $chk->execute([':div_id' => $divisionId, ':code' => $code]);
                        if ($chk->fetch()) {
                            sendApiResponse(false, "Plant Code '{$code}' already exists under this division.");
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO plants (division_id, plant_code, plant_name, location, status)
                            VALUES (:div_id, :code, :name, :loc, 'Active') RETURNING plant_id
                        ");
                        $stmt->execute([':div_id' => $divisionId, ':code' => $code, ':name' => $name, ':loc' => $location ?: null]);
                        $newId = $stmt->fetchColumn();
                        logAudit($pdo, $authUserId, 'plants', $newId, 'INSERT', null, json_encode(['customer_id' => $customerId, 'division_id' => $divisionId, 'code' => $code]));
                        sendApiResponse(true, 'Plant created successfully.');
                    }
                    break;

                case 'warehouse':
                    $plantId = (int)($_POST['plant_id'] ?? 0);
                    $code = strtoupper(trim($_POST['warehouse_code'] ?? ''));
                    $name = trim($_POST['warehouse_name'] ?? '');

                    if ($plantId <= 0 || empty($code) || empty($name)) {
                        sendApiResponse(false, 'Parent Plant, Warehouse Code, and Warehouse Name are required.');
                    }

                    if ($nodeId > 0) {
                        $stmt = $pdo->prepare("UPDATE warehouses SET warehouse_name = :name WHERE warehouse_id = :id AND plant_id = :plt_id");
                        $stmt->execute([':name' => $name, ':id' => $nodeId, ':plt_id' => $plantId]);
                        logAudit($pdo, $authUserId, 'warehouses', $nodeId, 'UPDATE', null, json_encode(['customer_id' => $customerId, 'name' => $name]));
                        sendApiResponse(true, 'Warehouse updated successfully.');
                    } else {
                        $chk = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE plant_id = :plt_id AND UPPER(warehouse_code) = :code");
                        $chk->execute([':plt_id' => $plantId, ':code' => $code]);
                        if ($chk->fetch()) {
                            sendApiResponse(false, "Warehouse Code '{$code}' already exists under this plant.");
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO warehouses (plant_id, warehouse_code, warehouse_name, status)
                            VALUES (:plt_id, :code, :name, 'Active') RETURNING warehouse_id
                        ");
                        $stmt->execute([':plt_id' => $plantId, ':code' => $code, ':name' => $name]);
                        $newId = $stmt->fetchColumn();
                        logAudit($pdo, $authUserId, 'warehouses', $newId, 'INSERT', null, json_encode(['customer_id' => $customerId, 'plant_id' => $plantId, 'code' => $code]));
                        sendApiResponse(true, 'Warehouse created successfully.');
                    }
                    break;

                default:
                    sendApiResponse(false, 'Invalid organization node type.');
            }
            break;

        /* ============================================================
           ACTION: DELETE / DEACTIVATE ORGANIZATION NODE
           ============================================================ */
        case 'delete_org_node':
            $nodeType   = strtolower(trim($_POST['node_type'] ?? ''));
            $nodeId     = (int)($_POST['node_id'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? 0);

            if ($nodeId <= 0 || $customerId <= 0) {
                sendApiResponse(false, 'Valid Node ID and Customer ID are required.');
            }

            enforceCustomerScope($customerId, $userScopedCustomerId, $userRoleCode);

            switch ($nodeType) {
                case 'company':
                    $stmt = $pdo->prepare("DELETE FROM companies WHERE company_id = :id AND customer_id = :cid");
                    $stmt->execute([':id' => $nodeId, ':cid' => $customerId]);
                    break;
                case 'division':
                    $stmt = $pdo->prepare("DELETE FROM divisions WHERE division_id = :id");
                    $stmt->execute([':id' => $nodeId]);
                    break;
                case 'plant':
                    $stmt = $pdo->prepare("DELETE FROM plants WHERE plant_id = :id");
                    $stmt->execute([':id' => $nodeId]);
                    break;
                case 'warehouse':
                    $stmt = $pdo->prepare("DELETE FROM warehouses WHERE warehouse_id = :id");
                    $stmt->execute([':id' => $nodeId]);
                    break;
                default:
                    sendApiResponse(false, 'Invalid node type.');
            }

            logAudit($pdo, $authUserId, $nodeType . 's', $nodeId, 'DELETE', null, json_encode(['customer_id' => $customerId]));
            sendApiResponse(true, ucfirst($nodeType) . ' removed successfully.');
            break;

        /* ============================================================
           ACTION: CREATE TENANT USER (CUSTOMER ADMIN SCOPED)
           ============================================================ */
        case 'create_customer_user':
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $fullName   = trim($_POST['full_name'] ?? '');
            $email      = strtolower(trim($_POST['email'] ?? ''));
            $username   = trim($_POST['username'] ?? '');
            $phone      = trim($_POST['phone'] ?? '');
            $password   = $_POST['password'] ?? '';
            $roleId     = (int)($_POST['role_id'] ?? 0);

            // Organization scope selection
            $companyId   = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
            $divisionId  = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
            $plantId     = !empty($_POST['plant_id']) ? (int)$_POST['plant_id'] : null;
            $warehouseId = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;

            if ($customerId <= 0 || empty($fullName) || empty($email) || empty($password) || $roleId <= 0) {
                sendApiResponse(false, 'Full Name, Email, Password, and Role are mandatory.');
            }

            enforceCustomerScope($customerId, $userScopedCustomerId, $userRoleCode);

            if (strlen($password) < 6) {
                sendApiResponse(false, 'Password must be at least 6 characters.');
            }

            if (empty($username)) {
                $username = $email;
            }

            // Check duplicate user
            $chk = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = :email OR LOWER(username) = :uname LIMIT 1");
            $chk->execute([':email' => $email, ':uname' => strtolower($username)]);
            if ($chk->fetch()) {
                sendApiResponse(false, 'User with this email or username already exists.');
            }

            // Customer Admin category (CUST_ADMIN or CUST_MGMT)
            $catId = 4; // CUST_ADMIN default

            $pdo->beginTransaction();
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $firstName = explode(' ', $fullName)[0];
                $lastName = substr(strstr($fullName, ' '), 1) ?: '';

                $uStmt = $pdo->prepare("
                    INSERT INTO users (category_id, full_name, first_name, last_name, username, email, phone, password_hash, is_active, status, is_email_verified, created_by, created_at)
                    VALUES (:cat_id, :full_name, :fname, :lname, :uname, :email, :phone, :phash, TRUE, 'ACTIVE', TRUE, :created_by, CURRENT_TIMESTAMP)
                    RETURNING user_id
                ");
                $uStmt->execute([
                    ':cat_id'     => $catId,
                    ':full_name'  => $fullName,
                    ':fname'      => $firstName,
                    ':lname'      => $lastName,
                    ':uname'      => $username,
                    ':email'      => $email,
                    ':phone'      => $phone ?: null,
                    ':phash'      => $passwordHash,
                    ':created_by' => $authUserId
                ]);
                $newUserId = (int)$uStmt->fetchColumn();

                // Role link
                $urStmt = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by, status)
                    VALUES (:uid, :rid, CURRENT_TIMESTAMP, :assigned_by, 'Active')
                ");
                $urStmt->execute([
                    ':uid'         => $newUserId,
                    ':rid'         => $roleId,
                    ':assigned_by' => $authUserId
                ]);

                // Organization Access Scope
                $scopeStmt = $pdo->prepare("
                    INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status)
                    VALUES (:uid, :cid, :comp_id, :div_id, :plt_id, :wh_id, CURRENT_DATE, 'Active')
                ");
                $scopeStmt->execute([
                    ':uid'     => $newUserId,
                    ':cid'     => $customerId,
                    ':comp_id' => $companyId,
                    ':div_id'  => $divisionId,
                    ':plt_id'  => $plantId,
                    ':wh_id'   => $warehouseId
                ]);

                logAudit($pdo, $authUserId, 'users', $newUserId, 'INSERT', null, json_encode([
                    'customer_id' => $customerId,
                    'email'       => $email,
                    'role_id'     => $roleId,
                    'company_id'  => $companyId,
                    'plant_id'    => $plantId
                ]));

                $pdo->commit();
                sendApiResponse(true, 'User created and organization scope assigned successfully.');

            } catch (Exception $e) {
                $pdo->rollBack();
                sendApiResponse(false, 'Failed to create customer user: ' . $e->getMessage(), [], 500);
            }
            break;

        /* ============================================================
           ACTION: SAVE USER ORGANIZATION ACCESS SCOPE
           ============================================================ */
        case 'save_user_scope':
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $customerId   = (int)($_POST['customer_id'] ?? 0);
            $companyId    = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
            $divisionId   = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
            $plantId      = !empty($_POST['plant_id']) ? (int)$_POST['plant_id'] : null;
            $warehouseId  = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;

            if ($targetUserId <= 0 || $customerId <= 0) {
                sendApiResponse(false, 'Valid User ID and Customer ID are required.');
            }

            enforceCustomerScope($customerId, $userScopedCustomerId, $userRoleCode);

            // Server-side cascading hierarchy validation
            if ($warehouseId && !$plantId) {
                sendApiResponse(false, 'A Warehouse cannot be selected without its parent Plant.');
            }
            if ($plantId && !$divisionId) {
                sendApiResponse(false, 'A Plant cannot be selected without its parent Division.');
            }
            if ($divisionId && !$companyId) {
                sendApiResponse(false, 'A Division cannot be selected without its parent Company.');
            }

            // Update or insert scope
            $chkScope = $pdo->prepare("SELECT access_id FROM user_organization_access WHERE user_id = :uid AND customer_id = :cid LIMIT 1");
            $chkScope->execute([':uid' => $targetUserId, ':cid' => $customerId]);
            $existingAccessId = $chkScope->fetchColumn();

            if ($existingAccessId) {
                $stmt = $pdo->prepare("
                    UPDATE user_organization_access SET
                        company_id   = :comp_id,
                        division_id  = :div_id,
                        plant_id     = :plt_id,
                        warehouse_id = :wh_id,
                        status       = 'Active'
                    WHERE access_id  = :aid
                ");
                $stmt->execute([
                    ':comp_id' => $companyId,
                    ':div_id'  => $divisionId,
                    ':plt_id'  => $plantId,
                    ':wh_id'   => $warehouseId,
                    ':aid'     => $existingAccessId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO user_organization_access (user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status)
                    VALUES (:uid, :cid, :comp_id, :div_id, :plt_id, :wh_id, CURRENT_DATE, 'Active')
                ");
                $stmt->execute([
                    ':uid'     => $targetUserId,
                    ':cid'     => $customerId,
                    ':comp_id' => $companyId,
                    ':div_id'  => $divisionId,
                    ':plt_id'  => $plantId,
                    ':wh_id'   => $warehouseId
                ]);
            }

            logAudit($pdo, $authUserId, 'user_organization_access', $targetUserId, 'UPDATE', null, json_encode([
                'customer_id'  => $customerId,
                'company_id'   => $companyId,
                'division_id'  => $divisionId,
                'plant_id'     => $plantId,
                'warehouse_id' => $warehouseId
            ]));

            sendApiResponse(true, 'User organization scope updated successfully.');
            break;

        /* ============================================================
           ACTION: GET AVAILABLE ROLES
           ============================================================ */
        case 'roles':
            $stmt = $pdo->query("SELECT role_id, role_code, role_name, description FROM roles WHERE status = 'Active' ORDER BY role_id ASC");
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendApiResponse(true, '', ['roles' => $roles]);
            break;

        default:
            sendApiResponse(false, "Unknown API action: '{$action}'.");
    }

} catch (Exception $e) {
    error_log("Customer Management API Error: " . $e->getMessage());
    sendApiResponse(false, 'Database Server Error: ' . $e->getMessage(), [], 500);
}
