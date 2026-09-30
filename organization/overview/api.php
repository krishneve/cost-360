<?php
/**
 * Cost360 - Organization Overview API Handler
 * Provides full visual hierarchy tree (Customer -> Company -> Division -> Plant -> Warehouse)
 * and dynamic dependent dropdown filter options.
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

        // Scoping
        if (!$isPlatform && $activeCustomerId) {
            $filterCustomerId = (int)$activeCustomerId;
        }

        // Fetch Customers
        $custSql = "SELECT customer_id, customer_code, customer_name, status, created_at FROM customers WHERE 1=1";
        $custParams = [];

        if ($filterCustomerId) {
            $custSql .= " AND customer_id = :cid";
            $custParams[':cid'] = $filterCustomerId;
        }
        if ($search !== '') {
            $custSql .= " AND (customer_code ILIKE :s OR customer_name ILIKE :s)";
            $custParams[':s'] = '%' . $search . '%';
        }
        $custSql .= " ORDER BY customer_name ASC";

        $stmt = $pdo->prepare($custSql);
        $stmt->execute($custParams);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all Companies
        $compSql = "SELECT company_id, customer_id, company_code, company_name, address, status FROM companies WHERE 1=1";
        $compParams = [];
        if ($filterCompanyId) {
            $compSql .= " AND company_id = :comp_id";
            $compParams[':comp_id'] = $filterCompanyId;
        }
        $compSql .= " ORDER BY company_name ASC";
        $compStmt = $pdo->prepare($compSql);
        $compStmt->execute($compParams);
        $companiesRaw = $compStmt->fetchAll(PDO::FETCH_ASSOC);

        $companiesByCustomer = [];
        foreach ($companiesRaw as $c) {
            $companiesByCustomer[$c['customer_id']][] = $c;
        }

        // Fetch all Divisions
        $divSql = "SELECT division_id, company_id, division_code, division_name, status FROM divisions ORDER BY division_name ASC";
        $divsRaw = $pdo->query($divSql)->fetchAll(PDO::FETCH_ASSOC);
        $divisionsByCompany = [];
        foreach ($divsRaw as $d) {
            $divisionsByCompany[$d['company_id']][] = $d;
        }

        // Fetch all Plants
        $plantSql = "SELECT plant_id, division_id, plant_code, plant_name, location, status FROM plants ORDER BY plant_name ASC";
        $plantsRaw = $pdo->query($plantSql)->fetchAll(PDO::FETCH_ASSOC);
        $plantsByDivision = [];
        foreach ($plantsRaw as $p) {
            $plantsByDivision[$p['division_id']][] = $p;
        }

        // Fetch all Warehouses
        $whSql = "SELECT warehouse_id, plant_id, warehouse_code, warehouse_name, type, status FROM warehouses ORDER BY warehouse_name ASC";
        $whsRaw = $pdo->query($whSql)->fetchAll(PDO::FETCH_ASSOC);
        $warehousesByPlant = [];
        foreach ($whsRaw as $w) {
            $warehousesByPlant[$w['plant_id']][] = $w;
        }

        // Assemble Tree
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
            'tree' => $tree
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

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
