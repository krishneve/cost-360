<?php
/**
 * Cost360 - Upload Centre REST API Endpoint
 * Handles file ingestion, preview requests, column mapping, and Neon DB import actions.
 * Strictly protected by Neon Auth session authentication via requireAuth().
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

// Verify authentication before processing any request
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized. Please log in to Cost360.']);
    exit;
}

$user = getAuthUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
                echo json_encode(['ok' => false, 'message' => 'No files uploaded.']);
                exit;
            }
            $result = analyzeUploadedFiles($files, $user);
            echo json_encode($result);
            break;

        case 'preview':
            $fileId = (int)($_POST['id'] ?? 0);
            if (!$fileId) {
                echo json_encode(['ok' => false, 'message' => 'File ID required.']);
                exit;
            }
            $result = getFilePreviewData($fileId);
            echo json_encode($result);
            break;

        case 'import':
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            if (!$uploadId) {
                echo json_encode(['ok' => false, 'message' => 'Upload Run ID required.']);
                exit;
            }
            $result = executeDataImport($uploadId, $user);
            echo json_encode($result);
            break;

        case 'stats':
            $stats = getUploadCentreStats($user['user_id']);
            echo json_encode(['ok' => true, 'stats' => $stats]);
            break;

        case 'dropdowns':
            $groups = getMasterGroupsAndSubCategories();
            echo json_encode(['ok' => true, 'master_groups' => $groups]);
            break;

        case 'update_classification':
            $fileId = (int)($_POST['file_id'] ?? 0);
            $subId = (int)($_POST['sub_category_id'] ?? 0);
            $res = updateFileClassification($fileId, $subId);
            echo json_encode($res);
            break;

        case 'errors':
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $errors = getImportErrorsByUploadId($uploadId);
            echo json_encode(['ok' => true, 'errors' => $errors]);
            break;

        default:
            echo json_encode(['ok' => false, 'message' => 'Unknown API action specified.']);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}
