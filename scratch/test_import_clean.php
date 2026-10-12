<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$globalPdo = getDbConnection();
$cid = 996;
$dbName = "cust" . $cid;
try {
    $globalPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
} catch (Throwable $e) {}
$globalPdo->exec("DELETE FROM customer_databases WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM customers WHERE customer_id = {$cid}");

$globalPdo->prepare("
    INSERT INTO customers (customer_id, customer_code, customer_name, status)
    VALUES (:cid, 'TEST996', 'Test Customer 996', 'Active')
")->execute([':cid' => $cid]);

$res = provisionCustomerDatabase($globalPdo, $cid, 'TEST996');

$importCode = 'TEST-IMP-' . time();
$uploadId = dbQuery(
    "INSERT INTO public.uploads(import_code, uploaded_by, username, status) VALUES(?, 1, 'admin@cost360.com', 'Ready') RETURNING id",
    [$importCode]
)->fetchColumn();

$testDir = __DIR__ . '/../uploads/temp/test/';
if (!is_dir($testDir)) mkdir($testDir, 0755, true);
$csvPath = $testDir . 'test_import_savepoint.csv';
$csvData = "item_code,item_name,uom,rate\n" .
           "ITEM-001,Steel Rod 10mm,KG,55.50\n" .
           "ITEM-002,Aluminium Ingot,KG,180.00\n" .
           "ITEM-003,Bearing 6204,PCS,95.00\n";
file_put_contents($csvPath, $csvData);

$subCatId = dbQuery("SELECT id FROM public.sub_categories WHERE code = 'ITEM_MASTER'")->fetchColumn() ?: 3;

$fileId = dbQuery("
    INSERT INTO public.upload_files(upload_id, original_name, safe_name, file_type, size_bytes, rows_count, columns_count, headers, sub_category_id, status, storage_path)
    VALUES(?, 'test_import_savepoint.csv', 'test_import_savepoint.csv', 'CSV', 150, 3, 4, '[\"item_code\",\"item_name\",\"uom\",\"rate\"]'::jsonb, ?, 'Ready', ?) RETURNING id
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
echo "Import Result: " . json_encode($importRes, JSON_PRETTY_PRINT) . "\n";

$custPdo = getCustomerDbConnection($dbName);
$rows = $custPdo->query("SELECT * FROM public.items ORDER BY item_id")->fetchAll(PDO::FETCH_ASSOC);
echo "Imported items in {$dbName}.items:\n";
print_r($rows);

// Clean up
try {
    $globalPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
} catch (Throwable $e) {}
$globalPdo->exec("DELETE FROM customer_databases WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM customers WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM uploads WHERE id = {$uploadId}");
