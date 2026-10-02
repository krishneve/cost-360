<?php
/**
 * Cost360 - Upload Centre REST API Endpoint
 * Handles file ingestion, preview requests, column mapping, and dedicated Customer DB (cust<ID>) import actions.
 * Strictly protected by session authentication via requireAuth() and RBAC permissions.
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

// Verify authentication before processing any request
if (!isLoggedIn()) {
    if (ob_get_length()) ob_clean();
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized. Please log in to Cost360.']);
    exit;
}

$user = getAuthUser();
if (!$user) {
    if (ob_get_length()) ob_clean();
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unable to identify authenticated user context.']);
    exit;
}

// Verify User Role / Menu Permission for Upload Centre
$userId = (int)$user['user_id'];
$hasUploadAccess = isPlatformAdmin($userId) || 
                   isCmaAdmin($userId) || 
                   userHasMenuAccess('UPLOAD_CENTRE', $userId) || 
                   userHasPermission('can_upload', 'UPLOAD_CENTRE', $userId) ||
                   userHasPermission('can_view', 'UPLOAD_CENTRE', $userId);

if (!$hasUploadAccess) {
    if (ob_get_length()) ob_clean();
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access Denied: You do not have permission to access the Upload Centre.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (ob_get_length()) ob_clean();
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST request method required.']);
    exit;
}

try {
    $action = $_POST['action'] ?? '';

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
            $result = getFilePreviewData($fileId, $user);
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
            $result = executeDataImport($uploadId, $user);
            if (ob_get_length()) ob_clean();
            echo json_encode($result);
            break;

        case 'stats':
            $stats = getUploadCentreStats($user['user_id']);
            if (ob_get_length()) ob_clean();
            echo json_encode(['ok' => true, 'stats' => $stats]);
            break;

        case 'dropdowns':
            $groups = getMasterGroupsAndSubCategories();
            if (ob_get_length()) ob_clean();
            echo json_encode(['ok' => true, 'master_groups' => $groups]);
            break;

        case 'update_classification':
            $fileId = (int)($_POST['file_id'] ?? 0);
            $subId = (int)($_POST['sub_category_id'] ?? 0);
            $res = updateFileClassification($fileId, $subId);
            if (ob_get_length()) ob_clean();
            echo json_encode($res);
            break;

        case 'errors':
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $errors = getImportErrorsByUploadId($uploadId);
            if (ob_get_length()) ob_clean();
            echo json_encode(['ok' => true, 'errors' => $errors]);
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
