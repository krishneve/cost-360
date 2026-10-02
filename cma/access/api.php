<?php
/**
 * Cost360 - User Access Management & Effective Access API
 * Phases 9-13: Hierarchical Access Control (Company -> Division -> Plant),
 * Dynamic Effective Access Calculation, Scoped Validation & Audit Trail.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

function accessJsonResponse(bool $success, string $message = '', array $data = [], int $httpCode = 200): void {
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
    accessJsonResponse(false, 'Authentication required.', [], 401);
}

$currentUser = getAuthUser();
$currentUserId = (int)($currentUser['user_id'] ?? 0);
$isPlatform = isPlatformAdmin($currentUserId);
$isCma = isCmaAdmin($currentUserId);

if (!$isPlatform && !$isCma) {
    accessJsonResponse(false, 'Unauthorized. Access restricted to Administrators.', [], 403);
}

$pdo = getDbConnection();

// Determine Customer Scope (Access Isolation)
$scopedCustomerId = null;
if ($isCma) {
    $scopedCustomerId = getCmaAdminCustomerId($currentUserId);
    if (!$scopedCustomerId) {
        accessJsonResponse(false, 'No active customer organization found for this CMA Admin.', [], 403);
    }
} else {
    // Platform Admin can specify customer_id or default to active
    $scopedCustomerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? ($_SESSION['active_customer_id'] ?? 0));
    if ($scopedCustomerId <= 0 && isset($_SESSION['active_customer_id']) && is_numeric($_SESSION['active_customer_id'])) {
        $scopedCustomerId = (int)$_SESSION['active_customer_id'];
    }
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? 'list');

try {
    switch ($action) {

        /* ============================================================
           ACTION: LIST USER ACCESS MAPPINGS
           ============================================================ */
        case 'list':
            $userIdFilter = (int)($_GET['user_id'] ?? 0);
            $companyFilter = (int)($_GET['company_id'] ?? 0);
            $levelFilter = strtoupper(trim($_GET['access_level'] ?? ''));

            $sql = "
                SELECT 
                    uoa.access_id,
                    uoa.user_id,
                    u.username,
                    u.first_name,
                    u.last_name,
                    u.full_name,
                    u.email,
                    r.role_code,
                    r.role_name,
                    uoa.customer_id,
                    cust.customer_name,
                    uoa.company_id,
                    c.company_code,
                    c.company_name,
                    uoa.division_id,
                    d.division_code,
                    d.division_name,
                    uoa.plant_id,
                    p.plant_code,
                    p.plant_name,
                    uoa.valid_from,
                    uoa.valid_to,
                    uoa.status,
                    CASE 
                        WHEN uoa.plant_id IS NOT NULL THEN 'PLANT'
                        WHEN uoa.division_id IS NOT NULL THEN 'DIVISION'
                        WHEN uoa.company_id IS NOT NULL THEN 'COMPANY'
                        ELSE 'CUSTOMER'
                    END AS calculated_level
                FROM user_organization_access uoa
                INNER JOIN users u ON u.user_id = uoa.user_id
                LEFT JOIN LATERAL (
                    SELECT ur.role_id FROM user_roles ur WHERE ur.user_id = u.user_id AND ur.status = 'Active' ORDER BY ur.user_role_id DESC LIMIT 1
                ) cur_role ON TRUE
                LEFT JOIN roles r ON r.role_id = cur_role.role_id
                LEFT JOIN customers cust ON cust.customer_id = uoa.customer_id
                LEFT JOIN companies c ON c.company_id = uoa.company_id
                LEFT JOIN divisions d ON d.division_id = uoa.division_id
                LEFT JOIN plants p ON p.plant_id = uoa.plant_id
                WHERE UPPER(uoa.status) = 'ACTIVE'
            ";

            $params = [];

            if ($scopedCustomerId && $scopedCustomerId > 0) {
                $sql .= " AND (uoa.customer_id = :cust_id OR c.customer_id = :cust_id)";
                $params[':cust_id'] = $scopedCustomerId;
            }

            if ($userIdFilter > 0) {
                $sql .= " AND uoa.user_id = :user_id";
                $params[':user_id'] = $userIdFilter;
            }

            if ($companyFilter > 0) {
                $sql .= " AND uoa.company_id = :comp_id";
                $params[':comp_id'] = $companyFilter;
            }

            if ($levelFilter !== '' && $levelFilter !== 'ALL') {
                if ($levelFilter === 'PLANT') {
                    $sql .= " AND uoa.plant_id IS NOT NULL";
                } elseif ($levelFilter === 'DIVISION') {
                    $sql .= " AND uoa.division_id IS NOT NULL AND uoa.plant_id IS NULL";
                } elseif ($levelFilter === 'COMPANY') {
                    $sql .= " AND uoa.company_id IS NOT NULL AND uoa.division_id IS NULL AND uoa.plant_id IS NULL";
                }
            }

            $sql .= " ORDER BY u.full_name ASC, uoa.access_id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // KPIs
            $uniqueUsers = [];
            $uniqueCompanies = [];
            $uniquePlants = [];

            foreach ($mappings as $m) {
                $uniqueUsers[$m['user_id']] = true;
                if (!empty($m['company_id'])) $uniqueCompanies[$m['company_id']] = true;
                if (!empty($m['plant_id'])) $uniquePlants[$m['plant_id']] = true;
            }

            accessJsonResponse(true, '', [
                'mappings' => $mappings,
                'kpis' => [
                    'total_mappings' => count($mappings),
                    'users_count' => count($uniqueUsers),
                    'companies_count' => count($uniqueCompanies),
                    'plants_count' => count($uniquePlants)
                ]
            ]);
            break;

        /* ============================================================
           ACTION: GET SCOPED USERS (FOR USER DROPDOWN)
           ============================================================ */
        case 'get_users':
            $sql = "
                SELECT DISTINCT u.user_id, u.username, u.full_name, u.email, r.role_name, r.role_code
                FROM users u
                INNER JOIN user_organization_access uoa ON uoa.user_id = u.user_id
                LEFT JOIN LATERAL (
                    SELECT ur.role_id FROM user_roles ur WHERE ur.user_id = u.user_id AND ur.status = 'Active' ORDER BY ur.user_role_id DESC LIMIT 1
                ) cur_role ON TRUE
                LEFT JOIN roles r ON r.role_id = cur_role.role_id
                WHERE UPPER(u.status) = 'ACTIVE'
            ";
            $params = [];

            if ($scopedCustomerId && $scopedCustomerId > 0) {
                $sql .= " AND uoa.customer_id = :cid";
                $params[':cid'] = $scopedCustomerId;
            }

            $sql .= " ORDER BY u.full_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            accessJsonResponse(true, '', ['users' => $users]);
            break;

        /* ============================================================
           ACTION: GET SCOPED COMPANIES
           ============================================================ */
        case 'get_companies':
            $sql = "
                SELECT company_id, company_code, company_name, customer_id
                FROM companies
                WHERE UPPER(status) = 'ACTIVE'
            ";
            $params = [];

            if ($scopedCustomerId && $scopedCustomerId > 0) {
                $sql .= " AND customer_id = :cid";
                $params[':cid'] = $scopedCustomerId;
            }

            $sql .= " ORDER BY company_name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            accessJsonResponse(true, '', ['companies' => $companies]);
            break;

        /* ============================================================
           ACTION: GET DIVISIONS FOR SELECTED COMPANY
           ============================================================ */
        case 'get_divisions':
            $companyId = (int)($_GET['company_id'] ?? 0);
            if ($companyId <= 0) {
                accessJsonResponse(true, '', ['divisions' => []]);
            }

            // Verify company belongs to scoped customer
            if ($scopedCustomerId && $scopedCustomerId > 0) {
                $stmtCheck = $pdo->prepare("SELECT 1 FROM companies WHERE company_id = :cid AND customer_id = :cust_id");
                $stmtCheck->execute([':cid' => $companyId, ':cust_id' => $scopedCustomerId]);
                if (!$stmtCheck->fetch()) {
                    accessJsonResponse(false, 'Access denied to this company.', [], 403);
                }
            }

            $stmt = $pdo->prepare("
                SELECT division_id, company_id, division_code, division_name 
                FROM divisions 
                WHERE company_id = :cid AND UPPER(status) = 'ACTIVE' 
                ORDER BY division_name ASC
            ");
            $stmt->execute([':cid' => $companyId]);
            $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            accessJsonResponse(true, '', ['divisions' => $divisions]);
            break;

        /* ============================================================
           ACTION: GET PLANTS FOR SELECTED DIVISION
           ============================================================ */
        case 'get_plants':
            $divisionId = (int)($_GET['division_id'] ?? 0);
            if ($divisionId <= 0) {
                accessJsonResponse(true, '', ['plants' => []]);
            }

            // Verify division belongs to customer
            if ($scopedCustomerId && $scopedCustomerId > 0) {
                $stmtCheck = $pdo->prepare("
                    SELECT 1 FROM divisions d 
                    JOIN companies c ON c.company_id = d.company_id 
                    WHERE d.division_id = :did AND c.customer_id = :cust_id
                ");
                $stmtCheck->execute([':did' => $divisionId, ':cust_id' => $scopedCustomerId]);
                if (!$stmtCheck->fetch()) {
                    accessJsonResponse(false, 'Access denied to this division.', [], 403);
                }
            }

            $stmt = $pdo->prepare("
                SELECT plant_id, division_id, plant_code, plant_name, location 
                FROM plants 
                WHERE division_id = :did AND UPPER(status) = 'ACTIVE' 
                ORDER BY plant_name ASC
            ");
            $stmt->execute([':did' => $divisionId]);
            $plants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            accessJsonResponse(true, '', ['plants' => $plants]);
            break;

        /* ============================================================
           ACTION: CALCULATE EFFECTIVE ACCESS (PHASE 10 & 11)
           ============================================================ */
        case 'get_effective_access':
            $targetUserId = (int)($_GET['user_id'] ?? 0);
            if ($targetUserId <= 0) {
                accessJsonResponse(false, 'Invalid user ID.', [], 400);
            }

            // Verify target user belongs to scoped customer
            if ($scopedCustomerId && $scopedCustomerId > 0) {
                $stmtUserCheck = $pdo->prepare("
                    SELECT 1 FROM user_organization_access 
                    WHERE user_id = :uid AND customer_id = :cid AND UPPER(status) = 'ACTIVE'
                ");
                $stmtUserCheck->execute([':uid' => $targetUserId, ':cid' => $scopedCustomerId]);
                if (!$stmtUserCheck->fetch() && !isPlatformAdmin($targetUserId)) {
                    // Check if user is self
                    if ($targetUserId !== $currentUserId) {
                        accessJsonResponse(false, 'User does not belong to your customer organization.', [], 403);
                    }
                }
            }

            // 1. Fetch user's active access records
            $stmtAccess = $pdo->prepare("
                SELECT access_id, customer_id, company_id, division_id, plant_id,
                       CASE 
                           WHEN plant_id IS NOT NULL THEN 'PLANT'
                           WHEN division_id IS NOT NULL THEN 'DIVISION'
                           WHEN company_id IS NOT NULL THEN 'COMPANY'
                           ELSE 'CUSTOMER'
                       END as level
                FROM user_organization_access
                WHERE user_id = :uid AND UPPER(status) = 'ACTIVE'
                  AND CURRENT_DATE >= valid_from
                  AND (valid_to IS NULL OR CURRENT_DATE <= valid_to)
            ");
            $stmtAccess->execute([':uid' => $targetUserId]);
            $accessRecords = $stmtAccess->fetchAll(PDO::FETCH_ASSOC);

            // Fetch target user info
            $stmtUser = $pdo->prepare("SELECT user_id, full_name, email, username FROM users WHERE user_id = :uid");
            $stmtUser->execute([':uid' => $targetUserId]);
            $userInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);

            // 2. Fetch full hierarchy of companies, divisions, plants for customer
            $sqlHierarchy = "
                SELECT 
                    c.company_id, c.company_name, c.company_code,
                    d.division_id, d.division_name, d.division_code,
                    p.plant_id, p.plant_name, p.plant_code
                FROM companies c
                LEFT JOIN divisions d ON d.company_id = c.company_id AND UPPER(d.status) = 'ACTIVE'
                LEFT JOIN plants p ON p.division_id = d.division_id AND UPPER(p.status) = 'ACTIVE'
                WHERE UPPER(c.status) = 'ACTIVE'
            ";
            $hParams = [];
            if ($scopedCustomerId && $scopedCustomerId > 0) {
                $sqlHierarchy .= " AND c.customer_id = :cid";
                $hParams[':cid'] = $scopedCustomerId;
            }
            $sqlHierarchy .= " ORDER BY c.company_name ASC, d.division_name ASC, p.plant_name ASC";
            $stmtH = $pdo->prepare($sqlHierarchy);
            $stmtH->execute($hParams);
            $hierarchyRows = $stmtH->fetchAll(PDO::FETCH_ASSOC);

            // Check if user has global customer access
            $hasCustomerScope = false;
            $allowedCompanyIds = [];
            $allowedDivisionIds = [];
            $allowedPlantIds = [];

            foreach ($accessRecords as $rec) {
                if ($rec['level'] === 'CUSTOMER') {
                    $hasCustomerScope = true;
                } elseif ($rec['level'] === 'COMPANY' && !empty($rec['company_id'])) {
                    $allowedCompanyIds[(int)$rec['company_id']] = true;
                } elseif ($rec['level'] === 'DIVISION' && !empty($rec['division_id'])) {
                    $allowedDivisionIds[(int)$rec['division_id']] = true;
                } elseif ($rec['level'] === 'PLANT' && !empty($rec['plant_id'])) {
                    $allowedPlantIds[(int)$rec['plant_id']] = true;
                }
            }

            // Build hierarchical effective tree
            $effectiveTree = [];
            $treeTextLines = [];

            // Group by company -> division -> plant
            $companyMap = [];
            foreach ($hierarchyRows as $row) {
                $compId = (int)$row['company_id'];
                $divId = $row['division_id'] ? (int)$row['division_id'] : null;
                $plantId = $row['plant_id'] ? (int)$row['plant_id'] : null;

                // Determine if this row is accessible under inheritance rules:
                // Rule 1: Customer scope gives access to everything
                // Rule 2: Company scope gives access to all divisions and plants in company
                // Rule 3: Division scope gives access to all plants under that division
                // Rule 4: Plant scope gives access only to that plant
                $isPlantAccessible = false;
                $isDivisionAccessible = false;
                $isCompanyAccessible = false;

                if ($hasCustomerScope || isset($allowedCompanyIds[$compId])) {
                    $isCompanyAccessible = true;
                    $isDivisionAccessible = true;
                    $isPlantAccessible = true;
                } elseif ($divId && isset($allowedDivisionIds[$divId])) {
                    $isCompanyAccessible = true;
                    $isDivisionAccessible = true;
                    $isPlantAccessible = true;
                } elseif ($plantId && isset($allowedPlantIds[$plantId])) {
                    $isCompanyAccessible = true;
                    $isDivisionAccessible = true;
                    $isPlantAccessible = true;
                }

                if (!$isCompanyAccessible) {
                    continue;
                }

                if (!isset($companyMap[$compId])) {
                    $compInherited = !isset($allowedCompanyIds[$compId]) && !$hasCustomerScope;
                    $companyMap[$compId] = [
                        'company_id' => $compId,
                        'company_code' => $row['company_code'],
                        'company_name' => $row['company_name'],
                        'inherited' => $compInherited,
                        'divisions' => []
                    ];
                }

                if ($divId && $isDivisionAccessible) {
                    if (!isset($companyMap[$compId]['divisions'][$divId])) {
                        $divInherited = !isset($allowedDivisionIds[$divId]) && ($hasCustomerScope || isset($allowedCompanyIds[$compId]));
                        $companyMap[$compId]['divisions'][$divId] = [
                            'division_id' => $divId,
                            'division_code' => $row['division_code'],
                            'division_name' => $row['division_name'],
                            'inherited' => $divInherited,
                            'plants' => []
                        ];
                    }

                    if ($plantId && $isPlantAccessible) {
                        $plantInherited = !isset($allowedPlantIds[$plantId]);
                        $companyMap[$compId]['divisions'][$divId]['plants'][$plantId] = [
                            'plant_id' => $plantId,
                            'plant_code' => $row['plant_code'],
                            'plant_name' => $row['plant_name'],
                            'inherited' => $plantInherited
                        ];
                    }
                }
            }

            // Format formatted ASCII tree and structured data
            $formattedTree = [];
            $asciiLines = [];

            if (empty($companyMap)) {
                $asciiLines[] = "No accessible organizational entities assigned.";
            } else {
                foreach ($companyMap as $c) {
                    $cLine = $c['company_name'] . ($c['inherited'] ? " (Inherited)" : " [Company Access]");
                    $asciiLines[] = $cLine;
                    $divCount = count($c['divisions']);
                    $dIdx = 0;

                    $cData = [
                        'company_id' => $c['company_id'],
                        'company_name' => $c['company_name'],
                        'inherited' => $c['inherited'],
                        'divisions' => []
                    ];

                    foreach ($c['divisions'] as $d) {
                        $dIdx++;
                        $isLastDiv = ($dIdx === $divCount);
                        $dBranch = $isLastDiv ? "└── " : "├── ";
                        $dPrefix = $isLastDiv ? "    " : "│   ";
                        $dLine = $dBranch . $d['division_name'] . ($d['inherited'] ? " (Inherited)" : " [Division Access]");
                        $asciiLines[] = $dLine;

                        $plantCount = count($d['plants']);
                        $pIdx = 0;
                        $dData = [
                            'division_id' => $d['division_id'],
                            'division_name' => $d['division_name'],
                            'inherited' => $d['inherited'],
                            'plants' => []
                        ];

                        foreach ($d['plants'] as $p) {
                            $pIdx++;
                            $isLastPlant = ($pIdx === $plantCount);
                            $pBranch = $isLastPlant ? "└── " : "├── ";
                            $pLine = $dPrefix . $pBranch . $p['plant_name'] . ($p['inherited'] ? " (Inherited)" : " [Plant Access]");
                            $asciiLines[] = $pLine;

                            $dData['plants'][] = [
                                'plant_id' => $p['plant_id'],
                                'plant_name' => $p['plant_name'],
                                'inherited' => $p['inherited']
                            ];
                        }
                        $cData['divisions'][] = $dData;
                    }
                    $formattedTree[] = $cData;
                }
            }

            accessJsonResponse(true, '', [
                'user' => $userInfo,
                'tree' => $formattedTree,
                'ascii_preview' => implode("\n", $asciiLines),
                'direct_assignments' => $accessRecords
            ]);
            break;

        /* ============================================================
           ACTION: GET USER ACCESS CONFIG (FOR MANAGE ACCESS MODAL)
           ============================================================ */
        case 'get_user_access_config':
            $targetUserId = (int)($_GET['user_id'] ?? 0);
            if ($targetUserId <= 0) {
                accessJsonResponse(false, 'Invalid user ID.', [], 400);
            }

            // Fetch target user info
            $stmtUser = $pdo->prepare("SELECT user_id, full_name, email, username FROM users WHERE user_id = :uid");
            $stmtUser->execute([':uid' => $targetUserId]);
            $userInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);
            if (!$userInfo) {
                accessJsonResponse(false, 'User not found.', [], 404);
            }

            // Fetch active mappings
            $stmtMappings = $pdo->prepare("
                SELECT access_id, customer_id, company_id, division_id, plant_id,
                       CASE 
                           WHEN plant_id IS NOT NULL THEN 'PLANT'
                           WHEN division_id IS NOT NULL THEN 'DIVISION'
                           WHEN company_id IS NOT NULL THEN 'COMPANY'
                           ELSE 'CUSTOMER'
                       END as access_level
                FROM user_organization_access
                WHERE user_id = :uid AND UPPER(status) = 'ACTIVE'
            ");
            $stmtMappings->execute([':uid' => $targetUserId]);
            $rawMappings = $stmtMappings->fetchAll(PDO::FETCH_ASSOC);

            $accessMode = 'SELECTED';
            foreach ($rawMappings as $m) {
                if ($m['access_level'] === 'CUSTOMER') {
                    $accessMode = 'FULL';
                    break;
                }
            }

            // Fetch full company hierarchy under customer scope
            $sqlComp = "
                SELECT c.company_id, c.company_code, c.company_name,
                       d.division_id, d.division_code, d.division_name,
                       p.plant_id, p.plant_code, p.plant_name
                FROM companies c
                LEFT JOIN divisions d ON d.company_id = c.company_id AND UPPER(d.status) = 'ACTIVE'
                LEFT JOIN plants p ON p.division_id = d.division_id AND UPPER(p.status) = 'ACTIVE'
                WHERE UPPER(c.status) = 'ACTIVE'
            ";
            $cParams = [];
            if ($scopedCustomerId && $scopedCustomerId > 0) {
                $sqlComp .= " AND c.customer_id = :cid";
                $cParams[':cid'] = $scopedCustomerId;
            }
            $sqlComp .= " ORDER BY c.company_name ASC, d.division_name ASC, p.plant_name ASC";
            $stmtC = $pdo->prepare($sqlComp);
            $stmtC->execute($cParams);
            $rows = $stmtC->fetchAll(PDO::FETCH_ASSOC);

            $companyMap = [];
            foreach ($rows as $r) {
                $cid = (int)$r['company_id'];
                if (!isset($companyMap[$cid])) {
                    $companyMap[$cid] = [
                        'company_id' => $cid,
                        'company_code' => $r['company_code'],
                        'company_name' => $r['company_name'],
                        'divisions' => []
                    ];
                }
                if (!empty($r['division_id'])) {
                    $did = (int)$r['division_id'];
                    if (!isset($companyMap[$cid]['divisions'][$did])) {
                        $companyMap[$cid]['divisions'][$did] = [
                            'division_id' => $did,
                            'division_code' => $r['division_code'],
                            'division_name' => $r['division_name'],
                            'plants' => []
                        ];
                    }
                    if (!empty($r['plant_id'])) {
                        $pid = (int)$r['plant_id'];
                        $companyMap[$cid]['divisions'][$did]['plants'][$pid] = [
                            'plant_id' => $pid,
                            'plant_code' => $r['plant_code'],
                            'plant_name' => $r['plant_name']
                        ];
                    }
                }
            }

            // Convert map to indexed arrays
            $companiesList = [];
            foreach ($companyMap as $c) {
                $divList = [];
                foreach ($c['divisions'] as $d) {
                    $d['plants'] = array_values($d['plants']);
                    $divList[] = $d;
                }
                $c['divisions'] = $divList;
                $companiesList[] = $c;
            }

            accessJsonResponse(true, '', [
                'user' => $userInfo,
                'access_mode' => $accessMode,
                'mappings' => $rawMappings,
                'companies' => $companiesList
            ]);
            break;


        /* ============================================================
           ACTION: SAVE USER ACCESS MAPPING (PHASE 12 & 13 VALIDATION)
           ============================================================ */
        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                accessJsonResponse(false, 'Method not allowed.', [], 405);
            }

            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $accessLevel = strtoupper(trim($_POST['access_level'] ?? ''));
            $companyId = (int)($_POST['company_id'] ?? 0);
            $divisionId = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
            $plantId = !empty($_POST['plant_id']) ? (int)$_POST['plant_id'] : null;

            // 1. Validation
            if ($targetUserId <= 0) {
                accessJsonResponse(false, 'Please select a valid user.', [], 400);
            }
            if (!in_array($accessLevel, ['COMPANY', 'DIVISION', 'PLANT'], true)) {
                accessJsonResponse(false, 'Please select a valid Access Level (Company, Division, or Plant).', [], 400);
            }
            if ($companyId <= 0) {
                accessJsonResponse(false, 'Company selection is required.', [], 400);
            }

            // 2. Validate Company exists and belongs to scoped customer
            $stmtC = $pdo->prepare("SELECT company_id, customer_id, company_name FROM companies WHERE company_id = :cid AND UPPER(status) = 'ACTIVE'");
            $stmtC->execute([':cid' => $companyId]);
            $comp = $stmtC->fetch(PDO::FETCH_ASSOC);
            if (!$comp) {
                accessJsonResponse(false, 'Selected company does not exist or is inactive.', [], 400);
            }
            $targetCustomerId = (int)$comp['customer_id'];

            if ($scopedCustomerId && $scopedCustomerId > 0 && $targetCustomerId !== $scopedCustomerId) {
                accessJsonResponse(false, 'Access violation: Company does not belong to your customer organization.', [], 403);
            }

            // 3. Hierarchy Validation (Phase 13)
            if ($accessLevel === 'DIVISION') {
                if (!$divisionId || $divisionId <= 0) {
                    accessJsonResponse(false, 'Division selection is required for Division-level access.', [], 400);
                }
                $stmtD = $pdo->prepare("SELECT division_id, company_id, division_name FROM divisions WHERE division_id = :did AND UPPER(status) = 'ACTIVE'");
                $stmtD->execute([':did' => $divisionId]);
                $div = $stmtD->fetch(PDO::FETCH_ASSOC);
                if (!$div) {
                    accessJsonResponse(false, 'Selected division does not exist or is inactive.', [], 400);
                }
                if ((int)$div['company_id'] !== $companyId) {
                    accessJsonResponse(false, "Hierarchy validation error: Division '{$div['division_name']}' does not belong to the selected company.", [], 400);
                }
                $plantId = null; // Division level access does not bind to a single plant
            } elseif ($accessLevel === 'PLANT') {
                if (!$divisionId || $divisionId <= 0) {
                    accessJsonResponse(false, 'Division selection is required for Plant-level access.', [], 400);
                }
                if (!$plantId || $plantId <= 0) {
                    accessJsonResponse(false, 'Plant selection is required for Plant-level access.', [], 400);
                }
                // Verify division belongs to company
                $stmtD = $pdo->prepare("SELECT division_id, company_id, division_name FROM divisions WHERE division_id = :did AND UPPER(status) = 'ACTIVE'");
                $stmtD->execute([':did' => $divisionId]);
                $div = $stmtD->fetch(PDO::FETCH_ASSOC);
                if (!$div || (int)$div['company_id'] !== $companyId) {
                    accessJsonResponse(false, "Hierarchy validation error: Division does not belong to selected company.", [], 400);
                }

                // Verify plant belongs to division
                $stmtP = $pdo->prepare("SELECT plant_id, division_id, plant_name FROM plants WHERE plant_id = :pid AND UPPER(status) = 'ACTIVE'");
                $stmtP->execute([':pid' => $plantId]);
                $plant = $stmtP->fetch(PDO::FETCH_ASSOC);
                if (!$plant) {
                    accessJsonResponse(false, 'Selected plant does not exist or is inactive.', [], 400);
                }
                if ((int)$plant['division_id'] !== $divisionId) {
                    accessJsonResponse(false, "Hierarchy validation error: Plant '{$plant['plant_name']}' does not belong to Division '{$div['division_name']}'.", [], 400);
                }
            } else {
                // COMPANY level
                $divisionId = null;
                $plantId = null;
            }

            // Verify target user belongs to customer
            $stmtUserCust = $pdo->prepare("
                SELECT 1 FROM user_organization_access 
                WHERE user_id = :uid AND customer_id = :cid AND UPPER(status) = 'ACTIVE'
            ");
            $stmtUserCust->execute([':uid' => $targetUserId, ':cid' => $targetCustomerId]);
            if (!$stmtUserCust->fetch() && !isPlatformAdmin($targetUserId)) {
                // Also check if user was created under this customer
                $stmtChkUser = $pdo->prepare("SELECT user_id, full_name FROM users WHERE user_id = :uid");
                $stmtChkUser->execute([':uid' => $targetUserId]);
                if (!$stmtChkUser->fetch()) {
                    accessJsonResponse(false, 'Target user not found.', [], 404);
                }
            }

            $pdo->beginTransaction();

            // Check if exact same mapping already exists
            $stmtChkExact = $pdo->prepare("
                SELECT access_id FROM user_organization_access 
                WHERE user_id = :uid AND customer_id = :cid 
                  AND (company_id = :comp_id OR (company_id IS NULL AND :comp_id IS NULL))
                  AND (division_id = :div_id OR (division_id IS NULL AND :div_id IS NULL))
                  AND (plant_id = :plant_id OR (plant_id IS NULL AND :plant_id IS NULL))
                  AND UPPER(status) = 'ACTIVE'
                LIMIT 1
            ");
            $stmtChkExact->execute([
                ':uid'      => $targetUserId,
                ':cid'      => $targetCustomerId,
                ':comp_id'  => $companyId,
                ':div_id'   => $divisionId,
                ':plant_id' => $plantId
            ]);
            $existingId = $stmtChkExact->fetchColumn();

            if ($existingId) {
                $pdo->rollBack();
                accessJsonResponse(true, 'This exact access mapping is already active for this user.', ['access_id' => (int)$existingId]);
            }

            // Insert access mapping
            $stmtInsert = $pdo->prepare("
                INSERT INTO user_organization_access (
                    user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status
                ) VALUES (
                    :uid, :cid, :comp_id, :div_id, :plant_id, NULL, CURRENT_DATE, 'Active'
                ) RETURNING access_id
            ");
            $stmtInsert->execute([
                ':uid'      => $targetUserId,
                ':cid'      => $targetCustomerId,
                ':comp_id'  => $companyId,
                ':div_id'   => $divisionId,
                ':plant_id' => $plantId
            ]);
            $newAccessId = (int)$stmtInsert->fetchColumn();

            // Audit Log
            $stmtAudit = $pdo->prepare("
                INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES (:uid, 'user_organization_access', :eid, 'USER_ACCESS_ASSIGNED', NULL, :new_val, CURRENT_TIMESTAMP, :ip)
            ");
            $stmtAudit->execute([
                ':uid'     => $currentUserId,
                ':eid'     => $newAccessId,
                ':new_val' => json_encode([
                    'access_id'    => $newAccessId,
                    'user_id'      => $targetUserId,
                    'customer_id'  => $targetCustomerId,
                    'access_level' => $accessLevel,
                    'company_id'   => $companyId,
                    'division_id'  => $divisionId,
                    'plant_id'     => $plantId
                ], JSON_UNESCAPED_UNICODE),
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $pdo->commit();

            accessJsonResponse(true, "{$accessLevel} access successfully assigned to user.", [
                'access_id' => $newAccessId
            ]);
            break;

        /* ============================================================
           ACTION: SAVE FULL USER ACCESS CONFIGURATION (FULL or SELECTED MULTI-COMPANY)
           ============================================================ */
        case 'save_user_access_config':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                accessJsonResponse(false, 'Method not allowed.', [], 405);
            }

            // Accept JSON or POST form data
            $rawInput = file_get_contents('php://input');
            $jsonData = json_decode($rawInput, true);

            $targetUserId = (int)($jsonData['user_id'] ?? $_POST['user_id'] ?? 0);
            $accessMode = strtoupper(trim($jsonData['access_mode'] ?? $_POST['access_mode'] ?? 'SELECTED'));
            $rawMappings = $jsonData['mappings'] ?? (isset($_POST['mappings']) ? json_decode($_POST['mappings'], true) : []);

            if ($targetUserId <= 0) {
                accessJsonResponse(false, 'Invalid user selected.', [], 400);
            }

            // Verify target user exists
            $stmtChkUser = $pdo->prepare("SELECT user_id, full_name, email FROM users WHERE user_id = :uid");
            $stmtChkUser->execute([':uid' => $targetUserId]);
            $targetUserObj = $stmtChkUser->fetch(PDO::FETCH_ASSOC);
            if (!$targetUserObj) {
                accessJsonResponse(false, 'Target user not found.', [], 404);
            }

            $effectiveCustId = $scopedCustomerId;
            if (!$effectiveCustId || $effectiveCustId <= 0) {
                // Fetch user's customer ID if platform admin didn't pass one
                $stmtUserCust = $pdo->prepare("SELECT customer_id FROM user_organization_access WHERE user_id = :uid AND customer_id IS NOT NULL LIMIT 1");
                $stmtUserCust->execute([':uid' => $targetUserId]);
                $effectiveCustId = (int)$stmtUserCust->fetchColumn();
            }

            if ($effectiveCustId <= 0) {
                accessJsonResponse(false, 'Unable to determine customer organization for this operation.', [], 400);
            }

            $pdo->beginTransaction();

            // Deactivate existing access records for this user
            $stmtDeactivate = $pdo->prepare("UPDATE user_organization_access SET status = 'Inactive' WHERE user_id = :uid AND UPPER(status) = 'ACTIVE'");
            $stmtDeactivate->execute([':uid' => $targetUserId]);

            $savedCount = 0;

            if ($accessMode === 'FULL') {
                // Insert full access record
                $stmtInsertFull = $pdo->prepare("
                    INSERT INTO user_organization_access (
                        user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status
                    ) VALUES (
                        :uid, :cid, NULL, NULL, NULL, NULL, CURRENT_DATE, 'Active'
                    )
                ");
                $stmtInsertFull->execute([':uid' => $targetUserId, ':cid' => $effectiveCustId]);
                $savedCount = 1;
            } else {
                // Selected organizations mapping
                if (!is_array($rawMappings) || empty($rawMappings)) {
                    $pdo->rollBack();
                    accessJsonResponse(false, 'Please select at least one organization for custom access mapping.', [], 400);
                }

                $stmtInsertMapping = $pdo->prepare("
                    INSERT INTO user_organization_access (
                        user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status
                    ) VALUES (
                        :uid, :cid, :comp_id, :div_id, :plant_id, NULL, CURRENT_DATE, 'Active'
                    )
                ");

                foreach ($rawMappings as $m) {
                    $compId = (int)($m['company_id'] ?? 0);
                    $level = strtoupper(trim($m['access_level'] ?? 'COMPANY'));
                    $divId = !empty($m['division_id']) ? (int)$m['division_id'] : null;
                    $plantId = !empty($m['plant_id']) ? (int)$m['plant_id'] : null;

                    if ($compId <= 0) continue;

                    // Verify company belongs to tenant
                    $stmtC = $pdo->prepare("SELECT company_id FROM companies WHERE company_id = :cid AND customer_id = :cust_id AND UPPER(status) = 'ACTIVE'");
                    $stmtC->execute([':cid' => $compId, ':cust_id' => $effectiveCustId]);
                    if (!$stmtC->fetch()) continue;

                    if ($level === 'DIVISION') {
                        if ($divId) {
                            $stmtD = $pdo->prepare("SELECT division_id FROM divisions WHERE division_id = :did AND company_id = :cid AND UPPER(status) = 'ACTIVE'");
                            $stmtD->execute([':did' => $divId, ':cid' => $compId]);
                            if (!$stmtD->fetch()) $divId = null;
                        }
                        $plantId = null;
                    } elseif ($level === 'PLANT') {
                        if ($divId && $plantId) {
                            $stmtP = $pdo->prepare("SELECT plant_id FROM plants WHERE plant_id = :pid AND division_id = :did AND UPPER(status) = 'ACTIVE'");
                            $stmtP->execute([':pid' => $plantId, ':did' => $divId]);
                            if (!$stmtP->fetch()) $plantId = null;
                        }
                    } else {
                        // COMPANY level
                        $divId = null;
                        $plantId = null;
                    }

                    $stmtInsertMapping->execute([
                        ':uid'      => $targetUserId,
                        ':cid'      => $effectiveCustId,
                        ':comp_id'  => $compId,
                        ':div_id'   => $divId,
                        ':plant_id' => $plantId
                    ]);
                    $savedCount++;
                }
            }

            // Audit
            $stmtAudit = $pdo->prepare("
                INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES (:uid, 'user_organization_access', :eid, 'USER_ACCESS_UPDATED', NULL, :new_val, CURRENT_TIMESTAMP, :ip)
            ");
            $stmtAudit->execute([
                ':uid'     => $currentUserId,
                ':eid'     => $targetUserId,
                ':new_val' => json_encode([
                    'user_id'     => $targetUserId,
                    'access_mode' => $accessMode,
                    'saved_count' => $savedCount
                ], JSON_UNESCAPED_UNICODE),
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $pdo->commit();

            accessJsonResponse(true, 'User access configuration updated successfully.', [
                'user_id' => $targetUserId,
                'access_mode' => $accessMode,
                'mappings_saved' => $savedCount
            ]);
            break;

        /* ============================================================
           ACTION: REMOVE USER ACCESS MAPPING
           ============================================================ */
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                accessJsonResponse(false, 'Method not allowed.', [], 405);
            }

            $accessId = (int)($_POST['access_id'] ?? 0);
            if ($accessId <= 0) {
                accessJsonResponse(false, 'Invalid Access ID.', [], 400);
            }

            // Fetch record to check customer ownership
            $stmtRec = $pdo->prepare("
                SELECT access_id, user_id, customer_id, company_id, division_id, plant_id, status 
                FROM user_organization_access 
                WHERE access_id = :aid
            ");
            $stmtRec->execute([':aid' => $accessId]);
            $rec = $stmtRec->fetch(PDO::FETCH_ASSOC);

            if (!$rec) {
                accessJsonResponse(false, 'Access record not found.', [], 404);
            }

            if ($scopedCustomerId && $scopedCustomerId > 0 && (int)$rec['customer_id'] !== $scopedCustomerId) {
                accessJsonResponse(false, 'Access violation: Cannot remove access mapping for another customer.', [], 403);
            }

            $pdo->beginTransaction();

            // Set to Inactive
            $stmtDel = $pdo->prepare("UPDATE user_organization_access SET status = 'Inactive' WHERE access_id = :aid");
            $stmtDel->execute([':aid' => $accessId]);

            // Audit
            $stmtAudit = $pdo->prepare("
                INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES (:uid, 'user_organization_access', :eid, 'USER_ACCESS_REMOVED', :old_val, NULL, CURRENT_TIMESTAMP, :ip)
            ");
            $stmtAudit->execute([
                ':uid'     => $currentUserId,
                ':eid'     => $accessId,
                ':old_val' => json_encode($rec, JSON_UNESCAPED_UNICODE),
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $pdo->commit();

            accessJsonResponse(true, 'Access mapping successfully removed.');
            break;

        default:
            accessJsonResponse(false, "Unknown action '{$action}'.", [], 400);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    accessJsonResponse(false, $e->getMessage(), [], 500);
}
