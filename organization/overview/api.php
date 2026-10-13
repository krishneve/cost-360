<?php
/**
 * Cost360 - Organization Overview API Handler
 * Provides full visual hierarchy tree (Customer -> Company -> Division -> Plant -> Warehouse)
 * and dynamic dependent dropdown filter options.
 *
 * Adheres strictly to the current Neon PostgreSQL schema:
 * - customers: customer_id, customer_code, customer_name, address, contact_person, contact_email, contact_phone, status, created_at
 * - companies: company_id, customer_id, company_code, company_name, address, status
 * - divisions: division_id, company_id, division_code, division_name, status
 * - plants: plant_id, division_id, plant_code, plant_name, location, status
 * - warehouses: warehouse_id, plant_id, warehouse_code, warehouse_name, status (NO 'type' column)
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
$activeCustomerId = $activeCompany['customer_id'] ?? null;
$isPlatform = isPlatformAdmin();
$isCma = isCmaAdmin();

if ($isCma && !$activeCustomerId) {
    $activeCustomerId = getCmaAdminCustomerId();
} elseif (!$activeCustomerId && isset($_SESSION['active_customer_id']) && is_numeric($_SESSION['active_customer_id'])) {
    $activeCustomerId = (int)$_SESSION['active_customer_id'];
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'tree';

try {
    $pdo = getDbConnection();

    /* ============================================================
       ACTION: GET HIERARCHY TREE
       ============================================================ */
    if ($action === 'tree' || $action === 'get_hierarchy_tree') {
        $search = trim($_GET['search'] ?? '');
        $filterCustomerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int)$_GET['customer_id'] : null;
        $filterCompanyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : null;

        // Accessible Customers list for current user
        $accessibleCustomers = [];
        $allowedCustomerIds = [];
        if ($isPlatform) {
            $cStmt = $pdo->query("SELECT customer_id, customer_code, customer_name FROM customers WHERE UPPER(status) = 'ACTIVE' ORDER BY customer_name ASC");
            $accessibleCustomers = $cStmt->fetchAll(PDO::FETCH_ASSOC);
            $allowedCustomerIds = array_column($accessibleCustomers, 'customer_id');
        } else {
            $userCustomers = getUserAccessibleCustomers($currentUser['user_id'] ?? null);
            if (!empty($userCustomers)) {
                $accessibleCustomers = $userCustomers;
                $allowedCustomerIds = array_column($userCustomers, 'customer_id');
            } elseif ($activeCustomerId) {
                $cStmt = $pdo->prepare("SELECT customer_id, customer_code, customer_name FROM customers WHERE customer_id = :cid ORDER BY customer_name ASC");
                $cStmt->execute([':cid' => $activeCustomerId]);
                $accessibleCustomers = $cStmt->fetchAll(PDO::FETCH_ASSOC);
                $allowedCustomerIds = [$activeCustomerId];
            }
        }

        // Customer scoping
        if (!$isPlatform && !empty($allowedCustomerIds)) {
            if ($filterCustomerId && in_array($filterCustomerId, $allowedCustomerIds)) {
                // Keep selected allowed customer
            } elseif ($activeCustomerId && in_array($activeCustomerId, $allowedCustomerIds)) {
                $filterCustomerId = (int)$activeCustomerId;
            } elseif (count($allowedCustomerIds) === 1) {
                $filterCustomerId = (int)$allowedCustomerIds[0];
            }
        }

        // Build Customers Query
        $custSql = "
            SELECT 
                customer_id, 
                customer_code, 
                customer_name, 
                address, 
                contact_person, 
                contact_email, 
                contact_phone, 
                status, 
                created_at 
            FROM customers 
            WHERE 1=1
        ";
        $custParams = [];

        if (!$isPlatform && !empty($allowedCustomerIds)) {
            $inPlaceholders = [];
            foreach ($allowedCustomerIds as $i => $id) {
                $key = ':allowed_cid_' . $i;
                $inPlaceholders[] = $key;
                $custParams[$key] = (int)$id;
            }
            $custSql .= " AND customer_id IN (" . implode(',', $inPlaceholders) . ")";
        }

        if ($filterCustomerId) {
            $custSql .= " AND customer_id = :cid";
            $custParams[':cid'] = $filterCustomerId;
        }

        if ($search !== '') {
            $custSql .= " AND (
                customer_code ILIKE :s 
                OR customer_name ILIKE :s 
                OR address ILIKE :s
                OR contact_person ILIKE :s
                OR EXISTS (
                    SELECT 1 FROM companies c 
                    WHERE c.customer_id = customers.customer_id 
                    AND (c.company_code ILIKE :s OR c.company_name ILIKE :s OR c.address ILIKE :s)
                )
                OR EXISTS (
                    SELECT 1 FROM divisions d 
                    JOIN companies c ON d.company_id = c.company_id 
                    WHERE c.customer_id = customers.customer_id 
                    AND (d.division_code ILIKE :s OR d.division_name ILIKE :s)
                )
                OR EXISTS (
                    SELECT 1 FROM plants p 
                    JOIN divisions d ON p.division_id = d.division_id 
                    JOIN companies c ON d.company_id = c.company_id 
                    WHERE c.customer_id = customers.customer_id 
                    AND (p.plant_code ILIKE :s OR p.plant_name ILIKE :s OR p.location ILIKE :s)
                )
                OR EXISTS (
                    SELECT 1 FROM warehouses w 
                    JOIN plants p ON w.plant_id = p.plant_id 
                    JOIN divisions d ON p.division_id = d.division_id 
                    JOIN companies c ON d.company_id = c.company_id 
                    WHERE c.customer_id = customers.customer_id 
                    AND (w.warehouse_code ILIKE :s OR w.warehouse_name ILIKE :s)
                )
            )";
            $custParams[':s'] = '%' . $search . '%';
        }

        $custSql .= " ORDER BY customer_name ASC, customer_id ASC";

        $stmt = $pdo->prepare($custSql);
        $stmt->execute($custParams);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Companies
        $compSql = "
            SELECT 
                company_id, 
                customer_id, 
                company_code, 
                company_name, 
                address, 
                status 
            FROM companies 
            WHERE 1=1
        ";
        $compParams = [];
        if ($filterCompanyId) {
            $compSql .= " AND company_id = :comp_id";
            $compParams[':comp_id'] = $filterCompanyId;
        }
        $compSql .= " ORDER BY company_name ASC, company_id ASC";
        $compStmt = $pdo->prepare($compSql);
        $compStmt->execute($compParams);
        $companiesRaw = $compStmt->fetchAll(PDO::FETCH_ASSOC);

        $companiesByCustomer = [];
        foreach ($companiesRaw as $c) {
            $companiesByCustomer[$c['customer_id']][] = $c;
        }

        // Fetch Divisions
        $divSql = "
            SELECT 
                division_id, 
                company_id, 
                division_code, 
                division_name, 
                status 
            FROM divisions 
            ORDER BY division_name ASC, division_id ASC
        ";
        $divsRaw = $pdo->query($divSql)->fetchAll(PDO::FETCH_ASSOC);
        $divisionsByCompany = [];
        foreach ($divsRaw as $d) {
            $divisionsByCompany[$d['company_id']][] = $d;
        }

        // Fetch Plants
        $plantSql = "
            SELECT 
                plant_id, 
                division_id, 
                plant_code, 
                plant_name, 
                location, 
                status 
            FROM plants 
            ORDER BY plant_name ASC, plant_id ASC
        ";
        $plantsRaw = $pdo->query($plantSql)->fetchAll(PDO::FETCH_ASSOC);
        $plantsByDivision = [];
        foreach ($plantsRaw as $p) {
            $plantsByDivision[$p['division_id']][] = $p;
        }

        // Fetch Warehouses (Confirmed columns: warehouse_id, plant_id, warehouse_code, warehouse_name, status)
        $whSql = "
            SELECT 
                warehouse_id, 
                plant_id, 
                warehouse_code, 
                warehouse_name, 
                status 
            FROM warehouses 
            ORDER BY warehouse_name ASC, warehouse_id ASC
        ";
        $whsRaw = $pdo->query($whSql)->fetchAll(PDO::FETCH_ASSOC);
        $warehousesByPlant = [];
        foreach ($whsRaw as $w) {
            $warehousesByPlant[$w['plant_id']][] = $w;
        }

        // Assemble 5-Level Hierarchical Tree
        $tree = [];
        $totalCustomers = count($customers);
        $totalCompanies = 0;
        $totalDivisions = 0;
        $totalPlants = 0;
        $totalWarehouses = 0;

        foreach ($customers as $cust) {
            $custId = $cust['customer_id'];
            $custCompanies = $companiesByCustomer[$custId] ?? [];

            $builtCompanies = [];
            foreach ($custCompanies as $comp) {
                $totalCompanies++;
                $compId = $comp['company_id'];
                $compDivs = $divisionsByCompany[$compId] ?? [];

                $builtDivisions = [];
                foreach ($compDivs as $div) {
                    $totalDivisions++;
                    $divId = $div['division_id'];
                    $divPlants = $plantsByDivision[$divId] ?? [];

                    $builtPlants = [];
                    foreach ($divPlants as $plant) {
                        $totalPlants++;
                        $plantId = $plant['plant_id'];
                        $plantWhs = $warehousesByPlant[$plantId] ?? [];

                        $totalWarehouses += count($plantWhs);

                        $builtPlants[] = array_merge($plant, [
                            'warehouses' => $plantWhs,
                            'warehouse_count' => count($plantWhs)
                        ]);
                    }

                    $builtDivisions[] = array_merge($div, [
                        'plants' => $builtPlants,
                        'plant_count' => count($builtPlants)
                    ]);
                }

                $builtCompanies[] = array_merge($comp, [
                    'divisions' => $builtDivisions,
                    'division_count' => count($builtDivisions)
                ]);
            }

            $tree[] = array_merge($cust, [
                'companies' => $builtCompanies,
                'company_count' => count($builtCompanies)
            ]);
        }

        echo json_encode([
            'success' => true,
            'kpis' => [
                'customers' => $totalCustomers,
                'companies' => $totalCompanies,
                'divisions' => $totalDivisions,
                'plants' => $totalPlants,
                'warehouses' => $totalWarehouses
            ],
            'tree' => $tree,
            'customers_list' => $accessibleCustomers
        ]);
        exit;
    }

    /* ============================================================
       ACTION: GET DEPENDENT DROPDOWN OPTIONS
       ============================================================ */
    elseif ($action === 'get_options' || $action === 'dropdowns') {
        $target = $_GET['target'] ?? 'all';
        $parentCustId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int)$_GET['customer_id'] : null;
        $parentCompId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : null;
        $parentDivId = isset($_GET['division_id']) && $_GET['division_id'] !== '' ? (int)$_GET['division_id'] : null;

        if (!$isPlatform && $activeCustomerId) {
            $parentCustId = (int)$activeCustomerId;
        }

        $res = [];

        // Customers list
        if ($target === 'customers' || $target === 'all') {
            $cSql = "SELECT customer_id, customer_code, customer_name FROM customers WHERE UPPER(status) = 'ACTIVE'";
            $cParams = [];
            if (!$isPlatform && $activeCustomerId) {
                $cSql .= " AND customer_id = :cid";
                $cParams[':cid'] = $activeCustomerId;
            }
            $cSql .= " ORDER BY customer_name ASC";
            $stmt = $pdo->prepare($cSql);
            $stmt->execute($cParams);
            $res['customers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Companies list
        if ($target === 'companies' || $target === 'all') {
            $coSql = "SELECT company_id, customer_id, company_code, company_name FROM companies WHERE UPPER(status) = 'ACTIVE'";
            $coParams = [];
            if ($parentCustId) {
                $coSql .= " AND customer_id = :cid";
                $coParams[':cid'] = $parentCustId;
            }
            $coSql .= " ORDER BY company_name ASC";
            $stmt = $pdo->prepare($coSql);
            $stmt->execute($coParams);
            $res['companies'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Divisions list
        if ($target === 'divisions' || $target === 'all') {
            $dSql = "SELECT d.division_id, d.company_id, d.division_code, d.division_name FROM divisions d JOIN companies c ON d.company_id = c.company_id WHERE UPPER(d.status) = 'ACTIVE'";
            $dParams = [];
            if ($parentCompId) {
                $dSql .= " AND d.company_id = :comp_id";
                $dParams[':comp_id'] = $parentCompId;
            }
            if ($parentCustId) {
                $dSql .= " AND c.customer_id = :cust_id";
                $dParams[':cust_id'] = $parentCustId;
            }
            $dSql .= " ORDER BY d.division_name ASC";
            $stmt = $pdo->prepare($dSql);
            $stmt->execute($dParams);
            $res['divisions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Plants list
        if ($target === 'plants' || $target === 'all') {
            $pSql = "SELECT p.plant_id, p.division_id, p.plant_code, p.plant_name FROM plants p JOIN divisions d ON p.division_id = d.division_id JOIN companies c ON d.company_id = c.company_id WHERE UPPER(p.status) = 'ACTIVE'";
            $pParams = [];
            if ($parentDivId) {
                $pSql .= " AND p.division_id = :div_id";
                $pParams[':div_id'] = $parentDivId;
            }
            if ($parentCompId) {
                $pSql .= " AND d.company_id = :comp_id";
                $pParams[':comp_id'] = $parentCompId;
            }
            if ($parentCustId) {
                $pSql .= " AND c.customer_id = :cust_id";
                $pParams[':cust_id'] = $parentCustId;
            }
            $pSql .= " ORDER BY p.plant_name ASC";
            $stmt = $pdo->prepare($pSql);
            $stmt->execute($pParams);
            $res['plants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => true, 'data' => $res]);
        exit;
    }

    else {
        throw new Exception("Invalid Overview action: {$action}");
    }

} catch (Throwable $e) {
    error_log("Cost360 Organization Overview API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Unable to load organization hierarchy. Please try again.'
    ]);
    exit;
}
