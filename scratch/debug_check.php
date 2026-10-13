<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$js = file_get_contents(__DIR__ . '/../assets/js/upload_centre.js');
$lines = explode("\n", $js);
foreach ($lines as $i => $line) {
    if (stripos($line, 'Master Category') !== false) {
        echo ($i + 1) . ': ' . trim($line) . PHP_EOL;
    }
}

// Check analyzeUploadedFiles error
$globalPdo = getDbConnection();
$adminUser = $globalPdo->query("SELECT * FROM users WHERE email = 'admin@cost360.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$tmpDir = __DIR__ . '/../uploads/temp/test_crit/';
$fOut = $tmpDir . 'out_of_range.csv';
$anaOut = analyzeUploadedFiles([
    'name' => ['06.MATERIAL TRANSACTION.csv'],
    'type' => ['text/csv'],
    'tmp_name' => [$fOut],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($fOut)]
], $adminUser);
echo "anaOut result: " . json_encode($anaOut) . PHP_EOL;
