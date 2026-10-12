<?php
/**
 * Test full Upload Centre Data Import flow with SAVEPOINT isolation
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$globalPdo = getDbConnection();

// Set up Customer 995
$cid = 995;
$dbName = "cust" . $cid;
try {
    $globalPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
} catch (Throwable $e) {}
$globalPdo->exec("DELETE FROM customer_databases WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM customers WHERE customer_id = {$cid}");

$globalPdo->prepare("
    INSERT INTO customers (customer_id, customer_code, customer_name, status)
    VALUES (:cid, 'TEST995', 'Test Customer 995', 'Active')
")->execute([':cid' => $cid]);

// Provision Customer 995 DB (initially empty)
$res = provisionCustomerDatabase($globalPdo, $cid, 'TEST995');
echo "Provision status: " . json_encode($res) . "\n";

// Create a mock upload run
$importCode = 'TEST-IMP-' . time();
$uploadId = dbQuery(
    "INSERT INTO public.uploads(import_code, uploaded_by, username, status) VALUES(?, 1, 'admin@cost360.com', 'Ready') RETURNING id",
    [$importCode]
)->fetchColumn();

// Write a test CSV file containing some valid items and 1 malformed row
$testDir = __DIR__ . '/../uploads/temp/test/';
if (!is_dir($testDir)) mkdir($testDir, 0755, true);
$csvPath = $testDir . 'test_import_savepoint.csv';
$csvData = "item_code,item_name,uom,rate\n" .
           "ITEM-001,Steel Rod 10mm,KG,55.50\n" .
           "ITEM-002,Aluminium Ingot,KG,180.00\n" .
           ",Missing Code Item,PCS,10.00\n" .
           "ITEM-003,Bearing 6204,PCS,95.00\n";
file_put_contents($csvPath, $csvData);

// Subcategory for Item Master
$subCatId = dbQuery("SELECT id FROM public.sub_categories WHERE code = 'ITEM_MASTER'")->fetchColumn() ?: 3;

$fileId = dbQuery("
    INSERT INTO public.upload_files(upload_id, original_name, safe_name, file_type, size_bytes, rows_count, columns_count, headers, sub_category_id, status, storage_path)
    VALUES(?, 'test_import_savepoint.csv', 'test_import_savepoint.csv', 'CSV', 150, 4, 4, '[\"item_code\",\"item_name\",\"uom\",\"rate\"]'::jsonb, ?, 'Ready', ?) RETURNING id
", [$uploadId, $subCatId, $csvPath])->fetchColumn();

$mockUser = [
    'user_id' => 1,
    'username' => 'admin@cost360.com',
    'email' => 'admin@cost360.com',
    'customer_id' => $cid
];
$_SESSION['user_id'] = 1;
$_SESSION['active_customer_id'] = $cid;

// Execute Import
echo "Executing Data Import for upload $uploadId...\n";
$importRes = executeDataImport($uploadId, $mockUser);
echo "Import Result: " . json_encode($importRes, JSON_PRETTY_PRINT) . "\n";

// Verify rows in Customer 995 DB
$custPdo = getCustomerDbConnection($dbName);
$rows = $custPdo->query("SELECT item_code, item_name, uom, rate FROM public.items ORDER BY item_id")->fetchAll(PDO::FETCH_ASSOC);
echo "Imported items in {$dbName}.items:\n";
print_r($rows);

// Clean up test customer DB
try {
    $globalPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
} catch (Throwable $e) {}
$globalPdo->exec("DELETE FROM customer_databases WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM customers WHERE customer_id = {$cid}");
$globalPdo->exec("DELETE FROM uploads WHERE id = {$uploadId}");

if ($importRes['ok'] && count($rows) === 3 && $importRes['failed'] === 1) {
    echo "\n>>> VERIFICATION SUCCESS: 3 rows imported, 1 invalid row safely caught with SAVEPOINT, 0 transaction aborts! <<<\n";
} else {
    echo "\n>>> VERIFICATION FAILED <<<\n";
}
