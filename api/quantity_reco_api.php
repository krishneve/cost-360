<?php
/**
 * Cost360 — Quantity Reco REST API Endpoint
 *
 * Provides RESTful access for:
 * - Dynamic Cost Centre retrieval per Plant
 * - Cost Basis Mapping configuration (CRUD)
 * - Quantity Reco Generation Engine
 * - Dynamic Quantity Reco Matrix & Totals
 * - Granular Details & Unmapped Exception Auditing
 * - Source Transaction Drill-down & Traceability
 */

ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/quantity_reco.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Verify User Authentication
if (!isLoggedIn()) {
    if (ob_get_length()) ob_clean();
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required. Please log in.']);
    exit;
}

$user = getAuthUser();
$userId = (int)($user['user_id'] ?? 0);

// 2. Verify RBAC Permissions
$hasAccess = isPlatformAdmin($userId) ||
             isCmaAdmin($userId) ||
             userHasMenuAccess('QUANTITY_RECO', $userId) ||
             userHasMenuAccess('RECONCILIATION', $userId) ||
             userHasMenuAccess('AUDIT', $userId) ||
             userHasMenuAccess('AUDIT_DESK', $userId) ||
             userHasMenuAccess('UPLOAD_CENTRE', $userId) ||
             userHasMenuAccess('DATA_MANAGEMENT', $userId);

if (!$hasAccess) {
    if (ob_get_length()) ob_clean();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied: You do not have permission to access the Quantity Reco module.']);
    exit;
}

$pdo = getDbConnection();
$action = trim((string)($_REQUEST['action'] ?? ''));

