<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/quantity_reco.php';

$pdo = getDbConnection();
$plantId = 38;
$periodId = 1;

// 1. Seed rules for Plant 38
echo "--> Seeding rules for Plant 38...\n";
seedPlantReferenceAllocationRules($pdo, $plantId);

// 2. Query rules back
$rules = getPlantCostAllocationRules($pdo, $plantId, $periodId, true);
echo "--> Fetched " . count($rules) . " active rules for Plant 38.\n";
foreach ($rules as $r) {
    echo "    Acc: {$r['account_code']} | Method: {$r['allocation_method']} | CC: {$r['cost_center_name']} | Ratio: {$r['allocation_ratio']} | Driver: {$r['driver_account_code']}\n";
}
