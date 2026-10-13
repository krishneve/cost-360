<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$pdo = getCustomerDbConnection('cost360_customer_cust001');
$tables = [
    'items',
    'work_centers',
    'party_master',
    'cost_center_master',
    'standard_cost',
    'bom',
    'routing',
    'material_transaction',
    'downtime',
    'finance_transaction',
    'trial_balance'
];

$defs = getFinalizedCategoryDefinitions();

foreach ($tables as $t) {
    $cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema='public' AND table_name='{$t}' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'column_name');
    
    // Find matching def
    $matchDef = null;
    foreach ($defs as $d) {
        if ($d['target_table'] === $t) {
            $matchDef = $d;
            break;
        }
    }
    
    echo "=========================================================\n";
    echo "TABLE: {$t} (" . ($matchDef['name'] ?? 'Unknown') . ")\n";
    echo "DB Columns (" . count($colNames) . "): " . implode(', ', $colNames) . "\n";
    if ($matchDef) {
        echo "Def Headers (" . count($matchDef['headers']) . "): " . implode(', ', $matchDef['headers']) . "\n";
    }
    echo "\n";
}
