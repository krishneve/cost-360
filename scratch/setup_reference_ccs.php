<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/quantity_reco.php';

$pdo = getDbConnection();

// Reference plant: plant_id 35 (Plant A)
$plantId = 35;
$periodId = 1;

// 1. Check or set up the 23 Cost Centres for Plant 35
$referenceCCs = [
    'Sales', 'Export Sales', 'Tool', 'Export Benefits', 'Export',
    'Self Consumption', 'RMC', 'MOH', 'Packing Cost', 'QC',
    'Consumable', 'Repairs & Maintenance', 'Direct Emp Cost', 'Direct Expenses', 'Utility',
    'POH', 'Depreciation', 'Change in stock', 'AOH', 'SOH',
    'Finance cost', 'Reco', 'Corporate'
];

echo "Setting up 23 Cost Centres for Plant {$plantId}...\n";
$order = 1;
foreach ($referenceCCs as $ccName) {
    $code = 'CC_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $ccName));
    saveCostCentre($pdo, [
        'plant_id' => $plantId,
        'cost_center_code' => $code,
        'cost_center_name' => $ccName,
        'cost_basis_code'  => $ccName,
        'display_order'    => $order++,
        'is_active'        => true
    ]);
}

$activeCCs = getPlantCostCentres($pdo, $plantId, true);
echo "Plant {$plantId} now has " . count($activeCCs) . " active Cost Centres.\n";
