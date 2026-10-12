<?php
// Update config/auth.php with flexible userCanAccessLocation
$authContent = file_get_contents('config/auth.php');

$oldLocFunc = <<<'OLD'
if (!function_exists('userCanAccessLocation')) {
    function userCanAccessLocation($userId, $companyId, $divisionId, $locationType, $locationId)
    {
        $type = strtolower(trim($locationType));
        if ($type === 'plant') {
            return userCanAccessPlant($userId, $companyId, $divisionId, $locationId);
        } elseif ($type === 'warehouse') {
            return userCanAccessWarehouse($userId, $companyId, $divisionId, $locationId);
        }
        return false;
    }
}
OLD;

$newLocFunc = <<<'NEW'
if (!function_exists('userCanAccessLocation')) {
    function userCanAccessLocation($arg1, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null)
    {
        // Polymorphic support for 3 args: userCanAccessLocation($locationType, $locationId, $userId) or ($userId, $locationType, $locationId)
        if ($arg4 === null && $arg5 === null) {
            if (is_numeric($arg1) && is_string($arg2)) {
                $userId = (int)$arg1;
                $locationType = strtolower(trim($arg2));
                $locationId = (int)$arg3;
            } elseif (is_string($arg1)) {
                $locationType = strtolower(trim($arg1));
                $locationId = (int)$arg2;
                $userId = (int)$arg3;
            } else {
                $userId = (int)$arg3;
                $locationType = 'plant';
                $locationId = (int)$arg2;
            }

            if ($locationType === 'warehouse') {
                $whs = getUserAccessibleWarehouses(null, null, $userId);
                foreach ($whs as $w) {
                    if ((int)$w['warehouse_id'] === $locationId) return true;
                }
                return false;
            } else {
                $plts = getUserAccessiblePlants(null, null, $userId);
                foreach ($plts as $p) {
                    if ((int)$p['plant_id'] === $locationId) return true;
                }
                return false;
            }
        }

        // Standard 5 args: ($userId, $companyId, $divisionId, $locationType, $locationId)
        $userId = $arg1;
        $companyId = $arg2;
        $divisionId = $arg3;
        $locationType = $arg4;
        $locationId = $arg5;

        $type = strtolower(trim((string)$locationType));
        if ($type === 'plant') {
            return userCanAccessPlant($userId, $companyId, $divisionId, $locationId);
        } elseif ($type === 'warehouse') {
            return userCanAccessWarehouse($userId, $companyId, $divisionId, $locationId);
        }
        return false;
    }
}
NEW;

if (strpos($authContent, $oldLocFunc) !== false) {
    $authContent = str_replace($oldLocFunc, $newLocFunc, $authContent);
    file_put_contents('config/auth.php', $authContent);
    echo "Updated userCanAccessLocation in config/auth.php successfully.\n";
} else {
    echo "userCanAccessLocation pattern not found in config/auth.php.\n";
}
