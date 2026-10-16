<?php
/**
 * Cost360 - Uploaded Data Reports REST API Endpoint
 *
 * Dedicated REST endpoint for querying, summarizing, and exporting uploaded data
 * across all 11 canonical datasets in the dedicated Customer Database.
 *
 * Supported Actions:
 *   - catalogue: Returns inventory of 11 datasets, table existence, and record counts.
 *   - overall_summary: Returns aggregated upload batches, error stats, and record distributions.
 *   - dataset_summary: Returns dataset-specific business metrics and aggregations.
 *   - dataset_details: Returns paginated, sortable, searchable data rows with strict column allowlist.
 *   - export_csv: Streams filtered dataset rows directly as CSV download.
 */

ob_start();

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';
require_once __DIR__ . '/../config/uploaded_data_reports.php';

// Verify authentication before processing any request
if (!isLoggedIn()) {
    if (ob_get_length()) ob_clean();
    http_response_code(401);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => 'Unauthorized. Please log in to Cost360.']);
    exit;
}

$user = getAuthUser();
if (!$user) {
    if (ob_get_length()) ob_clean();
    http_response_code(401);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => 'Unable to identify authenticated user context.']);
    exit;
}

// Verify User Role / Permission for Reports & Upload Data Viewing
$userId = (int)$user['user_id'];
$hasReportAccess = isPlatformAdmin($userId) || 
                   isCmaAdmin($userId) || 
                   userHasMenuAccess('REPORTS', $userId) || 
                   userHasMenuAccess('UPLOAD_CENTRE', $userId) || 
                   userHasMenuAccess('UPLOAD_CENTER', $userId) || 
                   userHasMenuAccess('DATA_MANAGEMENT', $userId) || 
                   userHasMenuAccess('AUDIT', $userId) ||
                   userHasMenuAccess('AUDIT_DESK', $userId) ||
                   userHasMenuAccess('RECONCILIATION', $userId) ||
                   userHasPermission('can_view', 'REPORTS', $userId) ||
                   userHasPermission('can_view', 'UPLOAD_CENTRE', $userId);

if (!$hasReportAccess) {
    if (ob_get_length()) ob_clean();
    http_response_code(403);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => 'Access Denied: You do not have permission to view Uploaded Data Reports.']);
    exit;
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'catalogue')));

// Normalize alias actions
if ($action === 'reports_catalogue') $action = 'catalogue';
if ($action === 'reports_overall_summary') $action = 'overall_summary';
if ($action === 'reports_dataset_summary') $action = 'dataset_summary';
if ($action === 'reports_dataset_details') $action = 'dataset_details';
if ($action === 'reports_export_csv') $action = 'export_csv';

