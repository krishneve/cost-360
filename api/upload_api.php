<?php
/**
 * Cost360 - Upload Centre REST API Endpoint
 * Handles file ingestion, preview requests, column mapping, and dedicated Customer DB (cust<ID>) import actions.
 * Strictly protected by session authentication via requireAuth() and RBAC permissions.
 */

ob_start();

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';
require_once __DIR__ . '/../config/uploaded_data_reports.php';

// Verify authentication before processing any request
if (!isLoggedIn()) {
    if (ob_get_length()) ob_clean();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Unauthorized. Please log in to Cost360.']);
    exit;
}

$user = getAuthUser();
if (!$user) {
    if (ob_get_length()) ob_clean();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Unable to identify authenticated user context.']);
    exit;
}

// Verify User Role / Menu Permission for Upload Centre & Financial Reconciliation
$userId = (int)$user['user_id'];
$hasUploadAccess = isPlatformAdmin($userId) || 
                   isCmaAdmin($userId) || 
                   userHasMenuAccess('UPLOAD_CENTRE', $userId) || 
                   userHasMenuAccess('UPLOAD_CENTER', $userId) || 
                   userHasMenuAccess('/data/upload', $userId) || 
                   userHasMenuAccess('DATA_MANAGEMENT', $userId) || 
                   userHasMenuAccess('AUDIT', $userId) ||
                   userHasMenuAccess('AUDIT_DESK', $userId) ||
                   userHasMenuAccess('RECONCILIATION', $userId) ||
                   userHasPermission('can_upload', 'UPLOAD_CENTRE', $userId) ||
                   userHasPermission('can_view', 'UPLOAD_CENTRE', $userId);

if (!$hasUploadAccess) {
    if (ob_get_length()) ob_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Access Denied: You do not have permission to access the Upload Centre.']);
    exit;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? ($_REQUEST['action'] ?? ''));

