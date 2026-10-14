<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

// Check customer connection function
$custDb = getCustomerDbConnection(1); // or 57
if (!$custDb) {
    $custDb = getCustomerDbConnection(57);
}
echo "Connected to cust db: " . ($custDb ? "YES" : "NO") . "\n";

$tables = ['items', 'work_centers', 'party_master', 'cost_center_master', 'standard_cost', 'bom', 'routing', 'material_transaction', 'downtime', 'finance_transaction', 'trial_balance'];

foreach ($tables as $t) {
    echo "\n=== TABLE: $t ===\n";
    $cols = $custDb->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='$t' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cols)) {
        echo "Table $t not found in cust db, checking public...\n";
        $pdo = getDbConnection();
        $cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='$t' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($cols as $c) {
        echo "  {$c['column_name']} ({$c['data_type']})\n";
    }
}
