<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Test with rohan (user_id = 4)
$userId = 4;
$companyId = 1;
$divisionId = 1;
$locationType = 'plant';
$locationId = 1;
$periodId = 1;

echo "Test 1 - userCanAccessCompany(4, 1): " . (userCanAccessCompany($userId, $companyId)?'TRUE':'FALSE') . "\n";
echo "Test 2 - userCanAccessCompany(1, 4): " . (userCanAccessCompany($companyId, $userId)?'TRUE':'FALSE') . "\n";
echo "Test 3 - userCanAccessLocation(4, 1, 1, 'plant', 1): " . (userCanAccessLocation($userId, $companyId, $divisionId, $locationType, $locationId)?'TRUE':'FALSE') . "\n";
echo "Test 4 - userCanAccessPeriod(1, 4): " . (userCanAccessPeriod($periodId, $userId)?'TRUE':'FALSE') . "\n";
