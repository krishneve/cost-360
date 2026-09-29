<?php
/**
 * Cost360 - Period Master API Handler
 * Uses actual Neon PostgreSQL schema: period_id, period_name, start_date, end_date, is_locked, status
 * Handles Period CRUD, locking/unlocking, status management, RBAC & audit logging.
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
$isPlatform = isPlatformAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    $pdo = getDbConnection();

    /* ============================================================
       ACTION: LIST PERIODS
       ============================================================ */
    if ($action === 'list') {
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');
        $lockedFilter = trim($_GET['is_locked'] ?? '');

        $sql = "
            SELECT 
                period_id,
                period_name,
                start_date,
                end_date,
                is_locked,
                status
            FROM periods
            WHERE 1=1
        ";

        $params = [];

        if ($search !== '') {
            $sql .= " AND (period_name ILIKE :search OR CAST(start_date AS TEXT) ILIKE :search OR CAST(end_date AS TEXT) ILIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL' && $statusFilter !== 'All Status') {
            $sql .= " AND UPPER(status) = :status";
            $params[':status'] = strtoupper($statusFilter);
        }

        if ($lockedFilter === '1' || strtolower($lockedFilter) === 'locked') {
            $sql .= " AND is_locked = TRUE";
        } elseif ($lockedFilter === '0' || strtolower($lockedFilter) === 'unlocked') {
            $sql .= " AND is_locked = FALSE";
        }

        $sql .= " ORDER BY start_date DESC, period_id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($periods);
        $active = 0;
        $inactive = 0;
        $lockedCount = 0;

        foreach ($periods as $p) {
            if (strtoupper($p['status']) === 'ACTIVE') {
                $active++;
            } else {
                $inactive++;
            }
            if ($p['is_locked']) {
                $lockedCount++;
            }
        }

        echo json_encode([
            'success' => true,
            'periods' => $periods,
            'kpis' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'locked' => $lockedCount
            ]
        ]);
        exit;
    }

    /* ============================================================
       ACTION: GET SINGLE PERIOD
       ============================================================ */
    elseif ($action === 'get') {
        $id = (int)($_GET['period_id'] ?? $_POST['period_id'] ?? 0);
        if (!$id) {
            throw new Exception("Invalid Period ID.");
        }

        $stmt = $pdo->prepare("SELECT * FROM periods WHERE period_id = :id");
        $stmt->execute([':id' => $id]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            throw new Exception("Period record not found.");
        }

        echo json_encode(['success' => true, 'period' => $period]);
        exit;
    }

    /* ============================================================
       ACTION: CREATE PERIOD
       ============================================================ */
    elseif ($action === 'create') {
        $periodName = trim($_POST['period_name'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $isLocked = filter_var($_POST['is_locked'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $status = trim($_POST['status'] ?? 'Active');

        if (empty($periodName)) {
            throw new Exception("Period Name is required.");
        }
        if (empty($startDate) || empty($endDate)) {
            throw new Exception("Start Date and End Date are required.");
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            throw new Exception("Start Date cannot be later than End Date.");
        }

        // Check duplicate period name
        $check = $pdo->prepare("SELECT period_id FROM periods WHERE UPPER(period_name) = :pname");
        $check->execute([':pname' => strtoupper($periodName)]);
        if ($check->fetch()) {
            throw new Exception("Period Name '{$periodName}' already exists.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO periods (period_name, start_date, end_date, is_locked, status)
            VALUES (:name, :start_date, :end_date, :is_locked, :status)
            RETURNING period_id
        ");
        $stmt->execute([
            ':name' => $periodName,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
            ':is_locked' => $isLocked ? 'true' : 'false',
            ':status' => $status
        ]);
        $newId = $stmt->fetchColumn();

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'CREATE_PERIOD', 'Organization', 'Period', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $newId,
            ':details' => json_encode(['period_name' => $periodName, 'start_date' => $startDate, 'end_date' => $endDate, 'is_locked' => $isLocked]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Period created successfully.', 'period_id' => $newId]);
        exit;
    }

    /* ============================================================
       ACTION: UPDATE PERIOD
       ============================================================ */
    elseif ($action === 'update') {
        $id = (int)($_POST['period_id'] ?? 0);
        $periodName = trim($_POST['period_name'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $isLocked = filter_var($_POST['is_locked'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $status = trim($_POST['status'] ?? 'Active');

        if (!$id || empty($periodName) || empty($startDate) || empty($endDate)) {
            throw new Exception("Period ID, Period Name, Start Date, and End Date are required.");
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            throw new Exception("Start Date cannot be later than End Date.");
        }

        $oldStmt = $pdo->prepare("SELECT * FROM periods WHERE period_id = :id");
        $oldStmt->execute([':id' => $id]);
        $oldPeriod = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldPeriod) {
            throw new Exception("Period record not found.");
        }

        $stmt = $pdo->prepare("
            UPDATE periods
            SET period_name = :name,
                start_date = :start_date,
                end_date = :end_date,
                is_locked = :is_locked,
                status = :status
            WHERE period_id = :id
        ");
        $stmt->execute([
            ':name' => $periodName,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
            ':is_locked' => $isLocked ? 'true' : 'false',
            ':status' => $status,
            ':id' => $id
        ]);

        // Audit Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'UPDATE_PERIOD', 'Organization', 'Period', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['old' => $oldPeriod, 'new' => ['period_name' => $periodName, 'start_date' => $startDate, 'end_date' => $endDate, 'is_locked' => $isLocked, 'status' => $status]]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => 'Period updated successfully.']);
        exit;
    }

    /* ============================================================
       ACTION: TOGGLE LOCK STATUS
       ============================================================ */
    elseif ($action === 'toggle_lock') {
        $id = (int)($_POST['period_id'] ?? 0);
        $isLocked = filter_var($_POST['is_locked'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$id) {
            throw new Exception("Invalid Period ID.");
        }

        $stmt = $pdo->prepare("UPDATE periods SET is_locked = :is_locked WHERE period_id = :id");
        $stmt->execute([':is_locked' => $isLocked ? 'true' : 'false', ':id' => $id]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'TOGGLE_PERIOD_LOCK', 'Organization', 'Period', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['is_locked' => $isLocked]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => "Period " . ($isLocked ? "Locked" : "Unlocked") . " successfully."]);
        exit;
    }

    /* ============================================================
       ACTION: TOGGLE STATUS
       ============================================================ */
    elseif ($action === 'toggle_status') {
        $id = (int)($_POST['period_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Inactive');

        if (!$id) {
            throw new Exception("Invalid Period ID.");
        }

        $stmt = $pdo->prepare("UPDATE periods SET status = :status WHERE period_id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $id]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, module, entity_type, entity_id, status, details, ip_address)
            VALUES (:uid, :uname, 'TOGGLE_PERIOD_STATUS', 'Organization', 'Period', :eid, 'Success', :details::jsonb, :ip::inet)
        ");
        $auditStmt->execute([
            ':uid' => $currentUser['user_id'],
            ':uname' => $currentUser['email'] ?? $currentUser['username'],
            ':eid' => $id,
            ':details' => json_encode(['new_status' => $newStatus]),
            ':ip' => $ip
        ]);

        echo json_encode(['success' => true, 'message' => "Period status changed to {$newStatus}."]);
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
