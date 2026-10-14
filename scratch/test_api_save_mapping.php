<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

// Test API action 'save_mapping' directly
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;
$_SESSION['role'] = 'PLATFORM_ADMIN';
$_SESSION['active_customer_id'] = 1;

// Create dummy file
$uploadId = dbQuery("INSERT INTO public.uploads(import_code, uploaded_by, username, status) VALUES(?, 1, 'admin', 'Analyzing') RETURNING id", ['API-TEST-' . uniqid()])->fetchColumn();

$tempCsvPath = __DIR__ . '/api_test_file.csv';
file_put_contents($tempCsvPath, "col_a,col_b\n1,2\n");

$fileId = dbQuery("
    INSERT INTO public.upload_files(
        upload_id, original_name, safe_name, file_type, size_bytes,
        rows_count, columns_count, headers, validation_status, status, sheet_name, storage_path
    ) VALUES(?, 'api_test.csv', 'api_test.csv', 'CSV', 100, 1, 2, '[\"col_a\",\"col_b\"]'::jsonb, 'Review', 'Review', 'api_test.csv', ?)
    RETURNING id
", [$uploadId, $tempCsvPath])->fetchColumn();

$_POST = [
    'action'   => 'save_mapping',
    'file_id'  => $fileId,
    'mapping'  => json_encode(['col_a' => 'item_code', 'col_b' => 'item_name'])
];

ob_start();
include __DIR__ . '/../api/upload_api.php';
$output = ob_get_clean();

echo "API response:\n" . $output . "\n";
$json = json_decode($output, true);
assert(!empty($json['ok']), "API save_mapping failed!");

// Verify DB
$savedInDb = dbQuery("SELECT column_mappings FROM public.upload_files WHERE id = ?", [$fileId])->fetchColumn();
echo "Saved in upload_files: " . $savedInDb . "\n";
assert(strpos($savedInDb, 'item_code') !== false, "column_mappings not updated in DB!");

// Cleanup
dbQuery("DELETE FROM public.upload_files WHERE upload_id = ?", [$uploadId]);
dbQuery("DELETE FROM public.uploads WHERE id = ?", [$uploadId]);
if (file_exists($tempCsvPath)) unlink($tempCsvPath);

echo "API TEST PASSED!\n";
