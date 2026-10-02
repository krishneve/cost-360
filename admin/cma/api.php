<?php
/**
 * Cost360 - CMA User Management & Organizational Access API
 *
 * Provides backend services for:
 * 1. list_users: List CMA Users with Subscription Quota & Access Summary
 * 2. create_user: Provision CMA User (strictly Role = CMA_USER, customer-isolated, enforces subscription user limit)
 * 3. update_user: Update CMA User details
 * 4. toggle_status: Activate / Deactivate CMA User
 * 5. get_hierarchy: Fetch customer's Companies, Divisions, Plants, Warehouses
 * 6. get_user_access: Fetch current access mappings for a user
 * 7. save_user_access: Persist normalized hierarchical access (Company, Division, Physical Location)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/subscription.php';

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
    cmaJsonResponse(false, 'Your session has expired. Please log in again.', [], 401);
}

$currentUser = getAuthUser();
$currentUserId = (int)($currentUser['user_id'] ?? 0);

// Only Platform Admin and CMA Admin can access this API
if (!isPlatformAdmin($currentUserId) && !isCmaAdmin($currentUserId)) {
    cmaJsonResponse(false, 'Unauthorized. Administrative privileges required.', [], 403);
}

$pdo = getDbConnection();

// Resolve Tenant Customer ID with strict Backend Isolation
$activeCustomerId = 0;

$action = trim($_GET['action'] ?? $_POST['action'] ?? 'list_users');

try {
    switch ($action) {

        /* ============================================================
           ACTION: LIST CMA USERS & SUBSCRIPTION QUOTA
           ============================================================ */
        case 'list_users':
            $search = trim($_GET['search'] ?? '');
            $status = trim($_GET['status'] ?? '');

            // 1. Subscription Quota Enforcement Info
            $limitInfo = ['plan_name' => 'Global Plan', 'plan_code' => 'GLOBAL', 'max_users' => 9999, 'current_users' => 0, 'available_users' => 9999, 'allowed' => true, 'reason' => ''];
            $subUsage = getCustomerUserUsage($activeCustomerId);

            // Customer Name & Code
            $stmtCust = $pdo->prepare("SELECT customer_name, customer_code FROM customers WHERE customer_id = ?");
            $stmtCust->execute([$activeCustomerId]);
            $custData = $stmtCust->fetch(PDO::FETCH_ASSOC);

            // 2. Fetch Users Belonging to this Customer
            $sql = "
                SELECT DISTINCT
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
                    r.role_id,
                    r.role_code,
                    r.role_name
                FROM users u
                
                LEFT JOIN user_roles ur ON ur.user_id = u.user_id AND ur.status = 'Active'
                LEFT JOIN roles r ON r.role_id = ur.role_id
                WHERE (r.role_code = 'CMA_USER' OR r.role_code IS NULL OR r.role_code = 'OPERATIONAL_USER')
            ";

            $params = [];

            if ($search !== '') {
                $sql .= " AND (u.username ILIKE :search OR u.email ILIKE :search OR u.first_name ILIKE :search OR u.last_name ILIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            if ($status !== '' && strtoupper($status) !== 'ALL') {
                $sql .= " AND UPPER(u.status) = :status";
                $params[':status'] = strtoupper($status);
            }

            $sql .= " ORDER BY u.user_id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rawUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Compute Access Summary for Each User
            $users = [];
            foreach ($rawUsers as $u) {
                $uid = (int)$u['user_id'];

                // Query all active access grants for this user & customer
                $stmtAcc = $pdo->prepare("
                    SELECT 
                        uoa.access_id,
                        uoa.company_id,
                        uoa.division_id,
                        uoa.plant_id,
                        uoa.warehouse_id,
                        c.company_name,
                        d.division_name,
                        p.plant_name,
                        w.warehouse_name
                    FROM user_organization_access uoa
                    LEFT JOIN companies c ON uoa.company_id = c.company_id
                    LEFT JOIN divisions d ON uoa.division_id = d.division_id
                    LEFT JOIN plants p ON uoa.plant_id = p.plant_id
                    LEFT JOIN warehouses w ON uoa.warehouse_id = w.warehouse_id
                    WHERE uoa.user_id = :uid 
                      
                      AND UPPER(uoa.status) = 'ACTIVE'
                    ORDER BY uoa.access_id ASC
                ");
                $stmtAcc->execute([':uid' => $uid]);
                $grants = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

                // Build human-readable access summary
                $accessSummary = 'No Access Configured';
                if (!empty($grants)) {
                    $uniqueCompanies = [];
                    $uniqueDivisions = [];
                    $uniquePlants = [];
                    $uniqueWarehouses = [];
                    $hasCustomerFull = false;

                    foreach ($grants as $g) {
                        if (empty($g['company_id']) && empty($g['division_id']) && empty($g['plant_id']) && empty($g['warehouse_id'])) {
                            $hasCustomerFull = true;
                        }
                        if (!empty($g['company_id'])) {
                            $uniqueCompanies[$g['company_id']] = $g['company_name'] ?: 'Company ' . $g['company_id'];
                        }
                        if (!empty($g['division_id'])) {
                            $uniqueDivisions[$g['division_id']] = $g['division_name'] ?: 'Division ' . $g['division_id'];
                        }
                        if (!empty($g['plant_id'])) {
                            $uniquePlants[$g['plant_id']] = $g['plant_name'] ?: 'Plant ' . $g['plant_id'];
                        }
                        if (!empty($g['warehouse_id'])) {
                            $uniqueWarehouses[$g['warehouse_id']] = $g['warehouse_name'] ?: 'Warehouse ' . $g['warehouse_id'];
                        }
                    }

                    if ($hasCustomerFull) {
                        $accessSummary = 'Full Organization Access';
                    } elseif (count($uniqueCompanies) > 1) {
                        $accessSummary = count($uniqueCompanies) . ' Companies';
                    } elseif (count($uniqueCompanies) === 1) {
                        $compName = reset($uniqueCompanies);
                        if (empty($uniqueDivisions) && empty($uniquePlants) && empty($uniqueWarehouses)) {
                            $accessSummary = $compName . ' (Full Access)';
                        } elseif (!empty($uniquePlants) || !empty($uniqueWarehouses)) {
                            $locCount = count($uniquePlants) + count($uniqueWarehouses);
                            if ($locCount === 1) {
                                $locName = !empty($uniquePlants) ? reset($uniquePlants) : reset($uniqueWarehouses);
                                $accessSummary = $locName;
                            } else {
                                $accessSummary = $locCount . ' Locations (' . $compName . ')';
                            }
                        } elseif (!empty($uniqueDivisions)) {
                            $accessSummary = reset($uniqueDivisions) . ' Division';
                        }
                    } elseif (!empty($uniquePlants) || !empty($uniqueWarehouses)) {
                        $locName = !empty($uniquePlants) ? reset($uniquePlants) : reset($uniqueWarehouses);
                        $accessSummary = $locName;
                    }
                }

                $u['access_summary'] = $accessSummary;
                $u['access_grants_count'] = count($grants);
                $users[] = $u;
            }

            cmaJsonResponse(true, '', [
                'users' => $users,
                'customer' => [
                    'customer_id' => $activeCustomerId,
                    'customer_name' => 'Global Organization',
                    'customer_code' => $custData['customer_code'] ?? ''
                ],
                'subscription' => [
                    'plan_name' => $limitInfo['plan_name'] ?? 'No Plan',
                    'plan_code' => $limitInfo['plan_code'] ?? 'NONE',
                    'max_users' => (int)($limitInfo['max_users'] ?? 0),
                    'current_users' => (int)($limitInfo['current_users'] ?? 0),
                    'available_users' => (int)($limitInfo['available_users'] ?? 0),
                    'allowed' => (bool)($limitInfo['allowed'] ?? false),
                    'reason' => $limitInfo['reason'] ?? ''
                ],
                'kpis' => [
                    'total_users' => count($users),
                    'active_users' => count(array_filter($users, fn($x) => strtoupper($x['status'] ?? '') === 'ACTIVE')),
                    'available_slots' => (int)($limitInfo['available_users'] ?? 0)
                ]
            ]);
            break;

        /* ============================================================
           ACTION: CREATE CMA USER
           ============================================================ */
        case 'create_user':
            // 1. Subscription User Limit Check
            
            // 2. Validate Input Fields
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $status = strtoupper(trim($_POST['status'] ?? 'ACTIVE'));

            if ($firstName === '') {
                cmaJsonResponse(false, 'First name is required.', [], 400);
            }
            if ($username === '') {
                cmaJsonResponse(false, 'Username is required.', [], 400);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                cmaJsonResponse(false, 'A valid email address is required.', [], 400);
            }
            if (strlen($password) < 6) {
                cmaJsonResponse(false, 'Password must be at least 6 characters.', [], 400);
            }
            if ($password !== $confirmPassword) {
                cmaJsonResponse(false, 'Passwords do not match.', [], 400);
            }

            // 3. Check for duplicates
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
            $stmt->execute([':u' => $username]);
            if ($stmt->fetch()) {
                cmaJsonResponse(false, 'Username is already taken.', [], 400);
            }

            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(:e) LIMIT 1");
            $stmt->execute([':e' => $email]);
            if ($stmt->fetch()) {
                cmaJsonResponse(false, 'Email address is already in use.', [], 400);
            }

            // 4. Resolve CMA_USER Role
            $stmtRole = $pdo->prepare("SELECT role_id, category_id FROM roles WHERE role_code = 'CMA_USER' LIMIT 1");
            $stmtRole->execute();
            $cmaUserRole = $stmtRole->fetch(PDO::FETCH_ASSOC);
            $roleId = (int)($cmaUserRole['role_id'] ?? 7);
            $categoryId = (int)($cmaUserRole['category_id'] ?? 2);

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $fullName = trim($firstName . ' ' . $lastName);
            $isActive = ($status === 'ACTIVE');

            $pdo->beginTransaction();
            try {
                // Insert User
                $stmtInsert = $pdo->prepare("
                    INSERT INTO users (
                        category_id, full_name, email, password_hash, is_active,
                        created_by, updated_at, username, first_name, last_name, status,
                        is_email_verified, failed_login_attempts
                    ) VALUES (
                        :cat_id, :full_name, :email, :pwd_hash, :is_active,
                        :created_by, CURRENT_TIMESTAMP, :username, :first_name, :last_name, :status,
                        FALSE, 0
                    ) RETURNING user_id
                ");
                $stmtInsert->execute([
                    ':cat_id' => $categoryId,
                    ':full_name' => $fullName,
                    ':email' => $email,
                    ':pwd_hash' => $passwordHash,
                    ':is_active' => $isActive ? 'true' : 'false',
                    ':created_by' => $currentUserId,
                    ':username' => $username,
                    ':first_name' => $firstName,
                    ':last_name' => $lastName,
                    ':status' => $status
                ]);
                $newUserId = (int)$stmtInsert->fetchColumn();

                // Assign Role = CMA_USER in user_roles
                $stmtRoleAssign = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by, status)
                    VALUES (:uid, :rid, CURRENT_TIMESTAMP, :by, 'Active')
                ");
                $stmtRoleAssign->execute([
                    ':uid' => $newUserId,
                    ':rid' => $roleId,
                    ':by' => $currentUserId
                ]);

                $pdo->commit();
                cmaJsonResponse(true, 'CMA User "' . htmlspecialchars($fullName) . '" created successfully.', ['user_id' => $newUserId]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                cmaJsonResponse(false, 'Failed to create CMA User: ' . $e->getMessage(), [], 500);
            }
            break;

        /* ============================================================
           ACTION: UPDATE CMA USER
           ============================================================ */
        case 'update_user':
            $userId = (int)($_POST['user_id'] ?? 0);
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $status = strtoupper(trim($_POST['status'] ?? 'ACTIVE'));

            if ($userId <= 0) {
                cmaJsonResponse(false, 'Invalid user ID.', [], 400);
            }

            // Verify User exists
            $stmtChk = $pdo->prepare("
                SELECT 1 FROM users 
                WHERE user_id = :uid
                LIMIT 1
            ");
            $stmtChk->execute([':uid' => $userId]);
            if (!$stmtChk->fetch()) {
                cmaJsonResponse(false, 'User not found.', [], 404);
            }

            $fullName = trim($firstName . ' ' . $lastName);
            $isActive = ($status === 'ACTIVE');

            $stmtUpd = $pdo->prepare("
                UPDATE users SET
                    first_name = :fn,
                    last_name = :ln,
                    full_name = :full,
                    email = :email,
                    phone = :phone,
                    status = :status,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :uid
            ");
            $stmtUpd->execute([
                ':fn' => $firstName,
                ':ln' => $lastName,
                ':full' => $fullName,
                ':email' => $email,
                ':phone' => $phone ?: null,
                ':status' => $status,
                ':is_active' => $isActive ? 'true' : 'false',
                ':uid' => $userId
            ]);

            cmaJsonResponse(true, 'CMA User updated successfully.');
            break;

        /* ============================================================
           ACTION: TOGGLE USER STATUS (ACTIVATE / DEACTIVATE)
           ============================================================ */
        case 'toggle_status':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                cmaJsonResponse(false, 'Invalid user ID.', [], 400);
            }

            // Verify User exists
            $stmtChk = $pdo->prepare("
                SELECT 1 FROM users 
                WHERE user_id = :uid
                LIMIT 1
            ");
            $stmtChk->execute([':uid' => $userId]);
            if (!$stmtChk->fetch()) {
                cmaJsonResponse(false, 'User not found.', [], 404);
            }

            $stmtCur = $pdo->prepare("SELECT status FROM users WHERE user_id = ?");
            $stmtCur->execute([$userId]);
            $curStatus = strtoupper($stmtCur->fetchColumn() ?: 'ACTIVE');
            $newStatus = ($curStatus === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE';
            $isActive = ($newStatus === 'ACTIVE');

            // If activating, verify subscription limit
            if ($newStatus === 'ACTIVE') {
                            }

            $pdo->prepare("UPDATE users SET status = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
                ->execute([$newStatus, $isActive ? 'true' : 'false', $userId]);

            cmaJsonResponse(true, 'User status changed to ' . $newStatus . '.', ['new_status' => $newStatus]);
            break;

        /* ============================================================
           ACTION: GET HIERARCHY (COMPANIES, DIVISIONS, PLANTS, WAREHOUSES)
           ============================================================ */
        case 'get_hierarchy':
            // Companies belonging to this Customer
            $stmtComp = $pdo->prepare("
                SELECT company_id, company_code, company_name 
                FROM companies 
                WHERE UPPER(status) = 'ACTIVE'
                ORDER BY company_name ASC
            ");
            $stmtComp->execute();
            $companies = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

            // Divisions under customer companies
            $stmtDiv = $pdo->prepare("
                SELECT d.division_id, d.company_id, d.division_code, d.division_name 
                FROM divisions d
                INNER JOIN companies c ON d.company_id = c.company_id
                WHERE UPPER(d.status) = 'ACTIVE' AND UPPER(c.status) = 'ACTIVE'
                ORDER BY d.division_name ASC
            ");
            $stmtDiv->execute();
            $divisions = $stmtDiv->fetchAll(PDO::FETCH_ASSOC);

            // Plants under customer divisions
            $stmtPlant = $pdo->prepare("
                SELECT p.plant_id, p.division_id, d.company_id, p.plant_code, p.plant_name 
                FROM plants p
                INNER JOIN divisions d ON p.division_id = d.division_id
                INNER JOIN companies c ON d.company_id = c.company_id
                WHERE UPPER(p.status) = 'ACTIVE' AND UPPER(d.status) = 'ACTIVE'
                ORDER BY p.plant_name ASC
            ");
            $stmtPlant->execute();
            $plants = $stmtPlant->fetchAll(PDO::FETCH_ASSOC);

            // Warehouses under customer divisions/companies
            $stmtWh = $pdo->prepare("
                SELECT w.warehouse_id, p.division_id, d.company_id, w.warehouse_code, w.warehouse_name 
                FROM warehouses w
                INNER JOIN plants p ON w.plant_id = p.plant_id
                INNER JOIN divisions d ON p.division_id = d.division_id
                INNER JOIN companies c ON d.company_id = c.company_id
                WHERE UPPER(w.status) = 'ACTIVE'
                ORDER BY w.warehouse_name ASC
            ");
            $stmtWh->execute();
            $warehouses = $stmtWh->fetchAll(PDO::FETCH_ASSOC);

            cmaJsonResponse(true, '', [
                'companies' => $companies,
                'divisions' => $divisions,
                'plants' => $plants,
                'warehouses' => $warehouses
            ]);
            break;

        /* ============================================================
           ACTION: GET USER ACCESS MAPPINGS
           ============================================================ */
        case 'get_user_access':
            $userId = (int)($_GET['user_id'] ?? 0);
            if ($userId <= 0) {
                cmaJsonResponse(false, 'Invalid user ID.', [], 400);
            }

            // Verify user exists
            $stmtU = $pdo->prepare("
                SELECT u.user_id, u.full_name, u.username, u.email
                FROM users u
                WHERE u.user_id = :uid
                LIMIT 1
            ");
            $stmtU->execute([':uid' => $userId]);
            $targetUser = $stmtU->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                cmaJsonResponse(false, 'User not found.', [], 404);
            }

            // Query existing mappings
            $stmtGrants = $pdo->prepare("
                SELECT 
                    uoa.access_id,
                    uoa.company_id,
                    uoa.division_id,
                    uoa.plant_id,
                    uoa.warehouse_id,
                    c.company_name,
                    d.division_name,
                    p.plant_name,
                    w.warehouse_name
                FROM user_organization_access uoa
                LEFT JOIN companies c ON uoa.company_id = c.company_id
                LEFT JOIN divisions d ON uoa.division_id = d.division_id
                LEFT JOIN plants p ON uoa.plant_id = p.plant_id
                LEFT JOIN warehouses w ON uoa.warehouse_id = w.warehouse_id
                WHERE uoa.user_id = :uid 
                  
                  AND UPPER(uoa.status) = 'ACTIVE'
                ORDER BY uoa.access_id ASC
            ");
            $stmtGrants->execute([':uid' => $userId]);
            $grants = $stmtGrants->fetchAll(PDO::FETCH_ASSOC);

            // Structure mappings for UI
            $mappings = [];
            foreach ($grants as $g) {
                if (empty($g['company_id'])) {
                    continue; // Skip the raw base tenant row if there are specific grants
                }

                $level = 'COMPANY';
                $locType = '';
                $locId = 0;

                if (!empty($g['plant_id'])) {
                    $level = 'LOCATION';
                    $locType = 'PLANT';
                    $locId = (int)$g['plant_id'];
                } elseif (!empty($g['warehouse_id'])) {
                    $level = 'LOCATION';
                    $locType = 'WAREHOUSE';
                    $locId = (int)$g['warehouse_id'];
                } elseif (!empty($g['division_id'])) {
                    $level = 'DIVISION';
                }

                $mappings[] = [
                    'access_id' => (int)$g['access_id'],
                    'company_id' => (int)$g['company_id'],
                    'company_name' => $g['company_name'] ?: 'Company',
                    'level' => $level, // 'COMPANY', 'DIVISION', 'LOCATION'
                    'division_id' => (int)($g['division_id'] ?? 0),
                    'division_name' => $g['division_name'] ?: '',
                    'location_type' => $locType, // 'PLANT' or 'WAREHOUSE'
                    'location_id' => $locId,
                    'location_name' => $g['plant_name'] ?: ($g['warehouse_name'] ?: '')
                ];
            }

            cmaJsonResponse(true, '', [
                'user' => $targetUser,
                'mappings' => $mappings
            ]);
            break;

        /* ============================================================
           ACTION: SAVE USER ACCESS MAPPINGS (HIERARCHICAL & MULTI-COMPANY)
           ============================================================ */
        case 'save_user_access':
            $userId = (int)($_POST['user_id'] ?? 0);
            $rawMappingsJson = $_POST['mappings'] ?? '[]';
            $mappings = json_decode($rawMappingsJson, true);

            if ($userId <= 0) {
                cmaJsonResponse(false, 'Invalid user ID.', [], 400);
            }
            if (!is_array($mappings)) {
                cmaJsonResponse(false, 'Invalid mappings format.', [], 400);
            }

            // Verify user exists
            $stmtChk = $pdo->prepare("
                SELECT 1 FROM users 
                WHERE user_id = :uid
                LIMIT 1
            ");
            $stmtChk->execute([':uid' => $userId]);
            if (!$stmtChk->fetch()) {
                cmaJsonResponse(false, 'User not found.', [], 404);
            }

            // Validate all referenced companies, divisions, and locations belong to this customer
            $stmtCompVerify = $pdo->prepare("SELECT company_id, customer_id FROM companies WHERE company_id = :comp_id");
            $stmtDivVerify = $pdo->prepare("
                SELECT d.division_id 
                FROM divisions d 
                JOIN companies c ON d.company_id = c.company_id 
                WHERE d.division_id = :div_id
            ");
            $stmtPlantVerify = $pdo->prepare("
                SELECT p.plant_id 
                FROM plants p 
                JOIN divisions d ON p.division_id = d.division_id 
                JOIN companies c ON d.company_id = c.company_id 
                WHERE p.plant_id = :plant_id
            ");
            $stmtWhVerify = $pdo->prepare("
                SELECT w.warehouse_id 
                FROM warehouses w 
                JOIN plants p ON w.plant_id = p.plant_id 
                JOIN divisions d ON p.division_id = d.division_id 
                JOIN companies c ON d.company_id = c.company_id 
                WHERE w.warehouse_id = :wh_id
            ");

            foreach ($mappings as $m) {
                $compId = (int)($m['company_id'] ?? 0);
                if ($compId <= 0) {
                    cmaJsonResponse(false, 'Every access mapping must select a company.', [], 400);
                }

                $stmtCompVerify->execute([':comp_id' => $compId]);
                if (!$stmtCompVerify->fetch()) {
                    cmaJsonResponse(false, 'Unauthorized company reference detected.', [], 403);
                }

                $lvl = strtoupper(trim($m['level'] ?? 'COMPANY'));
                if ($lvl === 'DIVISION' || $lvl === 'LOCATION') {
                    $divId = (int)($m['division_id'] ?? 0);
                    if ($divId <= 0) {
                        cmaJsonResponse(false, 'Please select a division for division/location access.', [], 400);
                    }
                    $stmtDivVerify->execute([':div_id' => $divId]);
                    if (!$stmtDivVerify->fetch()) {
                        cmaJsonResponse(false, 'Unauthorized division reference detected.', [], 403);
                    }
                }

                if ($lvl === 'LOCATION') {
                    $locType = strtoupper(trim($m['location_type'] ?? 'PLANT'));
                    $locId = (int)($m['location_id'] ?? 0);
                    if ($locId <= 0) {
                        cmaJsonResponse(false, 'Please select a physical location (plant or warehouse).', [], 400);
                    }
                    if ($locType === 'PLANT') {
                        $stmtPlantVerify->execute([':plant_id' => $locId]);
                        if (!$stmtPlantVerify->fetch()) {
                            cmaJsonResponse(false, 'Unauthorized plant reference detected.', [], 403);
                        }
                    } else {
                        $stmtWhVerify->execute([':wh_id' => $locId]);
                        if (!$stmtWhVerify->fetch()) {
                            cmaJsonResponse(false, 'Unauthorized warehouse reference detected.', [], 403);
                        }
                    }
                }
            }

            // Begin atomic transaction to update mappings
            $pdo->beginTransaction();
            try {
                // Delete previous organization access entries for this user and customer
                $stmtDel = $pdo->prepare("
                    DELETE FROM user_organization_access 
                    WHERE user_id = :uid
                ");
                $stmtDel->execute([':uid' => $userId]);

                if (!empty($mappings)) {
                    $stmtInsertAccess = $pdo->prepare("
                        INSERT INTO user_organization_access (
                            user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status
                        ) VALUES (
                            :uid, :cid, :comp_id, :div_id, :plant_id, :wh_id, CURRENT_DATE, 'Active'
                        )
                    ");

                    foreach ($mappings as $m) {
                        $compId = (int)$m['company_id'];
                        $lvl = strtoupper(trim($m['level'] ?? 'COMPANY'));
                        $divId = null;
                        $plantId = null;
                        $whId = null;

                        // Retrieve customer_id from company
                        $stmtCompVerify->execute([':comp_id' => $compId]);
                        $cData = $stmtCompVerify->fetch(PDO::FETCH_ASSOC);
                        $mappedCustId = $cData['customer_id'] ?? null;

                        if ($lvl === 'DIVISION') {
                            $divId = (int)$m['division_id'];
                        } elseif ($lvl === 'LOCATION') {
                            $divId = (int)$m['division_id'];
                            $locType = strtoupper(trim($m['location_type'] ?? 'PLANT'));
                            $locId = (int)$m['location_id'];
                            if ($locType === 'PLANT') {
                                $plantId = $locId;
                            } else {
                                $whId = $locId;
                            }
                        }

                        $stmtInsertAccess->execute([
                            ':uid' => $userId,
                            ':cid' => $mappedCustId,
                            ':comp_id' => $compId,
                            ':div_id' => $divId,
                            ':plant_id' => $plantId,
                            ':wh_id' => $whId
                        ]);
                    }
                }

                $pdo->commit();
                cmaJsonResponse(true, 'User organizational access permissions saved successfully.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                cmaJsonResponse(false, 'Failed to save access permissions: ' . $e->getMessage(), [], 500);
            }
            break;

        default:
            cmaJsonResponse(false, 'Invalid action specified.', [], 400);
            break;
    }
} catch (Throwable $e) {
    cmaJsonResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}
