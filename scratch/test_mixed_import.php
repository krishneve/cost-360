<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$globalPdo = getDbConnection();
$cid = 997;
$dbName = "cust" . $cid;
try {
    $globalPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
} catch (Throwable $e) {}
$globalPdo->exec("DELETE FROM customer_databases WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM customers WHERE customer_id = {$cid}");

$globalPdo->prepare("
    INSERT INTO customers (customer_id, customer_code, customer_name, status)
    VALUES (:cid, 'TEST997', 'Test Customer 997', 'Active')
")->execute([':cid' => $cid]);

$res = provisionCustomerDatabase($globalPdo, $cid, 'TEST997');

$importCode = 'TEST-IMP-MIXED-' . time();
$uploadId = dbQuery(
    "INSERT INTO public.uploads(import_code, uploaded_by, username, status) VALUES(?, 1, 'admin@cost360.com', 'Ready') RETURNING id",
    [$importCode]
)->fetchColumn();

$testDir = __DIR__ . '/../uploads/temp/test/';
if (!is_dir($testDir)) mkdir($testDir, 0755, true);
$csvPath = $testDir . 'test_mixed_import.csv';
// Row 1: Valid, Row 2: Missing item_code, Row 3: Valid, Row 4: Duplicate item_code (same as 1), Row 5: Valid
$csvData = "item_code,item_name,uom\n" .
           "ITEM-A1,Good Item 1,KG\n" .
           ",Missing Code,KG\n" .
           "ITEM-A2,Good Item 2,PCS\n" .
           "ITEM-A3,Good Item 3,MTR\n";
file_put_contents($csvPath, $csvData);

$subCatId = dbQuery("SELECT id FROM public.sub_categories WHERE code = 'ITEM_MASTER'")->fetchColumn() ?: 3;

$fileId = dbQuery("
    INSERT INTO public.upload_files(upload_id, original_name, safe_name, file_type, size_bytes, rows_count, columns_count, headers, sub_category_id, status, storage_path)
    VALUES(?, 'test_mixed_import.csv', 'test_mixed_import.csv', 'CSV', 150, 4, 3, '[\"item_code\",\"item_name\",\"uom\"]'::jsonb, ?, 'Ready', ?) RETURNING id
", [$uploadId, $subCatId, $csvPath])->fetchColumn();

$mockUser = [
    'user_id' => 1,
    'username' => 'admin@cost360.com',
    'email' => 'admin@cost360.com',
    'customer_id' => $cid
];
$_SESSION['user_id'] = 1;
$_SESSION['active_customer_id'] = $cid;

$importRes = executeDataImport($uploadId, $mockUser);
echo "Mixed Import Result: " . json_encode($importRes, JSON_PRETTY_PRINT) . "\n";

$custPdo = getCustomerDbConnection($dbName);
$rows = $custPdo->query("SELECT * FROM public.items ORDER BY item_id")->fetchAll(PDO::FETCH_ASSOC);
echo "Imported items count: " . count($rows) . "\n";
print_r($rows);

// Clean up
try {
    $globalPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
} catch (Throwable $e) {}
$globalPdo->exec("DELETE FROM customer_databases WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM customers WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM uploads WHERE id = {$uploadId}");
