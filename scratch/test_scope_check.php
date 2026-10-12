<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

echo "--- Testing scope functions for user 4 (rohan, Customer Admin for Customer 1) ---\n";
$uid = 4;
$comp = 1;
$div = 1;
$plant = 1;
$period = 1;

echo "isPlatformAdmin: " . (isPlatformAdmin($uid)?'YES':'NO') . "\n";
echo "isCmaAdmin: " . (isCmaAdmin($uid)?'YES':'NO') . "\n";
echo "userCanAccessCustomer(4, 1): " . (userCanAccessCustomer($uid, 1)?'YES':'NO') . "\n";
echo "userCanAccessCompany(4, 1): " . (userCanAccessCompany($uid, 1)?'YES':'NO') . "\n";
echo "userCanAccessCompany(1, 4) [inverted]: " . (userCanAccessCompany(1, $uid)?'YES':'NO') . "\n";
echo "userCanAccessLocation(4, 1, 1, 'plant', 1): " . (userCanAccessLocation($uid, $comp, $div, 'plant', $plant)?'YES':'NO') . "\n";
echo "userCanAccessPeriod(1, 4): " . (userCanAccessPeriod($period, $uid)?'YES':'NO') . "\n";
