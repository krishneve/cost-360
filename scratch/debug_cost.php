<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$globalPdo = getDbConnection();
$adminUser = $globalPdo->query("SELECT * FROM users WHERE email = 'admin@cost360.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$_SESSION['active_customer_id'] = 1;
$_SESSION['active_customer_code'] = 'CUST001';
$_SESSION['active_company_id'] = 1;
$_SESSION['active_company_code'] = 'ABC02';
$_SESSION['active_plant_id'] = 1;
$_SESSION['active_location_code'] = 'LOC001';
$_SESSION['active_location_type'] = 'plant';
$_SESSION['active_period_id'] = 4;
$_SESSION['active_period_name'] = 'October 2026';

$ctx = getUserCustomerDbContext($adminUser);
$cols = $ctx['pdo']->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'standard_cost'")->fetchAll(PDO::FETCH_COLUMN);
echo "standard_cost columns: " . implode(', ', $cols) . PHP_EOL;

$tmpDir = __DIR__ . '/../uploads/temp/test_crit/';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

$costItemCode = 'ITEM-COST-TEST';
$csvCost1 = "customer_code,company_code,division_code,location_code,product_code,std_material,std_conversion,std_total_cost,std_revision_date\n";
$csvCost1 .= "CUST001,ABC02,DIV001,LOC001,{$costItemCode},10.00,5.00,15.00,2026-04-01\n";

$fCost1 = $tmpDir . 'cost1.csv';
file_put_contents($fCost1, $csvCost1);

$anaCost1 = analyzeUploadedFiles([
    'name' => ['05. STANDARD_COST.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fCost1],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fCost1)]
], $adminUser);

echo "analyze: " . json_encode($anaCost1) . PHP_EOL;

$imp1 = executeDataImport($anaCost1['upload_id'], $adminUser, [$anaCost1['files'][0]['id']], null, '2026-04-01', '2026-09-30');
echo "imp1: " . json_encode($imp1) . PHP_EOL;

$cnt = $ctx['pdo']->query("SELECT count(*) FROM standard_cost WHERE product_code = '{$costItemCode}'")->fetchColumn();
echo "Inserted rows count: {$cnt}" . PHP_EOL;