try {
    $custDbContext = getUserCustomerDbContext($user);
    $custPdo = $custDbContext['pdo'];
    $globalPdo = getDbConnection();

    // Resolve Context Parameters
    $companyCode = !empty($_REQUEST['company_code']) ? trim((string)$_REQUEST['company_code']) : null;
    $periodId = !empty($_REQUEST['period_id']) ? (int)$_REQUEST['period_id'] : null;

    // Verify company allocation for current user
    $accessibleCompanies = getUserAccessibleCompanies($userId, $custDbContext['customer_id']);
    $accessibleCompanyCodes = array_map(function($c) {
        return strtoupper(trim($c['company_code'] ?? ''));
    }, $accessibleCompanies);

    $isPlatformAdmin = isPlatformAdmin($userId);
    $isCma = isCmaAdmin($userId);

    // Export authorization flag
    $canExport = $isPlatformAdmin || $isCma || 
                 userHasPermission('EXPORT', 'REPORTS', $userId) || 
                 userHasPermission('can_export', 'REPORTS', $userId) ||
                 userHasPermission('EXPORT', 'UPLOAD_CENTRE', $userId) ||
                 userHasPermission('can_export', 'UPLOAD_CENTRE', $userId);

    // Company access verification for non-platform admins
    if (!$isPlatformAdmin) {
        if (empty($accessibleCompanies)) {
            // User has no allocated companies under this customer
            if ($companyCode !== null) {
                if (ob_get_length()) ob_clean();
                http_response_code(403);
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['ok' => false, 'message' => 'Access Denied: You have no allocated companies under this customer.']);
                exit;
            }
            $companyCode = '__NO_AUTHORIZED_COMPANIES__';
        } else {
            if ($companyCode !== null && $companyCode !== '') {
                if (!in_array(strtoupper($companyCode), $accessibleCompanyCodes, true)) {
                    if (ob_get_length()) ob_clean();
                    http_response_code(403);
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8');
                    }
                    echo json_encode(['ok' => false, 'message' => "Access Denied: You are not authorized to access company '{$companyCode}'."]);
                    exit;
                }
            } else {
                // If user has access to exactly one company, scope to it automatically
                if (count($accessibleCompanies) === 1) {
                    $companyCode = $accessibleCompanies[0]['company_code'];
                }
            }
        }
    }

    $context = [
        'customer_id'  => $custDbContext['customer_id'],
        'company_code' => $companyCode,
        'period_id'    => $periodId,
        'user'         => $user
    ];

    switch ($action) {
        case 'user_context':
            $accessiblePeriods = getUserAccessiblePeriods(null, $userId);
            $stmtCust = $globalPdo->prepare("SELECT customer_id, customer_name, customer_code, status FROM customers WHERE customer_id = :cid");
            $stmtCust->execute([':cid' => $custDbContext['customer_id']]);
            $customerInfo = $stmtCust->fetch(PDO::FETCH_ASSOC) ?: [
                'customer_id'   => $custDbContext['customer_id'],
                'customer_name' => 'Current Customer',
                'customer_code' => 'CUST' . $custDbContext['customer_id']
            ];

            $response = [
                'ok' => true,
                'user' => [
                    'user_id'    => $userId,
                    'username'   => $user['username'] ?? '',
                    'email'      => $user['email'] ?? '',
                    'first_name' => $user['first_name'] ?? '',
                    'last_name'  => $user['last_name'] ?? '',
                    'role'       => getCurrentUserRole($userId)
                ],
                'customer' => $customerInfo,
                'companies' => $accessibleCompanies,
                'periods' => array_map(function($p) {
                    return [
                        'period_id'   => (int)$p['period_id'],
                        'period_name' => $p['period_name'],
                        'start_date'  => $p['start_date'] ?? null,
                        'end_date'    => $p['end_date'] ?? null,
                        'is_locked'   => !empty($p['is_locked'])
                    ];
                }, $accessiblePeriods),
                'permissions' => [
                    'can_view'         => true,
                    'can_view_details' => true,
                    'can_export'       => $canExport
                ]
            ];
            if (ob_get_length()) ob_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($response);
            break;

        case 'catalogue':
            $result = getReportableDatasetsCatalogue($custPdo, $context);
            if (ob_get_length()) ob_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($result);
            break;

        case 'overall_summary':
            $result = getOverallUploadSummary($globalPdo, $custPdo, $context);
            if (ob_get_length()) ob_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($result);
            break;

        case 'dataset_summary':
            $datasetKey = trim((string)($_REQUEST['dataset'] ?? ($_REQUEST['dataset_key'] ?? '')));
            if ($datasetKey === '') {
                http_response_code(400);
                if (ob_get_length()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Parameter "dataset" or "dataset_key" is required.']);
                exit;
            }
            $result = getDatasetSummary($custPdo, $datasetKey, $_REQUEST, $context);
            if (ob_get_length()) ob_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($result);
            break;

        case 'dataset_details':
            $datasetKey = trim((string)($_REQUEST['dataset'] ?? ($_REQUEST['dataset_key'] ?? '')));
            if ($datasetKey === '') {
                http_response_code(400);
                if (ob_get_length()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Parameter "dataset" or "dataset_key" is required.']);
                exit;
            }
            $result = getDatasetDetails($custPdo, $datasetKey, $_REQUEST, $context);
            if (ob_get_length()) ob_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($result);
            break;

        case 'export_csv':
            if (!$canExport) {
                if (ob_get_length()) ob_clean();
                http_response_code(403);
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['ok' => false, 'message' => 'Access Denied: You do not have permission to export uploaded data reports.']);
                exit;
            }
            $datasetKey = trim((string)($_REQUEST['dataset'] ?? ($_REQUEST['dataset_key'] ?? '')));
            if ($datasetKey === '') {
                http_response_code(400);
                if (ob_get_length()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Parameter "dataset" or "dataset_key" is required.']);
                exit;
            }
            $def = findReportableDataset($datasetKey);
            $downloadFilename = strtolower($def ? $def['target_table'] : $datasetKey) . '_export_' . date('Ymd_His') . '.csv';

            if (ob_get_length()) ob_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');

            exportDatasetCsv($custPdo, $datasetKey, $_REQUEST, $context);
            exit;

        default:
            http_response_code(400);
            if (ob_get_length()) ob_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'message' => "Unknown or unsupported reports action: '{$action}'."]);
            break;
    }
} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}
