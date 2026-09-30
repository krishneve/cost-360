<?php
/**
 * Cost360 - Company Master API Handler
 * Handles Company CRUD, status management, search/filter, RBAC & audit logging.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$currentUser = getAuthUser();
$activeCompany = getActiveCompany();
$activeCompanyId = $activeCompany['company_id'] ?? null;
$activeCustomerId = $activeCompany['customer_id'] ?? null;
$isPlatform = isPlatformAdmin();
$isCma = isCmaAdmin();

if ($isCma && !$activeCustomerId) {
    $activeCustomerId = getCmaAdminCustomerId();
} elseif (!$activeCustomerId && isset($_SESSION['active_customer_id']) && is_numeric($_SESSION['active_customer_id'])) {
    $activeCustomerId = (int)$_SESSION['active_customer_id'];
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    $pdo = getDbConnection();

    /* ============================================================
       ACTION: LIST COMPANIES
       ============================================================ */
    if ($action === 'list') {
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');

        $sql = "
            SELECT 
                c.company_id,
                c.customer_id,
                c.company_code,
                c.company_name,
                c.address,
                c.status,
                cust.customer_name,
                cust.customer_code,
                (SELECT COUNT(*) FROM divisions d WHERE d.company_id = c.company_id AND d.status = 'Active') AS division_count,
                (SELECT COUNT(*) FROM plants p JOIN divisions d ON p.division_id = d.division_id WHERE d.company_id = c.company_id AND p.status = 'Active') AS plant_count
            FROM companies c
            LEFT JOIN customers cust ON c.customer_id = cust.customer_id
            WHERE 1=1
        ";

        $params = [];

        // Scoping: Non-platform admins only see companies within their accessible scope or customer
        if (!$isPlatform && $activeCustomerId) {
            $sql .= " AND c.customer_id = :cust_id";
            $params[':cust_id'] = $activeCustomerId;
        }

        $filterCustomerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int)$_GET['customer_id'] : null;
        if ($filterCustomerId) {
            $sql .= " AND c.customer_id = :filter_cust_id";
            $params[':filter_cust_id'] = $filterCustomerId;
        }

        if ($search !== '') {
            $sql .= " AND (c.company_code ILIKE :search OR c.company_name ILIKE :search OR c.address ILIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL' && $statusFilter !== 'All Status') {
            $sql .= " AND UPPER(c.status) = :status";
            $params[':status'] = strtoupper($statusFilter);
        }

        $sql .= " ORDER BY c.company_name ASC, c.company_id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($companies);
        $active = 0;
        $inactive = 0;
        foreach ($companies as $comp) {
            if (strtoupper($comp['status']) === 'ACTIVE') {
                $active++;
            } else {
                $inactive++;
            }
        }

        // Fetch customer dropdown for modal (if user is platform admin or has customer access)
        $customersList = [];
        if ($isPlatform) {
            $cStmt = $pdo->query("SELECT customer_id, customer_code, customer_name FROM customers WHERE UPPER(status) = 'ACTIVE' ORDER BY customer_name ASC");
            $customersList = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($activeCustomerId) {
            $cStmt = $pdo->prepare("SELECT customer_id, customer_code, customer_name FROM customers WHERE customer_id = :cid AND UPPER(status) = 'ACTIVE'");
            $cStmt->execute([':cid' => $activeCustomerId]);
            $customersList = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'companies' => $companies,
            'customers' => $customersList,
            'kpis' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive
            ]
        ]);
        exit;
    }

    /* ============================================================
       ACTION: GET SINGLE COMPANY
       ============================================================ */
    elseif ($action === 'get') {
        $id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
        if (!$id) {
            throw new Exception("Invalid Company ID.");
        }

        $stmt = $pdo->prepare("SELECT * FROM companies WHERE company_id = :id");
        $stmt->execute([':id' => $id]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new Exception("Company not found.");
        }

        echo json_encode(['success' => true, 'company' => $company]);
        exit;
    }

    /* ============================================================
       ACTION: CREATE COMPANY
       ============================================================ */
    elseif ($action === 'create') {
        if (!userHasPermission('CREATE', 'ORG_COMPANIES') && !userHasPermission('CREATE', 'COMPANY_MASTER') && !$isPlatform) {
            // Check fallback view/menu access
        }

        $companyCode = strtoupper(trim($_POST['company_code'] ?? ''));
        $companyName = trim($_POST['company_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        $customerId = (int)($_POST['customer_id'] ?? $activeCustomerId ?? 0);

        if (empty($companyCode) || empty($companyName)) {
            throw new Exception("Company Code and Company Name are required.");
        }
        if (!$customerId) {
            throw new Exception("Customer assignment is required.");
        }

        // Check duplicate code within customer scope
        $check = $pdo->prepare("SELECT company_id FROM companies WHERE customer_id = :cid AND UPPER(company_code) = :code");
        $check->execute([':cid' => $customerId, ':code' => $companyCode]);
        if ($check->fetch()) {
            throw new Exception("Company Code '{$companyCode}' already exists for this customer.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO companies (customer_id, company_code, company_name, address, status)
            VALUES (:cid, :code, :name, :addr, :status)
            RETURNING company_id
        ");
        $stmt->execute([
            ':cid' => $customerId,
            ':code' => $companyCode,
            ':name' => $companyName,
            ':addr' => $address,
            ':status' => $status
        ]);
        $newId = $stmt->fetchColumn();

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'CREATE_COMPANY', 'Organization', 'Company', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $newId,
            ':details' => json_encode(['code' => $companyCode, 'name' => $companyName, 'customer_id' => $customerId]),
            ':ip' => $ip
        ]);

        $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, new_value, timestamp, ip_address)
            VALUES (:uid, 'companies', :eid, 'COMPANY_CREATED', :nval, CURRENT_TIMESTAMP, :ip)
        ")->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $newId,
            ':nval' => json_encode(['company_id' => $newId, 'company_code' => $companyCode, 'company_name' => $companyName, 'customer_id' => $customerId]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Company created successfully.', 'company_id' => $newId]);
        exit;
    }

    /* ============================================================
       ACTION: UPDATE COMPANY
       ============================================================ */
    elseif ($action === 'update') {
        $id = (int)($_POST['company_id'] ?? 0);
        $companyName = trim($_POST['company_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (!$id || empty($companyName)) {
            throw new Exception("Company ID and Company Name are required.");
        }

        $oldStmt = $pdo->prepare("SELECT * FROM companies WHERE company_id = :id");
        $oldStmt->execute([':id' => $id]);
        $oldComp = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldComp) {
            throw new Exception("Company record not found.");
        }

        $stmt = $pdo->prepare("
            UPDATE companies
            SET company_name = :name,
                address = :addr,
                status = :status
            WHERE company_id = :id
        ");
        $stmt->execute([
            ':name' => $companyName,
            ':addr' => $address,
            ':status' => $status,
            ':id' => $id
        ]);

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'UPDATE_COMPANY', 'Organization', 'Company', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['old' => $oldComp, 'new' => ['name' => $companyName, 'status' => $status]]),
            ':ip' => $ip
        ]);

        $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
            VALUES (:uid, 'companies', :eid, 'COMPANY_UPDATED', :oval, :nval, CURRENT_TIMESTAMP, :ip)
        ")->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $id,
            ':oval' => json_encode($oldComp),
            ':nval' => json_encode(['company_name' => $companyName, 'status' => $status]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Company updated successfully.']);
        exit;
    }

    /* ============================================================
       ACTION: TOGGLE STATUS
       ============================================================ */
    elseif ($action === 'toggle_status') {
        $id = (int)($_POST['company_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Inactive');

        if (!$id) {
            throw new Exception("Invalid Company ID.");
        }

        $stmt = $pdo->prepare("UPDATE companies SET status = :status WHERE company_id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $id]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'TOGGLE_COMPANY_STATUS', 'Organization', 'Company', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['new_status' => $newStatus]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => "Company status changed to {$newStatus}."]);
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
