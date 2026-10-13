<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
$userId = 4;
$plantId = 1;

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM plants p
    INNER JOIN divisions d ON d.division_id = p.division_id
    INNER JOIN companies c ON c.company_id = d.company_id
    INNER JOIN user_organization_access uoa ON (
        (uoa.customer_id = c.customer_id AND uoa.company_id IS NULL AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
        OR (uoa.company_id = c.company_id AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
        OR (uoa.division_id = d.division_id AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
        OR (uoa.plant_id = p.plant_id)
        OR (uoa.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses w WHERE w.warehouse_id = uoa.warehouse_id AND w.plant_id = p.plant_id))
    )
    WHERE p.plant_id = :plant_id
      AND uoa.user_id = :user_id
      AND UPPER(uoa.status) = 'ACTIVE'
      AND CURRENT_DATE >= uoa.valid_from
      AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
      AND UPPER(p.status) = 'ACTIVE'
");
$stmt->execute([':plant_id' => $plantId, ':user_id' => $userId]);
echo "Direct Plant access for user 4 on plant 1: " . ((int)$stmt->fetchColumn() > 0 ? "TRUE" : "FALSE") . "\n";
