<?php
/**
 * Cost360 - Customer Management & Tenant Onboarding API Handler
 * Handles Customer CRUD, Tenant Provisioning, Customer Admin Creation, Organization Scope & Audit Logging.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$currentUser = getAuthUser();
$currentRole = getCurrentUserRole();
$isPlatform = isPlatformAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    $pdo = getDbConnection();

    /* ============================================================
       ACTION: LIST CUSTOMERS
       ============================================================ */
    if ($action === 'list') {
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');

        $sql = "
            SELECT 
                c.customer_id,
                c.customer_code,
                c.customer_name,
                c.contact_person,
                c.contact_email,
                c.contact_phone,
                c.address,
                c.status,
                c.created_at,
                (SELECT COUNT(*) FROM companies comp WHERE comp.customer_id = c.customer_id AND comp.status = 'Active') AS company_count,
                (SELECT COUNT(DISTINCT uoa.user_id) FROM user_organization_access uoa WHERE uoa.customer_id = c.customer_id AND uoa.status = 'Active') AS user_count
            FROM customers c
            WHERE 1=1
        ";

        $params = [];

        if ($search !== '') {
            $sql .= " AND (c.customer_code ILIKE :search OR c.customer_name ILIKE :search OR c.contact_person ILIKE :search OR c.contact_email ILIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL' && $statusFilter !== 'All Status') {
            $sql .= " AND UPPER(c.status) = :status";
            $params[':status'] = strtoupper($statusFilter);
        }

        $sql .= " ORDER BY c.customer_id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate KPI totals
        $totalCustomers = count($customers);
        $activeCustomers = 0;
        $inactiveCustomers = 0;
        $totalCompanies = 0;

        foreach ($customers as $c) {
            if (strtoupper($c['status']) === 'ACTIVE') {
                $activeCustomers++;
            } else {
                $inactiveCustomers++;
            }
            $totalCompanies += (int)$c['company_count'];
        }

        echo json_encode([
            'success' => true,
            'customers' => $customers,
            'kpis' => [
                'total_customers' => $totalCustomers,
                'active_customers' => $activeCustomers,
                'inactive_customers' => $inactiveCustomers,
                'total_companies' => $totalCompanies
            ]
        ]);
        exit;
    }

    /* ============================================================
       ACTION: GET CUSTOMER DETAILS
       ============================================================ */
    elseif ($action === 'get_details') {
        $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
        if (!$customerId) {
            throw new Exception("Invalid Customer ID.");
        }

        // Fetch Customer Record
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = :id");
        $stmt->execute([':id' => $customerId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            throw new Exception("Customer record not found.");
        }

        // Fetch Customer Administrator
        $adminStmt = $pdo->prepare("
            SELECT u.user_id, u.full_name, u.email, u.phone, u.status, u.created_at
            FROM users u
            JOIN user_organization_access uoa ON u.user_id = uoa.user_id
            JOIN user_roles ur ON u.user_id = ur.user_id
            JOIN roles r ON ur.role_id = r.role_id
            WHERE uoa.customer_id = :cid
              AND (r.role_code = 'CUSTOMER_ADMIN' OR u.category_id = 4)
            LIMIT 1
        ");
        $adminStmt->execute([':cid' => $customerId]);
        $customerAdmin = $adminStmt->fetch(PDO::FETCH_ASSOC);

        // Fetch Companies
        $compStmt = $pdo->prepare("SELECT * FROM companies WHERE customer_id = :cid ORDER BY company_id ASC");
        $compStmt->execute([':cid' => $customerId]);
        $companies = $compStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Divisions
        $divStmt = $pdo->prepare("
            SELECT d.*, c.company_name 
            FROM divisions d
            JOIN companies c ON d.company_id = c.company_id
            WHERE c.customer_id = :cid
            ORDER BY d.division_id ASC
        ");
        $divStmt->execute([':cid' => $customerId]);
        $divisions = $divStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Plants
        $plantStmt = $pdo->prepare("
            SELECT p.*, d.division_name, c.company_name
            FROM plants p
            JOIN divisions d ON p.division_id = d.division_id
            JOIN companies c ON d.company_id = c.company_id
            WHERE c.customer_id = :cid
            ORDER BY p.plant_id ASC
        ");
        $plantStmt->execute([':cid' => $customerId]);
        $plants = $plantStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Warehouses
        $whStmt = $pdo->prepare("
            SELECT w.*, p.plant_name, c.company_name
            FROM warehouses w
            JOIN plants p ON w.plant_id = p.plant_id
            JOIN divisions d ON p.division_id = d.division_id
            JOIN companies c ON d.company_id = c.company_id
            WHERE c.customer_id = :cid
            ORDER BY w.warehouse_id ASC
        ");
        $whStmt->execute([':cid' => $customerId]);
        $warehouses = $whStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Customer Users & Access Scope
        $usersStmt = $pdo->prepare("
            SELECT DISTINCT
                u.user_id,
                u.full_name,
                u.email,
                u.phone,
                u.status,
                r.role_name,
                r.role_code,
                comp.company_name,
                div.division_name,
                pl.plant_name,
                wh.warehouse_name
            FROM users u
            JOIN user_organization_access uoa ON u.user_id = uoa.user_id
            LEFT JOIN user_roles ur ON u.user_id = ur.user_id AND ur.status = 'Active'
            LEFT JOIN roles r ON ur.role_id = r.role_id
            LEFT JOIN companies comp ON uoa.company_id = comp.company_id
            LEFT JOIN divisions div ON uoa.division_id = div.division_id
            LEFT JOIN plants pl ON uoa.plant_id = pl.plant_id
            LEFT JOIN warehouses wh ON uoa.warehouse_id = wh.warehouse_id
            WHERE uoa.customer_id = :cid
            ORDER BY u.user_id ASC
        ");
        $usersStmt->execute([':cid' => $customerId]);
        $customerUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Audit Logs
        $auditStmt = $pdo->prepare("
            SELECT a.*, u.full_name AS operator_name
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE a.entity_name = 'customers' AND a.entity_id = :cid
            ORDER BY a.audit_id DESC
            LIMIT 20
        ");
        $auditStmt->execute([':cid' => $customerId]);
        $auditLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'customer' => $customer,
            'admin' => $customerAdmin,
            'companies' => $companies,
            'divisions' => $divisions,
            'plants' => $plants,
            'warehouses' => $warehouses,
            'users' => $customerUsers,
            'audit_logs' => $auditLogs,
            'kpis' => [
                'company_count' => count($companies),
                'division_count' => count($divisions),
                'plant_count' => count($plants),
                'warehouse_count' => count($warehouses),
                'user_count' => count($customerUsers)
            ]
        ]);
        exit;
    }

    /* ============================================================
       ACTION: CREATE CUSTOMER (WIZARD SUBMISSION)
       ============================================================ */
    elseif ($action === 'create_customer') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
            throw new Exception("Invalid HTTP request method.");
        }

        // STEP 1: Basic Information
        $customerCode = strtoupper(trim($_POST['customer_code'] ?? ''));
        $customerName = trim($_POST['customer_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        // STEP 4: Customer Administrator
        $adminFullName = trim($_POST['admin_full_name'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPhone = trim($_POST['admin_phone'] ?? '');
        $adminPassword = $_POST['admin_password'] ?? '';

        // Validation
        if (empty($customerCode) || empty($customerName)) {
            throw new Exception("Customer Code and Customer Name are required.");
        }
        if (empty($contactPerson) || empty($contactEmail)) {
            throw new Exception("Primary Contact Person and Email are required.");
        }
        if (empty($adminFullName) || empty($adminEmail) || empty($adminPassword)) {
            throw new Exception("Customer Administrator Name, Email, and Password are required.");
        }

        // Check if customer_code already exists
        $checkCode = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_code = :code");
        $checkCode->execute([':code' => $customerCode]);
        if ($checkCode->fetch()) {
            throw new Exception("Customer Code '{$customerCode}' already exists.");
        }

        // Check if admin email already exists in users
        $checkEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = :email");
        $checkEmail->execute([':email' => $adminEmail]);
        if ($checkEmail->fetch()) {
            throw new Exception("Admin Email '{$adminEmail}' is already registered with another user account.");
        }

        // Find Customer Administrator Role (role_id = 4 or role_code = 'CUSTOMER_ADMIN')
        $roleStmt = $pdo->query("SELECT role_id FROM roles WHERE role_code = 'CUSTOMER_ADMIN' OR category_id = 4 ORDER BY role_id ASC LIMIT 1");
        $customerAdminRoleId = $roleStmt->fetchColumn() ?: 4;

        // Find Customer Admin Category (category_id = 4 or category_code = 'CUST_ADMIN')
        $catStmt = $pdo->query("SELECT category_id FROM categories WHERE category_code = 'CUST_ADMIN' ORDER BY category_id ASC LIMIT 1");
        $custAdminCategoryId = $catStmt->fetchColumn() ?: 4;

        // BEGIN ATOMIC DB TRANSACTION
        $pdo->beginTransaction();

        // 1. Insert Customer Record
        $insCust = $pdo->prepare("
            INSERT INTO customers (customer_code, customer_name, address, contact_person, contact_email, contact_phone, status, created_at)
            VALUES (:code, :name, :address, :contact_person, :contact_email, :contact_phone, :status, CURRENT_TIMESTAMP)
            RETURNING customer_id
        ");
        $insCust->execute([
            ':code' => $customerCode,
            ':name' => $customerName,
            ':address' => $address,
            ':contact_person' => $contactPerson,
            ':contact_email' => $contactEmail,
            ':contact_phone' => $contactPhone,
            ':status' => $status
        ]);
        $newCustomerId = $insCust->fetchColumn();

        // 2. Insert Initial Organization Hierarchy if provided
        $companyName = trim($_POST['company_name'] ?? '');
        $companyCode = strtoupper(trim($_POST['company_code'] ?? ''));
        $newCompanyId = null;

        if (!empty($companyName) && !empty($companyCode)) {
            $insComp = $pdo->prepare("
                INSERT INTO companies (customer_id, company_code, company_name, address, status)
                VALUES (:cid, :code, :name, :address, 'Active')
                RETURNING company_id
            ");
            $insComp->execute([
                ':cid' => $newCustomerId,
                ':code' => $companyCode,
                ':name' => $companyName,
                ':address' => $address
            ]);
            $newCompanyId = $insComp->fetchColumn();

            // Initial Division if provided
            $divisionName = trim($_POST['division_name'] ?? '');
            $divisionCode = strtoupper(trim($_POST['division_code'] ?? ''));
            if (!empty($divisionName) && !empty($divisionCode)) {
                $insDiv = $pdo->prepare("
                    INSERT INTO divisions (company_id, division_code, division_name, status)
                    VALUES (:comp_id, :code, :name, 'Active')
                    RETURNING division_id
                ");
                $insDiv->execute([
                    ':comp_id' => $newCompanyId,
                    ':code' => $divisionCode,
                    ':name' => $divisionName
                ]);
                $newDivisionId = $insDiv->fetchColumn();

                // Initial Plant if provided
                $plantName = trim($_POST['plant_name'] ?? '');
                $plantCode = strtoupper(trim($_POST['plant_code'] ?? ''));
                if (!empty($plantName) && !empty($plantCode)) {
                    $insPlant = $pdo->prepare("
                        INSERT INTO plants (division_id, plant_code, plant_name, location, status)
                        VALUES (:div_id, :code, :name, :loc, 'Active')
                        RETURNING plant_id
                    ");
                    $insPlant->execute([
                        ':div_id' => $newDivisionId,
                        ':code' => $plantCode,
                        ':name' => $plantName,
                        ':loc' => $address
                    ]);
                    $newPlantId = $insPlant->fetchColumn();

                    // Initial Warehouse if provided
                    $whName = trim($_POST['warehouse_name'] ?? '');
                    $whCode = strtoupper(trim($_POST['warehouse_code'] ?? ''));
                    if (!empty($whName) && !empty($whCode)) {
                        $insWh = $pdo->prepare("
                            INSERT INTO warehouses (plant_id, warehouse_code, warehouse_name, status)
                            VALUES (:plant_id, :code, :name, 'Active')
                        ");
                        $insWh->execute([
                            ':plant_id' => $newPlantId,
                            ':code' => $whCode,
                            ':name' => $whName
                        ]);
                    }
                }
            }
        }

        // 3. Create Customer Admin User
        $hashedPassword = hashPassword($adminPassword);
        $nameParts = explode(' ', $adminFullName, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $insUser = $pdo->prepare("
            INSERT INTO users (
                category_id, full_name, first_name, last_name, email, username, phone, 
                password_hash, is_active, status, created_by, created_at, updated_at
            )
            VALUES (
                :cat_id, :full_name, :first_name, :last_name, :email, :username, :phone, 
                :pass, true, 'ACTIVE', :creator, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            RETURNING user_id
        ");
        $insUser->execute([
            ':cat_id' => $custAdminCategoryId,
            ':full_name' => $adminFullName,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':email' => $adminEmail,
            ':username' => $adminEmail,
            ':phone' => $adminPhone,
            ':pass' => $hashedPassword,
            ':creator' => $currentUser['user_id']
        ]);
        $newAdminUserId = $insUser->fetchColumn();

        // 4. Assign Customer Admin Role
        $insRole = $pdo->prepare("
            INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at, status)
            VALUES (:uid, :rid, :assigner, CURRENT_TIMESTAMP, 'Active')
        ");
        $insRole->execute([
            ':uid' => $newAdminUserId,
            ':rid' => $customerAdminRoleId,
            ':assigner' => $currentUser['user_id']
        ]);

        // 5. Assign Full Customer-Level Organization Scope
        $insScope = $pdo->prepare("
            INSERT INTO user_organization_access (
                user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status
            )
            VALUES (:uid, :cid, NULL, NULL, NULL, NULL, CURRENT_DATE, 'Active')
        ");
        $insScope->execute([
            ':uid' => $newAdminUserId,
            ':cid' => $newCustomerId
        ]);

        // 6. Automatically Assign Default FREE Subscription
        require_once __DIR__ . '/../config/subscription.php';
        $subResult = assignDefaultCustomerSubscription((int)$newCustomerId, (int)$currentUser['user_id']);
        if (!$subResult['success']) {
            throw new Exception("Customer created, but failed to assign default FREE subscription: " . ($subResult['error'] ?? ''));
        }

        // 7. Record Audit Log
        $insAudit = $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
            VALUES (:uid, 'customers', :eid, 'INSERT', NULL, :new_val, CURRENT_TIMESTAMP, :ip)
        ");
        $insAudit->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $newCustomerId,
            ':new_val' => "Customer created: {$customerName} ({$customerCode}), Admin: {$adminFullName} ({$adminEmail}), Subscription: {$subResult['plan_name']}",
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Customer created successfully with default FREE subscription.',
            'customer_id' => $newCustomerId,
            'customer_code' => $customerCode,
            'customer_name' => $customerName,
            'admin_name' => $adminFullName,
            'admin_email' => $adminEmail,
            'subscription' => [
                'subscription_id' => $subResult['subscription_id'],
                'plan_code'       => $subResult['plan_code'],
                'plan_name'       => $subResult['plan_name'],
                'max_users'       => $subResult['max_users'],
                'status'          => $subResult['status']
            ]
        ]);
        exit;
    }

    /* ============================================================
       ACTION: UPDATE CUSTOMER
       ============================================================ */
    elseif ($action === 'update_customer') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $customerName = trim($_POST['customer_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (!$customerId || empty($customerName) || empty($contactEmail)) {
            throw new Exception("Missing required fields for customer update.");
        }

        // Get old values for audit
        $oldStmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = :id");
        $oldStmt->execute([':id' => $customerId]);
        $oldVal = $oldStmt->fetch(PDO::FETCH_ASSOC);

        $upd = $pdo->prepare("
            UPDATE customers
            SET customer_name = :name,
                contact_person = :contact_person,
                contact_email = :contact_email,
                contact_phone = :contact_phone,
                address = :address,
                status = :status
            WHERE customer_id = :id
        ");
        $upd->execute([
            ':name' => $customerName,
            ':contact_person' => $contactPerson,
            ':contact_email' => $contactEmail,
            ':contact_phone' => $contactPhone,
            ':address' => $address,
            ':status' => $status,
            ':id' => $customerId
        ]);

        // Record Audit
        $audit = $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
            VALUES (:uid, 'customers', :eid, 'UPDATE', :old_val, :new_val, CURRENT_TIMESTAMP, :ip)
        ");
        $audit->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $customerId,
            ':old_val' => json_encode($oldVal),
            ':new_val' => json_encode(['customer_name' => $customerName, 'status' => $status]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        echo json_encode(['success' => true, 'message' => 'Customer updated successfully.']);
        exit;
    }

    /* ============================================================
       ACTION: DEACTIVATE / TOGGLE STATUS
       ============================================================ */
    elseif ($action === 'deactivate_customer') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Inactive');

        if (!$customerId) {
            throw new Exception("Invalid Customer ID.");
        }

        $upd = $pdo->prepare("UPDATE customers SET status = :status WHERE customer_id = :id");
        $upd->execute([':status' => $newStatus, ':id' => $customerId]);

        // Audit Log
        $audit = $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
            VALUES (:uid, 'customers', :eid, :act, NULL, :new_val, CURRENT_TIMESTAMP, :ip)
        ");
        $audit->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $customerId,
            ':act' => ($newStatus === 'Inactive' ? 'DEACTIVATE' : 'ACTIVATE'),
            ':new_val' => "Customer status changed to {$newStatus}",
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        echo json_encode(['success' => true, 'message' => "Customer status updated to {$newStatus}."]);
        exit;
    }

    /* ============================================================
       ACTION: GET ORGANIZATION TREE
       ============================================================ */
    elseif ($action === 'get_org_tree') {
        $customerId = (int)($_GET['customer_id'] ?? 0);
        if (!$customerId) {
            throw new Exception("Customer ID required.");
        }

        $compStmt = $pdo->prepare("SELECT company_id, company_code, company_name FROM companies WHERE customer_id = :cid ORDER BY company_name");
        $compStmt->execute([':cid' => $customerId]);
        $companies = $compStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($companies as &$comp) {
            $divStmt = $pdo->prepare("SELECT division_id, division_code, division_name FROM divisions WHERE company_id = :comp_id ORDER BY division_name");
            $divStmt->execute([':comp_id' => $comp['company_id']]);
            $comp['divisions'] = $divStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($comp['divisions'] as &$div) {
                $pStmt = $pdo->prepare("SELECT plant_id, plant_code, plant_name FROM plants WHERE division_id = :div_id ORDER BY plant_name");
                $pStmt->execute([':div_id' => $div['division_id']]);
                $div['plants'] = $pStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($div['plants'] as &$plant) {
                    $wStmt = $pdo->prepare("SELECT warehouse_id, warehouse_code, warehouse_name FROM warehouses WHERE plant_id = :plant_id ORDER BY warehouse_name");
                    $wStmt->execute([':plant_id' => $plant['plant_id']]);
                    $plant['warehouses'] = $wStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }

        echo json_encode(['success' => true, 'tree' => $companies]);
        exit;
    }

    else {
        throw new Exception("Unsupported API action: {$action}");
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
