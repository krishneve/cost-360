<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['REQUEST_URI'] = '/upload-centre.php';
$_GET['page'] = 'upload';
require_once __DIR__ . '/../config/auth.php';
$_SESSION['user_id'] = 4;
$_SESSION['username'] = 'rohan.admin@cost360.com';
$_SESSION['roles'] = ['Customer Administrator'];
$_SESSION['selected_customer_id'] = 1;
$_SESSION['selected_company_id'] = 1;
$_SESSION['selected_division_id'] = 1;
$_SESSION['selected_period_id'] = 1;
$_SESSION['selected_customer_name'] = 'Omkar Industries';
$_SESSION['selected_company_name'] = 'Omkar Pune';
$_SESSION['selected_division_name'] = 'Automotive Division';
$_SESSION['selected_period_name'] = 'FY 2026-27 Q1';

echo "userHasMenuAccess('UPLOAD_CENTRE', 4): " . (userHasMenuAccess('UPLOAD_CENTRE', 4) ? 'TRUE' : 'FALSE') . "\n";
echo "userHasMenuAccess('UPLOAD_CENTER', 4): " . (userHasMenuAccess('UPLOAD_CENTER', 4) ? 'TRUE' : 'FALSE') . "\n";
echo "userHasMenuAccess('/data/upload', 4): " . (userHasMenuAccess('/data/upload', 4) ? 'TRUE' : 'FALSE') . "\n";
echo "userHasMenuAccess('DATA_MANAGEMENT', 4): " . (userHasMenuAccess('DATA_MANAGEMENT', 4) ? 'TRUE' : 'FALSE') . "\n";
