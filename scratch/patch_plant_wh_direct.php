<?php
$content = file_get_contents('config/auth.php');

$patternPlant = '/if\s*\(!function_exists\(\'userCanAccessPlant\'\)\)\s*\{[\s\S]*?return false;\s*\}\s*\}/';
$replacementPlant = <<<'NEW1'
if (!function_exists('userCanAccessPlant')) {
    function userCanAccessPlant($userId, $companyId = null, $divisionId = null, $plantId = null)
    {
        if ($plantId === null) {
            $plantId = $companyId;
            $companyId = null;
        }

        if (!$userId || !$plantId) return false;
        if (isPlatformAdmin($userId) || isCmaAdmin($userId)) return true;

        try {
            $pdo = getDbConnection();
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
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
NEW1;

$patternWh = '/if\s*\(!function_exists\(\'userCanAccessWarehouse\'\)\)\s*\{[\s\S]*?return false;\s*\}\s*\}/';
$replacementWh = <<<'NEW2'
if (!function_exists('userCanAccessWarehouse')) {
    function userCanAccessWarehouse($userId, $companyId = null, $divisionId = null, $warehouseId = null, $plantId = null)
    {
        if ($warehouseId === null) {
            $warehouseId = $companyId;
            $companyId = null;
        }

        if (!$userId || !$warehouseId) return false;
        if (isPlatformAdmin($userId) || isCmaAdmin($userId)) return true;

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM warehouses w
                INNER JOIN plants p ON p.plant_id = w.plant_id
                INNER JOIN divisions d ON d.division_id = p.division_id
                INNER JOIN companies c ON c.company_id = d.company_id
                INNER JOIN user_organization_access uoa ON (
                    (uoa.customer_id = c.customer_id AND uoa.company_id IS NULL AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.company_id = c.company_id AND uoa.division_id IS NULL AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.division_id = d.division_id AND uoa.plant_id IS NULL AND uoa.warehouse_id IS NULL)
                    OR (uoa.plant_id = p.plant_id AND uoa.warehouse_id IS NULL)
                    OR (uoa.warehouse_id = w.warehouse_id)
                )
                WHERE w.warehouse_id = :wh_id
                  AND uoa.user_id = :user_id
                  AND UPPER(uoa.status) = 'ACTIVE'
                  AND CURRENT_DATE >= uoa.valid_from
                  AND (uoa.valid_to IS NULL OR CURRENT_DATE <= uoa.valid_to)
                  AND UPPER(w.status) = 'ACTIVE'
            ");
            $stmt->execute([':wh_id' => $warehouseId, ':user_id' => $userId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
NEW2;

$newContent = preg_replace($patternPlant, $replacementPlant, $content, 1, $c1);
$newContent = preg_replace($patternWh, $replacementWh, $newContent, 1, $c2);

if ($c1 > 0 && $c2 > 0) {
    file_put_contents('config/auth.php', $newContent);
    echo "Successfully updated userCanAccessPlant and userCanAccessWarehouse!\n";
} else {
    echo "Regex failed: c1=$c1, c2=$c2\n";
}
