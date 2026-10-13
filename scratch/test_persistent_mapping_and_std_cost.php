<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

echo "=== TESTING PERSISTENT MAPPING & STANDARD COST PERIOD FIX ===\n\n";

// 1. Test getSavedMapping & saveCustomMapping
$adminUser = [
    'user_id' => 1,
    'username' => 'admin@cost360.com',
    'role_id' => 1,
    'role' => 'PLATFORM_ADMIN',
    'customer_id' => 1
];

// Test direct DB query to public.saved_mappings
$testMapping = [
    'product_code' => 'product_code',
    'std_material' => 'std_material',
    'std_conversion' => 'std_conversion',
    'std_total_cost' => 'std_total_cost',
    'std_rev_date' => 'std_revision_date'
];

echo "1. Testing saveCustomMapping()...\n";

// Create a dummy upload & upload_file for testing
$importCode = 'TEST-MAP-' . uniqid();
$uploadId = dbQuery("
    INSERT INTO public.uploads(import_code, uploaded_by, username, status)
    VALUES(?, 1, 'admin', 'Analyzing')
    RETURNING id
", [$importCode])->fetchColumn();

// Create a CSV file with 2025-04-01 dates
$tempCsvPath = __DIR__ . '/test_06_std_cost.csv';
$fp = fopen($tempCsvPath, 'w');
fputcsv($fp, ['product_code', 'std_material', 'std_conversion', 'std_total_cost', 'std_rev_date']);
fputcsv($fp, ['PROD-001', '100.50', '25.00', '125.50', '2025-04-01']);
fputcsv($fp, ['PROD-002', '200.00', '50.00', '250.00', '2024-04-01']);
fclose($fp);

$fileId = dbQuery("
    INSERT INTO public.upload_files(
        upload_id, original_name, safe_name, file_type, size_bytes,
        rows_count, columns_count, headers, validation_status, status, sheet_name, storage_path
    ) VALUES(?, '06_standard_cost.csv', 'test_std_cost.csv', 'CSV', 1024, 12, 5, ?::jsonb, 'Review', 'Review', '06_standard_cost.csv', ?)
    RETURNING id
", [$uploadId, json_encode(array_keys($testMapping)), $tempCsvPath])->fetchColumn();

// Link sub_category for standard_cost
$stdCostSubId = dbQuery("SELECT id FROM public.sub_categories WHERE code = '05_STANDARD_COST' OR name ILIKE '%Standard Cost%' LIMIT 1")->fetchColumn();
if ($stdCostSubId) {
    dbQuery("UPDATE public.upload_files SET sub_category_id = ? WHERE id = ?", [$stdCostSubId, $fileId]);
}

$saveRes = saveCustomMapping($fileId, $testMapping, 1, $adminUser);
echo "saveCustomMapping result: " . json_encode($saveRes) . "\n";

assert($saveRes['ok'] === true, "saveCustomMapping failed!");

echo "\n2. Testing getSavedMapping()...\n";
$retrieved = getSavedMapping(1, 'standard_cost', '06_standard_cost.csv');
echo "getSavedMapping result: " . json_encode($retrieved) . "\n";

assert(!empty($retrieved), "getSavedMapping returned null!");
assert(($retrieved['std_rev_date'] ?? '') === 'std_revision_date', "Mapping did not match!");
echo "=> getSavedMapping PASS!\n";

echo "\n3. Testing validateUploadPeriodDates with standard_cost exemption...\n";

// Validate against November 2026 (2026-11-01 to 2026-11-30)
$dateCheck = validateUploadPeriodDates($uploadId, [$fileId], '2026-11-01', '2026-11-30');
echo "validateUploadPeriodDates result: " . json_encode($dateCheck) . "\n";

assert(empty($dateCheck['has_violations']), "Standard cost was not exempted from period date validation!");
echo "=> validateUploadPeriodDates exemption PASS!\n";

echo "\n4. Testing getFilePreviewData()...\n";
$previewRes = getFilePreviewData($fileId, $adminUser, 1, '2026-11-01', '2026-11-30');
echo "Preview is_saved_mapping: " . var_export($previewRes['is_saved_mapping'] ?? false, true) . "\n";
echo "Preview date_violations count: " . count($previewRes['date_violations'] ?? []) . "\n";
echo "Preview auto_mapping: " . json_encode($previewRes['auto_mapping'] ?? []) . "\n";

assert(!empty($previewRes['is_saved_mapping']), "is_saved_mapping should be true!");
assert(empty($previewRes['date_violations']), "date_violations should be empty for standard_cost!");
assert(($previewRes['auto_mapping']['std_rev_date'] ?? '') === 'std_revision_date', "std_rev_date should map to std_revision_date from saved mapping!");
echo "=> getFilePreviewData PASS!\n";

// Cleanup test records
dbQuery("DELETE FROM public.upload_files WHERE upload_id = ?", [$uploadId]);
dbQuery("DELETE FROM public.uploads WHERE id = ?", [$uploadId]);
if (file_exists($tempCsvPath)) unlink($tempCsvPath);

echo "\nALL TESTS PASSED SUCCESSFULLY!\n";