// Handle CSV Error Export (can be GET request)
if ($action === 'export_errors') {
    if (ob_get_length()) ob_clean();
    $uploadId = (int)($_REQUEST['upload_id'] ?? 0);
    $errors = getImportErrorsByUploadId($uploadId);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import_errors_' . $uploadId . '_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Error ID', 'Upload Run ID', 'Worksheet', 'Line / Row Number', 'Column / Field', 'Error Type', 'Error Message', 'Severity', 'Logged Timestamp']);

    foreach ($errors as $e) {
        fputcsv($output, [
            $e['id'] ?? '',
            $e['upload_id'] ?? '',
            $e['sheet_name'] ?? 'Main Sheet',
            $e['row_number'] ?? '',
            $e['field'] ?? '',
            $e['error_type'] ?? '',
            $e['error_message'] ?? '',
            $e['severity'] ?? '',
            $e['created_at'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// Handle CSV Dataset Export (can be GET or POST request)
if ($action === 'reports_export_csv' || $action === 'export_dataset_csv') {
    if (ob_get_length()) ob_clean();

    $userId = (int)($user['user_id'] ?? 0);
    $canExport = isPlatformAdmin($userId) || 
                 isCmaAdmin($userId) || 
                 userHasPermission('EXPORT', 'REPORTS', $userId) || 
                 userHasPermission('can_export', 'REPORTS', $userId) ||
                 userHasPermission('EXPORT', 'UPLOAD_CENTRE', $userId) ||
                 userHasPermission('can_export', 'UPLOAD_CENTRE', $userId);

    if (!$canExport) {
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
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Parameter "dataset" or "dataset_key" is required.']);
        exit;
    }

    try {
        $custDbContext = getUserCustomerDbContext($user);
        $custPdo = $custDbContext['pdo'];
        $companyCode = !empty($_REQUEST['company_code']) ? trim((string)$_REQUEST['company_code']) : null;
        $periodId = !empty($_REQUEST['period_id']) ? (int)$_REQUEST['period_id'] : null;

        // Verify company allocation
        $accessibleCompanies = getUserAccessibleCompanies($userId, $custDbContext['customer_id']);
        $accessibleCompanyCodes = array_map(function($c) {
            return strtoupper(trim($c['company_code'] ?? ''));
        }, $accessibleCompanies);

        if (!isPlatformAdmin($userId)) {
            if (empty($accessibleCompanies)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Access Denied: You have no allocated companies under this customer.']);
                exit;
            }
            if ($companyCode !== null && $companyCode !== '') {
                if (!in_array(strtoupper($companyCode), $accessibleCompanyCodes, true)) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'message' => "Access Denied: You are not authorized to access company '{$companyCode}'."]);
                    exit;
                }
            } elseif (count($accessibleCompanies) === 1) {
                $companyCode = $accessibleCompanies[0]['company_code'];
            }
        }

        $def = findReportableDataset($datasetKey);
        $downloadFilename = strtolower($def ? $def['target_table'] : $datasetKey) . '_export_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');

        exportDatasetCsv($custPdo, $datasetKey, $_REQUEST, [
            'company_code' => $companyCode,
            'period_id'    => $periodId,
            'user'         => $user
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (ob_get_length()) ob_clean();
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'POST request method required.']);
    exit;
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    switch ($action) {
        case 'upload':
            $canUpload = isPlatformAdmin($user['user_id']) || userHasPermission('UPLOAD', 'UPLOAD_CENTRE', $user['user_id']) || userHasPermission('UPLOAD', null, $user['user_id']);
            if (!$canUpload) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Permission Denied: Your role does not have permission to upload files.']);
                exit;
            }
            $files = $_FILES['files'] ?? null;
            if (!$files) {
                if (ob_get_length()) ob_clean();
                echo json_encode(['ok' => false, 'message' => 'No files selected for upload.']);
                exit;
            }
            $result = analyzeUploadedFiles($files, $user);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'preview':
            $fileId = (int)($_POST['id'] ?? 0);
            if (!$fileId) {
                if (ob_get_length()) ob_clean();
                echo json_encode(['ok' => false, 'message' => 'File ID required.']);
                exit;
            }
            $targetParam = trim($_POST['target_dataset'] ?? ($_POST['target_table'] ?? ''));
            $periodId = !empty($_POST['preview_period_id']) ? (int)$_POST['preview_period_id'] : (!empty($_POST['period_id']) ? (int)$_POST['period_id'] : null);
            $startDate = !empty($_POST['start_date']) ? trim($_POST['start_date']) : null;
            $endDate = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
            $result = getFilePreviewData($fileId, $user, $periodId, $startDate, $endDate, $targetParam ?: null);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'import':
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            if (!$uploadId) {
                if (ob_get_length()) ob_clean();
                echo json_encode(['ok' => false, 'message' => 'Upload Run ID required.']);
                exit;
            }
            $selectedFileIds = null;
            if (!empty($_POST['file_ids'])) {
                if (is_array($_POST['file_ids'])) {
                    $selectedFileIds = array_map('intval', $_POST['file_ids']);
                } else {
                    $selectedFileIds = array_filter(array_map('intval', explode(',', $_POST['file_ids'])));
                }
            }
            $periodId = !empty($_POST['preview_period_id']) ? (int)$_POST['preview_period_id'] : (!empty($_POST['period_id']) ? (int)$_POST['period_id'] : null);
            $startDate = !empty($_POST['start_date']) ? trim($_POST['start_date']) : null;
            $endDate = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
            $periodName = !empty($_POST['period_name']) ? trim($_POST['period_name']) : null;
            $result = executeDataImport($uploadId, $user, $selectedFileIds, $periodId, $startDate, $endDate, $periodName);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'periods':
            $periods = getUserAccessiblePeriods();
            $authRes = getAuthoritativeUploadPeriod(null, $user);
            $activePeriod = $authRes['ok'] ? $authRes['period'] : getAccountingPeriodContext();
            if (ob_get_length()) ob_clean();
            echo json_encode([
                'ok' => true, 
                'periods' => $periods, 
                'active_period' => $activePeriod,
                'period_error' => $authRes['ok'] ? null : $authRes['message']
            ]);
            break;

        case 'stats':
            $stats = getUploadCentreStats($user['user_id']);
            if (ob_get_length()) ob_clean();
            echo json_encode(['ok' => true, 'stats' => $stats]);
            break;

        case 'dropdowns':
            $groups = getMasterGroupsAndSubCategories();
            $targetDatasets = getCanonicalTargetDatasets();
            if (ob_get_length()) ob_clean();
            echo json_encode(['ok' => true, 'master_groups' => $groups, 'target_datasets' => $targetDatasets]);
            break;

        case 'target_datasets':
            $targetDatasets = getCanonicalTargetDatasets();
            if (ob_get_length()) ob_clean();
            echo json_encode(['ok' => true, 'target_datasets' => $targetDatasets]);
            break;

        case 'resolve_period':
            $periodId = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null;
            $startDate = !empty($_POST['start_date']) ? trim($_POST['start_date']) : null;
            $endDate = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
            $periodName = !empty($_POST['period_name']) ? trim($_POST['period_name']) : null;
            $res = resolveOrRegisterPeriod($periodId, $startDate, $endDate, $periodName, $user);
            if (ob_get_length()) ob_clean();
            echo json_encode($res);
            break;

        case 'validate_period_dates':
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');
            $selectedFileIds = [];
            if (!empty($_POST['file_ids'])) {
                if (is_array($_POST['file_ids'])) {
                    $selectedFileIds = array_map('intval', $_POST['file_ids']);
                } else {
                    $selectedFileIds = array_filter(array_map('intval', explode(',', $_POST['file_ids'])));
                }
            }
            $res = validateUploadPeriodDates($uploadId, $selectedFileIds, $startDate, $endDate);
            if (ob_get_length()) ob_clean();
            echo json_encode($res);
            break;

        case 'update_classification':
            $fileId = (int)($_POST['file_id'] ?? 0);
            $targetParam = $_POST['target_dataset'] ?? ($_POST['target_table'] ?? ($_POST['sub_category_id'] ?? 0));
            $res = updateFileClassification($fileId, $targetParam);
            if (ob_get_length()) ob_clean();
            echo json_encode($res);
            break;

        case 'errors':
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $errors = getImportErrorsByUploadId($uploadId);
            if (ob_get_length()) ob_clean();
            echo json_encode(['ok' => true, 'errors' => $errors]);
            break;

        case 'save_mapping':
            $fileId = (int)($_POST['file_id'] ?? 0);
            $mappingRaw = $_POST['mapping'] ?? '';
            $mapping = is_array($mappingRaw) ? $mappingRaw : json_decode($mappingRaw, true);
            if (!$fileId || !is_array($mapping)) {
                if (ob_get_length()) ob_clean();
                echo json_encode(['ok' => false, 'message' => 'Valid File ID and mapping payload required.']);
                exit;
            }
            $result = saveCustomMapping($fileId, $mapping, $userId, $user);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'update_mapping':
            $targetTable = trim($_POST['target_table'] ?? '');
            $sheetName = trim($_POST['sheet_name'] ?? '');
            $companyCode = trim($_POST['company_code'] ?? '');
            $mappingRaw = $_POST['mapping'] ?? '';
            $mapping = is_array($mappingRaw) ? $mappingRaw : json_decode($mappingRaw, true);

            if (empty($targetTable) || !is_array($mapping)) {
                if (ob_get_length()) ob_clean();
                echo json_encode(['ok' => false, 'message' => 'Target table name and valid mapping definition required.']);
                exit;
            }

            $result = updateCustomerSavedMapping($targetTable, $mapping, $sheetName, $companyCode, $user);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'reconcile_trial_balance':
            $custDbContext = getUserCustomerDbContext($user);
            $custPdo = $custDbContext['pdo'];
            $tolerance = isset($_POST['tolerance']) ? (float)$_POST['tolerance'] : 0.00;
            $companyCode = !empty($_POST['company_code']) ? trim($_POST['company_code']) : null;
            $periodId = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null;

            $result = reconcileTrialBalance($custPdo, [
                'company_code' => $companyCode,
                'period_id'    => $periodId,
                'tolerance'    => $tolerance,
                'user'         => $user
            ]);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'inventory_gl_reconciliation':
            $custDbContext = getUserCustomerDbContext($user);
            $custPdo = $custDbContext['pdo'];
            $companyCode = !empty($_POST['company_code']) ? trim($_POST['company_code']) : null;
            $periodId = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null;

            $result = reconcileInventoryToGeneralLedger($custPdo, [
                'company_code' => $companyCode,
                'period_id'    => $periodId,
                'user'         => $user
            ]);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'financial_reconciliation_overview':
            $custDbContext = getUserCustomerDbContext($user);
            $custPdo = $custDbContext['pdo'];
            $tolerance = isset($_POST['tolerance']) ? (float)$_POST['tolerance'] : 0.00;
            $companyCode = !empty($_POST['company_code']) ? trim($_POST['company_code']) : null;
            $periodId = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null;

            $result = getFinancialReconciliationOverview($custPdo, [
                'company_code' => $companyCode,
                'period_id'    => $periodId,
                'tolerance'    => $tolerance,
                'user'         => $user
            ]);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'reports_catalogue':
            $custDbContext = getUserCustomerDbContext($user);
            $custPdo = $custDbContext['pdo'];
            $companyCode = !empty($_POST['company_code']) ? trim($_POST['company_code']) : null;
            $periodId = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null;
            $result = getReportableDatasetsCatalogue($custPdo, [
                'company_code' => $companyCode,
                'period_id'    => $periodId
            ]);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'reports_overall_summary':
            $globalPdo = getDbConnection();
            $custDbContext = getUserCustomerDbContext($user);
            $custPdo = $custDbContext['pdo'];
            $companyCode = !empty($_POST['company_code']) ? trim($_POST['company_code']) : null;
            $periodId = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null;
            $result = getOverallUploadSummary($globalPdo, $custPdo, [
                'customer_id'  => $custDbContext['customer_id'],
                'company_code' => $companyCode,
                'period_id'    => $periodId
            ]);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'reports_dataset_summary':
            $custDbContext = getUserCustomerDbContext($user);
            $custPdo = $custDbContext['pdo'];
            $datasetKey = trim((string)($_POST['dataset'] ?? ($_POST['dataset_key'] ?? '')));
            if ($datasetKey === '') {
                http_response_code(400);
                if (ob_get_length()) ob_clean();
                echo json_encode(['ok' => false, 'message' => 'Dataset key is required.']);
                exit;
            }
            $result = getDatasetSummary($custPdo, $datasetKey, $_POST, [
                'company_code' => !empty($_POST['company_code']) ? trim($_POST['company_code']) : null,
                'period_id'    => !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null,
                'user'         => $user
            ]);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'reports_dataset_details':
            $custDbContext = getUserCustomerDbContext($user);
            $custPdo = $custDbContext['pdo'];
            $datasetKey = trim((string)($_POST['dataset'] ?? ($_POST['dataset_key'] ?? '')));
            if ($datasetKey === '') {
                http_response_code(400);
                if (ob_get_length()) ob_clean();
                echo json_encode(['ok' => false, 'message' => 'Dataset key is required.']);
                exit;
            }
            $result = getDatasetDetails($custPdo, $datasetKey, $_POST, [
                'company_code' => !empty($_POST['company_code']) ? trim($_POST['company_code']) : null,
                'period_id'    => !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null,
                'user'         => $user
            ]);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        default:
            if (ob_get_length()) ob_clean();
            echo json_encode(['ok' => false, 'message' => 'Unknown API action specified.']);
            break;
    }
} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}
