<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['REQUEST_URI'] = '/mapping-centre.php';
$_GET['page'] = 'mapping';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

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

ob_start();
include __DIR__ . '/../mapping-centre.php';
$html = ob_get_clean();

echo "Length: " . strlen($html) . "\n";
file_put_contents(__DIR__ . '/rendered_mapping.html', $html);
echo "Written to rendered_mapping.html\n";

// Check if dataset names appear in html
$datasets = ['Bill of Materials', 'Item Master Product Master', 'Work Center Master', 'Party Master', 'Cost Center Master', 'Standard Cost', 'Routing Master', 'Material Transactions', 'Machine Downtime', 'Finance Transactions', 'Trial Balance'];
foreach ($datasets as $d) {
    if (strpos($html, $d) !== false) {
        echo "Found: $d\n";
    } else {
        echo "MISSING: $d\n";
    }
}
