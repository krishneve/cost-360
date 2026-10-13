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

        if ($companyId) {
            $plants = getUserAccessiblePlants($companyId, $divisionId, $userId);
            foreach ($plants as $p) {
                if ((string)$p['plant_id'] === (string)$plantId) {
                    return true;
                }
            }
        }

        // Check across all plants accessible by user
        $allPlants = getUserAccessiblePlants(null, null, $userId);
        foreach ($allPlants as $p) {
            if ((string)$p['plant_id'] === (string)$plantId) {
                return true;
            }
        }

        return false;
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

        if ($companyId) {
            $warehouses = getUserAccessibleWarehouses($companyId, $divisionId, $userId, $plantId);
            foreach ($warehouses as $w) {
                if ((string)$w['warehouse_id'] === (string)$warehouseId) {
                    return true;
                }
            }
        }

        // Check across all warehouses accessible by user
        $allWarehouses = getUserAccessibleWarehouses(null, null, $userId);
        foreach ($allWarehouses as $w) {
            if ((string)$w['warehouse_id'] === (string)$warehouseId) {
                return true;
            }
        }

        return false;
    }
}
NEW2;

$newContent = preg_replace($patternPlant, $replacementPlant, $content, 1, $c1);
$newContent = preg_replace($patternWh, $replacementWh, $newContent, 1, $c2);

if ($c1 > 0 && $c2 > 0) {
    file_put_contents('config/auth.php', $newContent);
    echo "Successfully replaced userCanAccessPlant and userCanAccessWarehouse!\n";
} else {
    echo "Regex failed: c1=$c1, c2=$c2\n";
}
