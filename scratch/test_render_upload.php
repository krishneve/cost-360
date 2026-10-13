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

ob_start();
include __DIR__ . '/../upload-centre.php';
$output = ob_get_clean();
echo "Render status: " . (strlen($output) > 500 ? "SUCCESS (length: " . strlen($output) . " bytes)" : "OUTPUT: " . substr($output, 0, 300)) . "\n";
if (strpos($output, 'Access Denied') !== false) {
    echo "WARNING: Access Denied still triggered!\n";
} else {
    echo "SUCCESS: Upload Centre rendered completely without Access Denied!\n";
}
