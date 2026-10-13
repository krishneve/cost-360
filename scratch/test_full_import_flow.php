<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

// Setup customer session
$_SESSION['user_id'] = 4;
$_SESSION['username'] = 'rohan.admin@cost360.com';
$_SESSION['active_customer_id'] = 1;
$_SESSION['active_company_id'] = 1;
$_SESSION['active_division_id'] = 1;
$_SESSION['active_plant_id'] = 1;
$_SESSION['active_location_type'] = 'plant';
$_SESSION['active_period_id'] = 1;

$user = [
    'user_id' => 4,
    'username' => 'rohan.admin@cost360.com',
    'email' => 'rohan.admin@cost360.com'
];

$testCsv = __DIR__ . '/test_upload_sample.csv';
file_put_contents($testCsv, "item_code,item_name,item_type,uom\nRM-TEST-02,Test Material 2,Raw Material,PCS\n");

$dummyFiles = [
    'name' => ['test_upload_sample.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$testCsv],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($testCsv)]
];

$analysis = analyzeUploadedFiles($dummyFiles, $user);
echo "Analysis status: " . ($analysis['ok'] ? 'SUCCESS' : 'FAILED: ' . $analysis['message']) . "\n";

if ($analysis['ok']) {
    $uploadId = $analysis['upload_id'];
    $importRes = executeDataImport($uploadId, $user);
    echo "Import status: " . ($importRes['ok'] ? 'SUCCESS' : 'FAILED: ' . $importRes['message']) . "\n";
    print_r($importRes);
}
