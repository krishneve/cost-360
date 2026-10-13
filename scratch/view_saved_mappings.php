<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$ctx = getUserCustomerDbContext(['user_id' => 1], 1);
$custPdo = $ctx['pdo'];
$dbName = $ctx['database_name'];

echo "=== MAPPINGS IN CUSTOMER DATABASE: {$dbName} ===\n\n";
$st = $custPdo->query("SELECT id, company_code, target_table, sheet_name, mapping, updated_at FROM public.saved_mappings ORDER BY id");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: " . $r['id'] . "\n";
    echo "Company Code: " . $r['company_code'] . "\n";
    echo "Target Table: " . $r['target_table'] . "\n";
    echo "Sheet / File: " . $r['sheet_name'] . "\n";
    echo "Updated At: " . $r['updated_at'] . "\n";
    $m = is_array($r['mapping']) ? $r['mapping'] : json_decode($r['mapping'], true);
    echo "Mapping (JSONB):\n" . json_encode($m, JSON_PRETTY_PRINT) . "\n";
    echo str_repeat('-', 50) . "\n";
}
