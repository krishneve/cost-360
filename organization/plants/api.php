<?php
/**
 * Cost360 - Plant Master API Handler
 * Hierarchy: Company -> Division -> Plant
 * Handles Plant CRUD, dependent dropdowns (Company -> Division), RBAC & audit logging.
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
       ACTION: GET DIVISIONS FOR A SELECTED COMPANY (DEPENDENT DROPDOWN)
       ============================================================ */
    if ($action === 'get_divisions') {
        $companyId = (int)($_GET['company_id'] ?? 0);
        if (!$companyId) {
            echo json_encode(['success' => true, 'divisions' => []]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT division_id, division_code, division_name FROM divisions WHERE company_id = :cid AND UPPER(status) = 'ACTIVE' ORDER BY division_name ASC");
        $stmt->execute([':cid' => $companyId]);
        $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'divisions' => $divisions]);
        exit;
    }

    /* ============================================================
       ACTION: LIST PLANTS
       ============================================================ */
    elseif ($action === 'list') {
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');
        $companyFilter = (int)($_GET['company_id'] ?? 0);
        $divisionFilter = (int)($_GET['division_id'] ?? 0);

        $sql = "
            SELECT 
                p.plant_id,
                p.division_id,
                p.plant_code,
                p.plant_name,
                p.location,
                p.status,
                d.division_code,
                d.division_name,
                d.company_id,
                c.company_code,
                c.company_name,
                cust.customer_name,
                (SELECT COUNT(*) FROM warehouses w WHERE w.plant_id = p.plant_id AND w.status = 'Active') AS warehouse_count
            FROM plants p
            JOIN divisions d ON p.division_id = d.division_id
            JOIN companies c ON d.company_id = c.company_id
            LEFT JOIN customers cust ON c.customer_id = cust.customer_id
            WHERE 1=1
        ";

        $params = [];

        if ($divisionFilter > 0) {
            $sql .= " AND p.division_id = :div_filter";
            $params[':div_filter'] = $divisionFilter;
        } elseif ($companyFilter > 0) {
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
            $sql .= " AND (p.plant_code ILIKE :search OR p.plant_name ILIKE :search OR p.location ILIKE :search OR d.division_name ILIKE :search OR c.company_name ILIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL' && $statusFilter !== 'All Status') {
            $sql .= " AND UPPER(p.status) = :status";
            $params[':status'] = strtoupper($statusFilter);
        }

        $sql .= " ORDER BY c.company_name ASC, d.division_name ASC, p.plant_name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $plants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($plants);
        $active = 0;
        $inactive = 0;
        foreach ($plants as $pl) {
            if (strtoupper($pl['status']) === 'ACTIVE') {
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
            'plants' => $plants,
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
       ACTION: GET SINGLE PLANT
       ============================================================ */
    elseif ($action === 'get') {
        $id = (int)($_GET['plant_id'] ?? $_POST['plant_id'] ?? 0);
        if (!$id) {
            throw new Exception("Invalid Plant ID.");
        }

        $stmt = $pdo->prepare("
            SELECT p.*, d.company_id 
            FROM plants p 
            JOIN divisions d ON p.division_id = d.division_id 
            WHERE p.plant_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $plant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plant) {
            throw new Exception("Plant record not found.");
        }

        echo json_encode(['success' => true, 'plant' => $plant]);
        exit;
    }

    /* ============================================================
       ACTION: CREATE PLANT
       ============================================================ */
    elseif ($action === 'create') {
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $plantCode = strtoupper(trim($_POST['plant_code'] ?? ''));
        $plantName = trim($_POST['plant_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (!$divisionId) {
            throw new Exception("Division selection is required.");
        }
        if (empty($plantCode) || empty($plantName)) {
            throw new Exception("Plant Code and Plant Name are required.");
        }

        // Verify division exists
        $divCheck = $pdo->prepare("SELECT d.division_id, d.company_id, c.customer_id FROM divisions d JOIN companies c ON d.company_id = c.company_id WHERE d.division_id = :did");
        $divCheck->execute([':did' => $divisionId]);
        $divRecord = $divCheck->fetch(PDO::FETCH_ASSOC);
        if (!$divRecord) {
            throw new Exception("Selected Division does not exist.");
        }

        // Check security scope
        if (!$isPlatform && $activeCustomerId && (int)$divRecord['customer_id'] !== (int)$activeCustomerId) {
            throw new Exception("Unauthorized to add plant for this division.");
        }

        // Check duplicate code in division
        $check = $pdo->prepare("SELECT plant_id FROM plants WHERE division_id = :did AND UPPER(plant_code) = :code");
        $check->execute([':did' => $divisionId, ':code' => $plantCode]);
        if ($check->fetch()) {
            throw new Exception("Plant Code '{$plantCode}' already exists for the selected division.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO plants (division_id, plant_code, plant_name, location, status)
            VALUES (:did, :code, :name, :loc, :status)
            RETURNING plant_id
        ");
        $stmt->execute([
            ':did' => $divisionId,
            ':code' => $plantCode,
            ':name' => $plantName,
            ':loc' => $location,
            ':status' => $status
        ]);
        $newId = $stmt->fetchColumn();

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'CREATE_PLANT', 'Organization', 'Plant', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $newId,
            ':details' => json_encode(['division_id' => $divisionId, 'code' => $plantCode, 'name' => $plantName, 'location' => $location]),
            ':ip' => $ip
        ]);

        $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, new_value, timestamp, ip_address)
            VALUES (:uid, 'plants', :eid, 'PLANT_CREATED', :nval, CURRENT_TIMESTAMP, :ip)
        ")->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $newId,
            ':nval' => json_encode(['plant_id' => $newId, 'division_id' => $divisionId, 'plant_code' => $plantCode, 'plant_name' => $plantName, 'location' => $location]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Plant created successfully.', 'plant_id' => $newId]);
        exit;
    }

    /* ============================================================
       ACTION: UPDATE PLANT
       ============================================================ */
    elseif ($action === 'update') {
        $id = (int)($_POST['plant_id'] ?? 0);
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $plantName = trim($_POST['plant_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (!$id || !$divisionId || empty($plantName)) {
            throw new Exception("Plant ID, Division, and Plant Name are required.");
        }

        $oldStmt = $pdo->prepare("SELECT * FROM plants WHERE plant_id = :id");
        $oldStmt->execute([':id' => $id]);
        $oldPlant = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldPlant) {
            throw new Exception("Plant record not found.");
        }

        $stmt = $pdo->prepare("
            UPDATE plants
            SET division_id = :did,
                plant_name = :name,
                location = :loc,
                status = :status
            WHERE plant_id = :id
        ");
        $stmt->execute([
            ':did' => $divisionId,
            ':name' => $plantName,
            ':loc' => $location,
            ':status' => $status,
            ':id' => $id
        ]);

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'UPDATE_PLANT', 'Organization', 'Plant', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['old' => $oldPlant, 'new' => ['division_id' => $divisionId, 'name' => $plantName, 'location' => $location, 'status' => $status]]),
            ':ip' => $ip
        ]);

        $pdo->prepare("
            INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
            VALUES (:uid, 'plants', :eid, 'PLANT_UPDATED', :oval, :nval, CURRENT_TIMESTAMP, :ip)
        ")->execute([
            ':uid' => $currentUser['user_id'],
            ':eid' => $id,
            ':oval' => json_encode($oldPlant),
            ':nval' => json_encode(['plant_id' => $id, 'division_id' => $divisionId, 'plant_name' => $plantName, 'location' => $location, 'status' => $status]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Plant updated successfully.']);
        exit;
    }

    /* ============================================================
       ACTION: TOGGLE STATUS
       ============================================================ */
    elseif ($action === 'toggle_status') {
        $id = (int)($_POST['plant_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Inactive');

        if (!$id) {
            throw new Exception("Invalid Plant ID.");
        }

        $stmt = $pdo->prepare("UPDATE plants SET status = :status WHERE plant_id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $id]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'TOGGLE_PLANT_STATUS', 'Organization', 'Plant', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['new_status' => $newStatus]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => "Plant status changed to {$newStatus}."]);
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
