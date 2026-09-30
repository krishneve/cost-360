<?php
/**
 * Cost360 - Division Master API Handler
 * Hierarchy: Company -> Division
 * Handles Division CRUD, company dropdown filtering, RBAC & audit logging.
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
       ACTION: LIST DIVISIONS
       ============================================================ */
    if ($action === 'list') {
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');
        $companyFilter = (int)($_GET['company_id'] ?? 0);

        $sql = "
            SELECT 
                d.division_id,
                d.company_id,
                d.division_code,
                d.division_name,
                d.status,
                c.company_name,
                c.company_code,
                cust.customer_name,
                (SELECT COUNT(*) FROM plants p WHERE p.division_id = d.division_id AND p.status = 'Active') AS plant_count
            FROM divisions d
            JOIN companies c ON d.company_id = c.company_id
            LEFT JOIN customers cust ON c.customer_id = cust.customer_id
            WHERE 1=1
        ";

        $params = [];

        // Scoping
        if ($companyFilter > 0) {
            $sql .= " AND d.company_id = :comp_filter";
            $params[':comp_filter'] = $companyFilter;
        } elseif (!$isPlatform && $activeCompanyId) {
            $sql .= " AND d.company_id = :active_comp";
            $params[':active_comp'] = $activeCompanyId;
        } elseif (!$isPlatform && $activeCustomerId) {
            $sql .= " AND c.customer_id = :active_cust";
            $params[':active_cust'] = $activeCustomerId;
        }

        $customerFilter = (int)($_GET['customer_id'] ?? 0);
        if ($customerFilter > 0) {
            $sql .= " AND c.customer_id = :cust_filter";
            $params[':cust_filter'] = $customerFilter;
        }

        if ($search !== '') {
            $sql .= " AND (d.division_code ILIKE :search OR d.division_name ILIKE :search OR c.company_name ILIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL' && $statusFilter !== 'All Status') {
            $sql .= " AND UPPER(d.status) = :status";
            $params[':status'] = strtoupper($statusFilter);
        }

        $sql .= " ORDER BY c.company_name ASC, d.division_name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($divisions);
        $active = 0;
        $inactive = 0;
        foreach ($divisions as $div) {
            if (strtoupper($div['status']) === 'ACTIVE') {
                $active++;
            } else {
                $inactive++;
            }
        }

        // Customers list for top dependent filter
        $custSql = "SELECT customer_id, customer_code, customer_name FROM customers WHERE UPPER(status) = 'ACTIVE'";
        $custParams = [];
        if (!$isPlatform && $activeCustomerId) {
            $custSql .= " AND customer_id = :cid";
            $custParams[':cid'] = $activeCustomerId;
        }
        $custSql .= " ORDER BY customer_name ASC";
        $custStmt = $pdo->prepare($custSql);
        $custStmt->execute($custParams);
        $customersList = $custStmt->fetchAll(PDO::FETCH_ASSOC);

        // Database-driven companies list for filter & modal dropdown
        $compSql = "SELECT company_id, customer_id, company_code, company_name FROM companies WHERE UPPER(status) = 'ACTIVE'";
        $compParams = [];
        if ($customerFilter > 0) {
            $compSql .= " AND customer_id = :cid";
            $compParams[':cid'] = $customerFilter;
        } elseif (!$isPlatform && $activeCustomerId) {
            $compSql .= " AND customer_id = :cid";
            $compParams[':cid'] = $activeCustomerId;
        }
        $compSql .= " ORDER BY company_name ASC";
        $cStmt = $pdo->prepare($compSql);
        $cStmt->execute($compParams);
        $companiesList = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'divisions' => $divisions,
            'customers' => $customersList,
            'companies' => $companiesList,
            'kpis' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive
            ]
        ]);
        exit;
    }

    /* ============================================================
       ACTION: GET SINGLE DIVISION
       ============================================================ */
    elseif ($action === 'get') {
        $id = (int)($_GET['division_id'] ?? $_POST['division_id'] ?? 0);
        if (!$id) {
            throw new Exception("Invalid Division ID.");
        }

        $stmt = $pdo->prepare("SELECT * FROM divisions WHERE division_id = :id");
        $stmt->execute([':id' => $id]);
        $division = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$division) {
            throw new Exception("Division not found.");
        }

        echo json_encode(['success' => true, 'division' => $division]);
        exit;
    }

    /* ============================================================
       ACTION: CREATE DIVISION
       ============================================================ */
    elseif ($action === 'create') {
        $companyId = (int)($_POST['company_id'] ?? 0);
        $divisionCode = strtoupper(trim($_POST['division_code'] ?? ''));
        $divisionName = trim($_POST['division_name'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (!$companyId) {
            throw new Exception("Company selection is required.");
        }
        if (empty($divisionCode) || empty($divisionName)) {
            throw new Exception("Division Code and Division Name are required.");
        }

        // Verify company exists
        $compCheck = $pdo->prepare("SELECT company_id, customer_id FROM companies WHERE company_id = :cid");
        $compCheck->execute([':cid' => $companyId]);
        $compRecord = $compCheck->fetch(PDO::FETCH_ASSOC);
        if (!$compRecord) {
            throw new Exception("Selected company does not exist.");
        }

        // Verify security scope
        if (!$isPlatform && $activeCustomerId && (int)$compRecord['customer_id'] !== (int)$activeCustomerId) {
            throw new Exception("Unauthorized to add division for this company.");
        }

        // Check duplicate division code in company
        $check = $pdo->prepare("SELECT division_id FROM divisions WHERE company_id = :cid AND UPPER(division_code) = :code");
        $check->execute([':cid' => $companyId, ':code' => $divisionCode]);
        if ($check->fetch()) {
            throw new Exception("Division Code '{$divisionCode}' already exists for the selected company.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO divisions (company_id, division_code, division_name, status)
            VALUES (:cid, :code, :name, :status)
            RETURNING division_id
        ");
        $stmt->execute([
            ':cid' => $companyId,
            ':code' => $divisionCode,
            ':name' => $divisionName,
            ':status' => $status
        ]);
        $newId = $stmt->fetchColumn();

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'CREATE_DIVISION', 'Organization', 'Division', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $newId,
            ':details' => json_encode(['company_id' => $companyId, 'code' => $divisionCode, 'name' => $divisionName]),
            ':ip' => $ip
        ]);

        $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, new_value, timestamp, ip_address)
            VALUES (:uid, 'divisions', :eid, 'DIVISION_CREATED', :nval, CURRENT_TIMESTAMP, :ip)
        ")->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $newId,
            ':nval' => json_encode(['division_id' => $newId, 'company_id' => $companyId, 'division_code' => $divisionCode, 'division_name' => $divisionName]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Division created successfully.', 'division_id' => $newId]);
        exit;
    }

    /* ============================================================
       ACTION: UPDATE DIVISION
       ============================================================ */
    elseif ($action === 'update') {
        $id = (int)($_POST['division_id'] ?? 0);
        $companyId = (int)($_POST['company_id'] ?? 0);
        $divisionName = trim($_POST['division_name'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (!$id || !$companyId || empty($divisionName)) {
            throw new Exception("Division ID, Company, and Division Name are required.");
        }

        $oldStmt = $pdo->prepare("SELECT * FROM divisions WHERE division_id = :id");
        $oldStmt->execute([':id' => $id]);
        $oldDiv = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldDiv) {
            throw new Exception("Division record not found.");
        }

        $stmt = $pdo->prepare("
            UPDATE divisions
            SET company_id = :cid,
                division_name = :name,
                status = :status
            WHERE division_id = :id
        ");
        $stmt->execute([
            ':cid' => $companyId,
            ':name' => $divisionName,
            ':status' => $status,
            ':id' => $id
        ]);

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'UPDATE_DIVISION', 'Organization', 'Division', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['old' => $oldDiv, 'new' => ['company_id' => $companyId, 'name' => $divisionName, 'status' => $status]]),
            ':ip' => $ip
        ]);

        $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
            VALUES (:uid, 'divisions', :eid, 'DIVISION_UPDATED', :oval, :nval, CURRENT_TIMESTAMP, :ip)
        ")->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $id,
            ':oval' => json_encode($oldDiv),
            ':nval' => json_encode(['division_id' => $id, 'company_id' => $companyId, 'division_name' => $divisionName, 'status' => $status]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Division updated successfully.']);
        exit;
    }

    /* ============================================================
       ACTION: TOGGLE STATUS
       ============================================================ */
    elseif ($action === 'toggle_status') {
        $id = (int)($_POST['division_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Inactive');

        if (!$id) {
            throw new Exception("Invalid Division ID.");
        }

        $stmt = $pdo->prepare("UPDATE divisions SET status = :status WHERE division_id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $id]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'TOGGLE_DIVISION_STATUS', 'Organization', 'Division', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['new_status' => $newStatus]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => "Division status changed to {$newStatus}."]);
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
