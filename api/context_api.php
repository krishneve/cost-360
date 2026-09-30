<?php
/**
 * Cost360 - Organization & Period Context Management API
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'switch_period':
        $periodId = $_POST['period_id'] ?? $_GET['period_id'] ?? null;
        if (!$periodId) {
            echo json_encode(['success' => false, 'error' => 'Period ID is required']);
            exit;
        }

        if (setActivePeriod($periodId)) {
            $period = getActivePeriod();
            echo json_encode([
                'success' => true,
                'period' => $period,
                'message' => 'Active period updated successfully'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid or unauthorized period selection']);
        }
        break;

    case 'switch_customer':
        $customerId = $_POST['customer_id'] ?? null;
        if (setActiveCustomer($customerId)) {
            echo json_encode([
                'success' => true,
                'redirect' => 'company-selection.php'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Unauthorized customer selection']);
        }
        break;

    case 'get_context':
        $context = getActiveOrganizationContext();
        echo json_encode([
            'success' => true,
            'context' => $context
        ]);
        break;

    case 'switch_company':
        $companyId = $_POST['company_id'] ?? null;
        if (setActiveCompany($companyId)) {
            echo json_encode([
                'success' => true,
                'redirect' => 'division-selection.php'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Unauthorized company selection']);
        }
        break;

    case 'switch_division':
        $divisionId = $_POST['division_id'] ?? null;
        if (setActiveDivision($divisionId)) {
            echo json_encode([
                'success' => true,
                'redirect' => 'plant-selection.php'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Unauthorized division selection']);
        }
        break;

    case 'switch_plant':
        $plantId = $_POST['plant_id'] ?? null;
        if (setActivePlant($plantId)) {
            echo json_encode([
                'success' => true,
                'redirect' => 'warehouse-selection.php'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Unauthorized plant selection']);
        }
        break;

    case 'switch_warehouse':
        $warehouseId = $_POST['warehouse_id'] ?? null;
        if (setActiveWarehouse($warehouseId)) {
            echo json_encode([
                'success' => true,
                'redirect' => 'period-selection.php'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Unauthorized warehouse selection']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
        break;
}
