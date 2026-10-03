<?php
require 'c:/xampp/htdocs/cost-360-main/config/database.php'; 
require 'c:/xampp/htdocs/cost-360-main/config/auth.php'; 
session_start(); 
$_SESSION['user_id']=2; 
$_SESSION['role_id']=2; 
$_SESSION['customer_id']=1; 
$_GET['action']='get_hierarchy'; 

ob_start();
require 'c:/xampp/htdocs/cost-360-main/admin/cma/api.php'; 
$out = ob_get_clean();

$data = json_decode($out, true);
if ($data && $data['success']) {
    echo "Hierarchy fetched successfully.\n";
    echo "Companies: " . count($data['companies']) . "\n";
    echo "Divisions: " . count($data['divisions']) . "\n";
    echo "Plants: " . count($data['plants']) . "\n";
    echo "Warehouses: " . count($data['warehouses']) . "\n";
} else {
    echo "Error fetching hierarchy:\n";
    echo $out;
}
