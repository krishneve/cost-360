<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Test with rohan (user_id = 4)
$userId = 4;
$companyId = 1;
$divisionId = 1;
$plantId = 1;
$warehouseId = 1;

echo "userCanAccessPlant(4, 1, 1, 1): " . (userCanAccessPlant($userId, $companyId, $divisionId, $plantId)?'TRUE':'FALSE') . "\n";
echo "userCanAccessPlant(4, 1, null, 1): " . (userCanAccessPlant($userId, $companyId, null, $plantId)?'TRUE':'FALSE') . "\n";
echo "userCanAccessWarehouse(4, 1, 1, 1): " . (userCanAccessWarehouse($userId, $companyId, $divisionId, $warehouseId)?'TRUE':'FALSE') . "\n";
echo "userCanAccessWarehouse(4, 1, null, 1): " . (userCanAccessWarehouse($userId, $companyId, null, $warehouseId)?'TRUE':'FALSE') . "\n";
echo "userCanAccessLocation(4, 1, null, 'PLANT', 1): " . (userCanAccessLocation($userId, $companyId, null, 'PLANT', $plantId)?'TRUE':'FALSE') . "\n";
