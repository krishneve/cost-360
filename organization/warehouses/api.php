<?php
/**
 * Cost360 - Warehouse Master API Handler
 * Hierarchy: Company -> Division -> Plant -> Warehouse
 * Handles Warehouse CRUD, dependent dropdown chain, RBAC & audit logging.
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

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    $pdo = getDbConnection();

    /* ============================================================
       ACTION: GET PLANTS FOR A SELECTED DIVISION (DEPENDENT DROPDOWN)
       ============================================================ */
    if ($action === 'get_plants') {
        $divisionId = (int)($_GET['division_id'] ?? 0);
        if (!$divisionId) {
            echo json_encode(['success' => true, 'plants' => []]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT plant_id, plant_code, plant_name FROM plants WHERE division_id = :did AND UPPER(status) = 'ACTIVE' ORDER BY plant_name ASC");
        $stmt->execute([':did' => $divisionId]);
        $plants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'plants' => $plants]);
        exit;
    }

    /* ============================================================
       ACTION: LIST WAREHOUSES
       ============================================================ */
    elseif ($action === 'list') {
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');
        $companyFilter = (int)($_GET['company_id'] ?? 0);
        $divisionFilter = (int)($_GET['division_id'] ?? 0);
        $plantFilter = (int)($_GET['plant_id'] ?? 0);

        $sql = "
            SELECT 
                w.warehouse_id,
                w.plant_id,
                w.warehouse_code,
                w.warehouse_name,
                w.status,
                p.plant_code,
                p.plant_name,
                d.division_id,
                d.division_code,
                d.division_name,
                c.company_id,
                c.company_code,
                c.company_name,
                cust.customer_name
            FROM warehouses w
            JOIN plants p ON w.plant_id = p.plant_id
            JOIN divisions d ON p.division_id = d.division_id
            JOIN companies c ON d.company_id = c.company_id
            LEFT JOIN customers cust ON c.customer_id = cust.customer_id
            WHERE 1=1
        ";

        $params = [];

        if ($plantFilter > 0) {
            $sql .= " AND w.plant_id = :p_filter";
            $params[':p_filter'] = $plantFilter;
        } elseif ($divisionFilter > 0) {
            $sql .= " AND p.division_id = :d_filter";
            $params[':d_filter'] = $divisionFilter;
        } elseif ($companyFilter > 0) {
            $sql .= " AND d.company_id = :c_filter";
            $params[':c_filter'] = $companyFilter;
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
            $sql .= " AND (w.warehouse_code ILIKE :search OR w.warehouse_name ILIKE :search OR p.plant_name ILIKE :search OR d.division_name ILIKE :search OR c.company_name ILIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL' && $statusFilter !== 'All Status') {
            $sql .= " AND UPPER(w.status) = :status";
            $params[':status'] = strtoupper($statusFilter);
        }

        $sql .= " ORDER BY c.company_name ASC, d.division_name ASC, p.plant_name ASC, w.warehouse_name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($warehouses);
        $active = 0;
        $inactive = 0;
        foreach ($warehouses as $wh) {
            if (strtoupper($wh['status']) === 'ACTIVE') {
                $active++;
            } else {
                $inactive++;
            }
        }

        // Fetch Customers list
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

        // Database-driven Companies list
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
            'warehouses' => $warehouses,
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
       ACTION: GET SINGLE WAREHOUSE
       ============================================================ */
    elseif ($action === 'get') {
        $id = (int)($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);
        if (!$id) {
            throw new Exception("Invalid Warehouse ID.");
        }

        $stmt = $pdo->prepare("
            SELECT w.*, p.division_id, d.company_id 
            FROM warehouses w 
            JOIN plants p ON w.plant_id = p.plant_id 
            JOIN divisions d ON p.division_id = d.division_id 
            WHERE w.warehouse_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$warehouse) {
            throw new Exception("Warehouse record not found.");
        }

        echo json_encode(['success' => true, 'warehouse' => $warehouse]);
        exit;
    }

    /* ============================================================
       ACTION: CREATE WAREHOUSE
       ============================================================ */
    elseif ($action === 'create') {
        $plantId = (int)($_POST['plant_id'] ?? 0);
        $warehouseCode = strtoupper(trim($_POST['warehouse_code'] ?? ''));
        $warehouseName = trim($_POST['warehouse_name'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (!$plantId) {
            throw new Exception("Plant selection is required.");
        }
        if (empty($warehouseCode) || empty($warehouseName)) {
            throw new Exception("Warehouse Code and Warehouse Name are required.");
        }

        // Verify plant exists
        $pCheck = $pdo->prepare("
            SELECT p.plant_id, d.company_id, c.customer_id 
            FROM plants p 
            JOIN divisions d ON p.division_id = d.division_id 
            JOIN companies c ON d.company_id = c.company_id 
            WHERE p.plant_id = :pid
        ");
        $pCheck->execute([':pid' => $plantId]);
        $pRecord = $pCheck->fetch(PDO::FETCH_ASSOC);
        if (!$pRecord) {
            throw new Exception("Selected Plant does not exist.");
        }

        if (!$isPlatform && $activeCustomerId && (int)$pRecord['customer_id'] !== (int)$activeCustomerId) {
            throw new Exception("Unauthorized to add warehouse for this plant.");
        }

        // Check duplicate code in plant
        $check = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE plant_id = :pid AND UPPER(warehouse_code) = :code");
        $check->execute([':pid' => $plantId, ':code' => $warehouseCode]);
        if ($check->fetch()) {
            throw new Exception("Warehouse Code '{$warehouseCode}' already exists for the selected plant.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO warehouses (plant_id, warehouse_code, warehouse_name, status)
            VALUES (:pid, :code, :name, :status)
            RETURNING warehouse_id
        ");
        $stmt->execute([
            ':pid' => $plantId,
            ':code' => $warehouseCode,
            ':name' => $warehouseName,
            ':status' => $status
        ]);
        $newId = $stmt->fetchColumn();

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'CREATE_WAREHOUSE', 'Organization', 'Warehouse', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $newId,
            ':details' => json_encode(['plant_id' => $plantId, 'code' => $warehouseCode, 'name' => $warehouseName]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Warehouse created successfully.', 'warehouse_id' => $newId]);
        exit;
    }

    /* ============================================================
       ACTION: UPDATE WAREHOUSE
       ============================================================ */
    elseif ($action === 'update') {
        $id = (int)($_POST['warehouse_id'] ?? 0);
        $plantId = (int)($_POST['plant_id'] ?? 0);
        $warehouseName = trim($_POST['warehouse_name'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (!$id || !$plantId || empty($warehouseName)) {
            throw new Exception("Warehouse ID, Plant, and Warehouse Name are required.");
        }

        $oldStmt = $pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = :id");
        $oldStmt->execute([':id' => $id]);
        $oldWh = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldWh) {
            throw new Exception("Warehouse record not found.");
        }

        $stmt = $pdo->prepare("
            UPDATE warehouses
            SET plant_id = :pid,
                warehouse_name = :name,
                status = :status
            WHERE warehouse_id = :id
        ");
        $stmt->execute([
            ':pid' => $plantId,
            ':name' => $warehouseName,
            ':status' => $status,
            ':id' => $id
        ]);

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'UPDATE_WAREHOUSE', 'Organization', 'Warehouse', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['old' => $oldWh, 'new' => ['plant_id' => $plantId, 'name' => $warehouseName, 'status' => $status]]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Warehouse updated successfully.']);
        exit;
    }

    /* ============================================================
       ACTION: TOGGLE STATUS
       ============================================================ */
    elseif ($action === 'toggle_status') {
        $id = (int)($_POST['warehouse_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Inactive');

        if (!$id) {
            throw new Exception("Invalid Warehouse ID.");
        }

        $stmt = $pdo->prepare("UPDATE warehouses SET status = :status WHERE warehouse_id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $id]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'TOGGLE_WAREHOUSE_STATUS', 'Organization', 'Warehouse', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['new_status' => $newStatus]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => "Warehouse status changed to {$newStatus}."]);
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