try {
    switch ($action) {

        // =====================================================================
        // 1. Get Cost Centres for a Plant
        // =====================================================================
        case 'get_cost_centres':
            $plantId = (int)($_GET['plant_id'] ?? $_POST['plant_id'] ?? 0);
            if ($plantId <= 0) {
                $orgCtx = getActiveOrganizationContext();
                $plantId = (int)($orgCtx['plant_id'] ?? 0);
            }
            if ($plantId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Plant ID is required.']);
                exit;
            }

            $activeOnly = !isset($_GET['all']) || $_GET['all'] !== '1';
            $costCentres = getPlantCostCentres($pdo, $plantId, $activeOnly);

            echo json_encode([
                'ok' => true,
                'plant_id' => $plantId,
                'count' => count($costCentres),
                'cost_centres' => $costCentres
            ]);
            break;

        // =====================================================================
        // 2. Save / Update Cost Centre for a Plant
        // =====================================================================
        case 'save_cost_centre':
            $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $res = saveCostCentre($pdo, $payload, $userId);
            echo json_encode([
                'ok' => true,
                'message' => 'Cost Centre saved successfully.',
                'cost_centre' => $res
            ]);
            break;

        // =====================================================================
        // 3. Get Cost Basis Mappings for a Plant
        // =====================================================================
        case 'get_mappings':
            $plantId = (int)($_GET['plant_id'] ?? $_POST['plant_id'] ?? 0);
            if ($plantId <= 0) {
                $orgCtx = getActiveOrganizationContext();
                $plantId = (int)($orgCtx['plant_id'] ?? 0);
            }
            if ($plantId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Plant ID is required.']);
                exit;
            }

            $activeOnly = !isset($_GET['all']) || $_GET['all'] !== '1';
            $mappings = getPlantCostBasisMappings($pdo, $plantId, $activeOnly);

            echo json_encode([
                'ok' => true,
                'plant_id' => $plantId,
                'count' => count($mappings),
                'mappings' => $mappings
            ]);
            break;

        // =====================================================================
        // 4. Save / Update Cost Basis Mapping
        // =====================================================================
        case 'save_mapping':
            if (!canManageQuantityRecoRules($userId)) {
                if (ob_get_length()) ob_clean();
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Access denied: You do not have permission to modify mappings.']);
                exit;
            }
            $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $saved = saveCostBasisMapping($pdo, $payload, $userId);
            echo json_encode([
                'ok' => true,
                'message' => 'Cost Basis Mapping saved successfully.',
                'mapping' => $saved
            ]);
            break;

        // =====================================================================
        // 5. Delete Cost Basis Mapping
        // =====================================================================
        case 'delete_mapping':
            if (!canManageQuantityRecoRules($userId)) {
                if (ob_get_length()) ob_clean();
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Access denied: You do not have permission to delete mappings.']);
                exit;
            }
            $mappingId = (int)($_POST['mapping_id'] ?? $_GET['mapping_id'] ?? 0);
            if ($mappingId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Mapping ID is required.']);
                exit;
            }
            $deleted = deleteCostBasisMapping($pdo, $mappingId);
            echo json_encode([
                'ok' => $deleted,
                'message' => $deleted ? 'Mapping deleted successfully.' : 'Failed to delete mapping.'
            ]);
            break;

        // =====================================================================
        // 5b. Get Department Cost Allocation Rules for a Plant
        // =====================================================================
        case 'get_allocation_rules':
            $plantId = (int)($_GET['plant_id'] ?? $_POST['plant_id'] ?? 0);
            $periodId = !empty($_GET['period_id']) ? (int)$_GET['period_id'] : (!empty($_POST['period_id']) ? (int)$_POST['period_id'] : null);
            if ($plantId <= 0) {
                $orgCtx = getActiveOrganizationContext();
                $plantId = (int)($orgCtx['plant_id'] ?? 0);
            }
            if ($plantId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Plant ID is required.']);
                exit;
            }

            $activeOnly = !isset($_GET['all']) || $_GET['all'] !== '1';
            $rules = getPlantCostAllocationRules($pdo, $plantId, $periodId, $activeOnly);

            echo json_encode([
                'ok' => true,
                'plant_id' => $plantId,
                'period_id' => $periodId,
                'count' => count($rules),
                'rules' => $rules
            ]);
            break;

        // =====================================================================
        // 5c. Save / Update Cost Allocation Rule
        // =====================================================================
        case 'save_allocation_rule':
            if (!canManageQuantityRecoRules($userId)) {
                if (ob_get_length()) ob_clean();
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Access denied: You do not have permission to modify allocation rules.']);
                exit;
            }
            $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $saved = saveCostAllocationRule($pdo, $payload, $userId);
            echo json_encode([
                'ok' => true,
                'message' => 'Cost Allocation Rule saved successfully.',
                'rule' => $saved
            ]);
            break;

        // =====================================================================
        // 5d. Delete Cost Allocation Rule
        // =====================================================================
        case 'delete_allocation_rule':
            if (!canManageQuantityRecoRules($userId)) {
                if (ob_get_length()) ob_clean();
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Access denied: You do not have permission to delete allocation rules.']);
                exit;
            }
            $ruleId = (int)($_POST['rule_id'] ?? $_GET['rule_id'] ?? 0);
            $accountCode = trim((string)($_POST['account_code'] ?? $_GET['account_code'] ?? ''));
            $plantId = (int)($_POST['plant_id'] ?? $_GET['plant_id'] ?? 0);
            $periodId = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : (!empty($_GET['period_id']) ? (int)$_GET['period_id'] : null);

            if ($ruleId > 0) {
                $deleted = deleteCostAllocationRule($pdo, $ruleId);
            } elseif ($plantId > 0 && !empty($accountCode)) {
                $deleted = deleteCostAllocationRulesByAccount($pdo, $plantId, $accountCode, $periodId);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Rule ID or Plant ID + Account Code is required.']);
                exit;
            }

            echo json_encode([
                'ok' => $deleted,
                'message' => $deleted ? 'Cost Allocation Rule deleted successfully.' : 'Failed to delete rule.'
            ]);
            break;

        // =====================================================================
        // 6. Generate Quantity Reco
        // =====================================================================
        case 'generate':
            $plantId = (int)($_POST['plant_id'] ?? $_GET['plant_id'] ?? 0);
            $periodId = (int)($_POST['period_id'] ?? $_GET['period_id'] ?? 0);

            if ($plantId <= 0) {
                $orgCtx = getActiveOrganizationContext();
                $plantId = (int)($orgCtx['plant_id'] ?? 0);
            }
            if ($periodId <= 0) {
                $orgCtx = getActiveOrganizationContext();
                $periodId = (int)($orgCtx['period_id'] ?? 0);
            }

            if ($plantId <= 0 || $periodId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Both Plant ID and Period ID are required to generate Quantity Reco.']);
                exit;
            }

            $options = [];
            $result = generateQuantityReco($pdo, $plantId, $periodId, $userId, $options);
            echo json_encode([
                'ok' => true,
                'message' => 'Quantity Reco generated successfully.',
                'summary' => $result
            ]);
            break;

        // =====================================================================
        // 7. Get Quantity Reco Matrix by Plant + Period
        // =====================================================================
        case 'get_reco':
            $plantId = (int)($_GET['plant_id'] ?? $_POST['plant_id'] ?? 0);
            $periodId = (int)($_GET['period_id'] ?? $_POST['period_id'] ?? 0);

            if ($plantId <= 0) {
                $orgCtx = getActiveOrganizationContext();
                $plantId = (int)($orgCtx['plant_id'] ?? 0);
            }
            if ($periodId <= 0) {
                $orgCtx = getActiveOrganizationContext();
                $periodId = (int)($orgCtx['period_id'] ?? 0);
            }

            if ($plantId <= 0 || $periodId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Plant ID and Period ID are required.']);
                exit;
            }

            $matrix = getQuantityRecoMatrix($pdo, $plantId, $periodId);
            echo json_encode($matrix);
            break;

        // =====================================================================
        // 8. Get Unmapped / Exception Records
        // =====================================================================
        case 'get_exceptions':
            $runId = (int)($_GET['run_id'] ?? $_POST['run_id'] ?? 0);
            if ($runId <= 0) {
                // Resolve from plant and period
                $plantId = (int)($_GET['plant_id'] ?? 0);
                $periodId = (int)($_GET['period_id'] ?? 0);
                if ($plantId > 0 && $periodId > 0) {
                    $rStmt = $pdo->prepare("SELECT run_id FROM public.quantity_reco_run WHERE plant_id = ? AND period_id = ? AND status = 'COMPLETED' ORDER BY run_id DESC LIMIT 1");
                    $rStmt->execute([$plantId, $periodId]);
                    $runId = (int)$rStmt->fetchColumn();
                }
            }

            if ($runId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Run ID or valid Plant+Period required.']);
                exit;
            }

            $status = isset($_GET['status']) ? trim($_GET['status']) : null;
            $exceptions = getQuantityRecoExceptions($pdo, $runId, $status);

            echo json_encode([
                'ok' => true,
                'run_id' => $runId,
                'count' => count($exceptions),
                'exceptions' => $exceptions
            ]);
            break;

        // =====================================================================
        // 9. Trace Quantity Reco Amount Back to Source Records (Drill-down)
        // =====================================================================
        case 'trace_source':
            $runId = (int)($_GET['run_id'] ?? $_POST['run_id'] ?? 0);
            $costCentreId = (int)($_GET['cost_centre_id'] ?? $_POST['cost_centre_id'] ?? 0);

            if ($runId <= 0) {
                $plantId = (int)($_GET['plant_id'] ?? 0);
                $periodId = (int)($_GET['period_id'] ?? 0);
                if ($plantId > 0 && $periodId > 0) {
                    $rStmt = $pdo->prepare("SELECT run_id FROM public.quantity_reco_run WHERE plant_id = ? AND period_id = ? AND status = 'COMPLETED' ORDER BY run_id DESC LIMIT 1");
                    $rStmt->execute([$plantId, $periodId]);
                    $runId = (int)$rStmt->fetchColumn();
                }
            }

            if ($runId <= 0 || $costCentreId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Both Run ID and Cost Centre ID are required for source traceability.']);
                exit;
            }

            $sourceRecords = traceCostCentreAmountToSource($pdo, $runId, $costCentreId);
            $subtotal = 0.0;
            foreach ($sourceRecords as $sr) {
                $subtotal += (float)$sr['amount'];
            }

            echo json_encode([
                'ok' => true,
                'run_id' => $runId,
                'cost_centre_id' => $costCentreId,
                'count' => count($sourceRecords),
                'subtotal' => round($subtotal, 4),
                'source_records' => $sourceRecords
            ]);
            break;

        // =====================================================================
        // 10. Import / Add Source Records
        // =====================================================================
        case 'import_source_records':
            $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $plantId = (int)($payload['plant_id'] ?? 0);
            $periodId = (int)($payload['period_id'] ?? 0);
            $records = (array)($payload['records'] ?? []);

            if ($plantId <= 0 || $periodId <= 0 || empty($records)) {
                echo json_encode(['ok' => false, 'error' => 'Plant ID, Period ID and records array are required.']);
                exit;
            }

            $insertedCount = saveQuantityRecoSourceTransactions($pdo, $plantId, $periodId, $records, $userId);
            echo json_encode([
                'ok' => true,
                'message' => "Successfully imported {$insertedCount} source transactions.",
                'inserted_count' => $insertedCount
            ]);
            break;

        // =====================================================================
        // 11. Phase 3: Import Real Plant Excel Worksheet (plant.xlsx)
        // =====================================================================
        case 'import_plant_excel':
            $plantId = (int)($_POST['plant_id'] ?? $_GET['plant_id'] ?? 0);
            $periodId = (int)($_POST['period_id'] ?? $_GET['period_id'] ?? 0);
            $replace = !isset($_REQUEST['replace']) || (string)$_REQUEST['replace'] === '1' || strtolower((string)$_REQUEST['replace']) === 'true';

            if ($plantId <= 0 || $periodId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Plant ID and Period ID are required.']);
                exit;
            }

            // Determine target file: uploaded file or authoritative plant.xlsx on server
            $targetPath = __DIR__ . '/../plant.xlsx';
            if (isset($_FILES['file']) && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $targetPath = $_FILES['file']['tmp_name'];
            }

            $importRes = importPlantExcelToQuantityReco($pdo, $plantId, $periodId, $targetPath, [
                'replace_existing' => $replace,
                'user_id' => $userId
            ]);

            echo json_encode($importRes);
            break;

        // =====================================================================
        // 12. Phase 3: Sync from Real Trial Balance
        // =====================================================================
        case 'sync_tb':
            $plantId = (int)($_REQUEST['plant_id'] ?? 0);
            $periodId = (int)($_REQUEST['period_id'] ?? 0);
            if ($plantId <= 0 || $periodId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Plant ID and Period ID are required.']);
                exit;
            }

            $syncRes = syncTrialBalanceToQuantityReco($pdo, $plantId, $periodId, [
                'user_id' => $userId
            ]);

            echo json_encode($syncRes);
            break;

        // =====================================================================
        // 13. Phase 3: Authoritative 7-Level Reconciliation Audit
        // =====================================================================
        case 'reconcile_excel':
            $plantId = (int)($_REQUEST['plant_id'] ?? 0);
            $periodId = (int)($_REQUEST['period_id'] ?? 0);
            $refPath = trim((string)($_REQUEST['file_path'] ?? '')) ?: (__DIR__ . '/../plant.xlsx');

            if ($plantId <= 0 || $periodId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Plant ID and Period ID are required.']);
                exit;
            }

            $recoRes = reconcileWithPlantExcel($pdo, $plantId, $periodId, $refPath);
            echo json_encode(array_merge(['ok' => true], $recoRes));
            break;

        default:
            echo json_encode([
                'ok' => false,
                'error' => "Unrecognized action '{$action}'. Supported actions: get_cost_centres, save_cost_centre, get_mappings, save_mapping, delete_mapping, get_allocation_rules, save_allocation_rule, delete_allocation_rule, generate, get_reco, get_exceptions, trace_source, import_source_records, import_plant_excel, sync_tb, reconcile_excel."
            ]);
            break;
    }


} catch (InvalidArgumentException $ie) {
    if (ob_get_length()) ob_clean();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $ie->getMessage()]);
} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    error_log("Quantity Reco API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
