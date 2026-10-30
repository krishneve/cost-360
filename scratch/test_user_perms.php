<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

echo "User 1 (Platform Admin):\n";
echo "  isPlatformAdmin: " . (isPlatformAdmin(1) ? "YES" : "NO") . "\n";
echo "  isCmaAdmin: " . (isCmaAdmin(1) ? "YES" : "NO") . "\n";
echo "  userHasMenuAccess(QUANTITY_RECO): " . (userHasMenuAccess('QUANTITY_RECO', 1) ? "YES" : "NO") . "\n";
echo "  userHasPermission(EDIT, QUANTITY_RECO): " . (userHasPermission('EDIT', 'QUANTITY_RECO', 1) ? "YES" : "NO") . "\n";

echo "User 2 (CMA Admin):\n";
echo "  isPlatformAdmin: " . (isPlatformAdmin(2) ? "YES" : "NO") . "\n";
echo "  isCmaAdmin: " . (isCmaAdmin(2) ? "YES" : "NO") . "\n";
echo "  userHasMenuAccess(QUANTITY_RECO): " . (userHasMenuAccess('QUANTITY_RECO', 2) ? "YES" : "NO") . "\n";
echo "  userHasPermission(EDIT, QUANTITY_RECO): " . (userHasPermission('EDIT', 'QUANTITY_RECO', 2) ? "YES" : "NO") . "\n";

echo "User 5 (Auditor):\n";
echo "  isPlatformAdmin: " . (isPlatformAdmin(5) ? "YES" : "NO") . "\n";
echo "  isCmaAdmin: " . (isCmaAdmin(5) ? "YES" : "NO") . "\n";
echo "  userHasMenuAccess(QUANTITY_RECO): " . (userHasMenuAccess('QUANTITY_RECO', 5) ? "YES" : "NO") . "\n";
echo "  userHasPermission(EDIT, QUANTITY_RECO): " . (userHasPermission('EDIT', 'QUANTITY_RECO', 5) ? "YES" : "NO") . "\n";
echo "  userHasPermission(VIEW, QUANTITY_RECO): " . (userHasPermission('VIEW', 'QUANTITY_RECO', 5) ? "YES" : "NO") . "\n";
