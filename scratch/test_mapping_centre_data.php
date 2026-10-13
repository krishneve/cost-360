<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$ctx = getUserCustomerDbContext(['user_id' => 1], 1);
$custPdo = $ctx['pdo'];

// Check current rows
$st = $custPdo->query("SELECT id, company_code, target_table, sheet_name FROM public.saved_mappings ORDER BY target_table, id");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo "Current rows in cost360_customer_cust001.saved_mappings (" . count($rows) . "):\n";
foreach ($rows as $r) {
    echo "  [ID {$r['id']}] {$r['company_code']} | {$r['target_table']} | {$r['sheet_name']}\n";
}
