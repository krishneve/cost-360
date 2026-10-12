<?php
$content = file_get_contents('config/auth.php');

$oldPlantFunc = <<<'OLD'
if (!function_exists('userCanAccessPlant')) {
    function userCanAccessPlant($userId, $companyId, $divisionId, $plantId)
    {
        if (!$userId || !$companyId || !$divisionId || !$plantId) return false;
        $plants = getUserAccessiblePlants($userId, $companyId, $divisionId);
        foreach ($plants as $p) {
            if ((string)$p['plant_id'] === (string)$plantId) {
                return true;
            }
        }
        return false;
    }
}
OLD;

$newPlantFunc = <<<'NEW'
if (!function_exists('userCanAccessPlant')) {
    function userCanAccessPlant($userId, $companyId = null, $divisionId = null, $plantId = null)
    {
        // Handle argument shifting if plantId is passed in 2nd or 4th position
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
NEW;

$oldWhFunc = <<<'OLD2'
if (!function_exists('userCanAccessWarehouse')) {
    function userCanAccessWarehouse($userId, $companyId, $divisionId, $warehouseId, $plantId = null)
    {
        if (!$userId || !$companyId || !$divisionId || !$warehouseId) return false;
        $warehouses = getUserAccessibleWarehouses($userId, $companyId, $divisionId, $plantId);
        foreach ($warehouses as $w) {
            if ((string)$w['warehouse_id'] === (string)$warehouseId) {
                return true;
            }
        }
        return false;
    }
}
OLD2;

$newWhFunc = <<<'NEW2'
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

if (strpos($content, $oldPlantFunc) !== false) {
    $content = str_replace($oldPlantFunc, $newPlantFunc, $content);
    echo "Updated userCanAccessPlant.\n";
} else {
    echo "userCanAccessPlant pattern not found.\n";
}

if (strpos($content, $oldWhFunc) !== false) {
    $content = str_replace($oldWhFunc, $newWhFunc, $content);
    echo "Updated userCanAccessWarehouse.\n";
} else {
    echo "userCanAccessWarehouse pattern not found.\n";
}

file_put_contents('config/auth.php', $content);
