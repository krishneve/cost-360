<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
$_SESSION['user_id'] = 3;
$_SESSION['username'] = 'aarav.cma@cost360.com';
$_SESSION['email'] = 'aarav.cma@cost360.com';
$_SESSION['roles'] = ['CMA / Cost Administrators'];
$_SESSION['active_customer_id'] = 1;
$_SESSION['active_customer_name'] = 'Omkar Industries';
$_SESSION['active_company_id'] = 2;
$_SESSION['active_company_code'] = 'ABC02';
$_SESSION['active_company_name'] = 'ABC Components Division';
$_SESSION['active_division_id'] = 3;
$_SESSION['active_division_name'] = 'Machining Division';
$_SESSION['active_period_id'] = 1;
$_SESSION['active_period_name'] = 'November 2026';

$_GET['page'] = 'mapping';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Test requireActiveOrganizationContext
echo "Calling requireActiveOrganizationContext...\n";
requireActiveOrganizationContext();
echo "Context OK!\n";

$mappingData = getMappingConfigurationsData();
echo "Mapping configurations returned " . count($mappingData['mappings']) . " mappings.\n";
echo "Active DB: " . $mappingData['database_name'] . "\n";
echo "Active Company: " . $mappingData['company_code'] . "\n";
