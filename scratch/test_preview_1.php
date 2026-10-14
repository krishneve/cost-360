<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

session_start();
$_SESSION['user_id'] = 3;
$_SESSION['username'] = 'aarav.cma@cost360.com';
$_SESSION['active_customer_id'] = 1;
$_SESSION['active_company_id'] = 2;
$_SESSION['active_company_code'] = 'ABC02';

$user = getAuthUser();

echo "Testing getFilePreviewData(1)...\n";
$res = getFilePreviewData(1, $user);
echo "Preview result:\n";
print_r($res);
