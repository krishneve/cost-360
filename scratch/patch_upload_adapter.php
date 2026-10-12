<?php
$content = file_get_contents('config/upload_centre.php');

$oldDiscovery = <<<'OLD'
        // Discover dynamic target columns for this table
        $dbCols = getTargetTableColumns($targetTable);
        $dbColNames = array_column($dbCols, 'column_name');
OLD;

$newDiscovery = <<<'NEW'
        // Discover dynamic target columns for this table from customer DB
        $dbCols = getTargetTableColumns($targetTable, $targetCustomerId);
        if (empty($dbCols) && $targetTable === 'item_master') {
            $targetTable = 'items';
            $dbCols = getTargetTableColumns('items', $targetCustomerId);
        } elseif (empty($dbCols) && $targetTable === 'items') {
            $targetTable = 'item_master';
            $dbCols = getTargetTableColumns('item_master', $targetCustomerId);
        }
        $dbColNames = array_column($dbCols, 'column_name');
NEW;

if (strpos($content, $oldDiscovery) !== false) {
    $content = str_replace($oldDiscovery, $newDiscovery, $content);
    echo "Replaced target column discovery.\n";
} else {
    echo "Target column discovery pattern not found.\n";
}

$oldAliasBlock = <<<'OLD'
            $aliasMap = [
                'cc_code'             => 'work_center_code',
                'cost_center_code'    => 'work_center_code',
                'std_machine_hr_rate' => 'hourly_rate',
                'machine_rate'        => 'hourly_rate',
                'std_hr_rate'         => 'hourly_rate',
                'desc'                => 'item_description',
                'description'         => 'item_description',
                'unit_rate'           => 'rate',
                'unit_cost'           => 'rate'
            ];
OLD;

$newAliasBlock = <<<'NEW'
            $aliasMap = [
                'cc_code'             => 'work_center_code',
                'cost_center_code'    => 'work_center_code',
                'std_machine_hr_rate' => 'hourly_rate',
                'machine_rate'        => 'hourly_rate',
                'std_hr_rate'         => 'hourly_rate',
                'desc'                => 'item_description',
                'description'         => 'item_description',
                'unit_rate'           => 'rate',
                'unit_cost'           => 'rate',
                'item'                => 'item_code',
                'material_code'       => 'item_code',
                'material_name'       => 'item_name',
                'product_code'        => 'item_code',
                'product_name'        => 'item_name',
                'type'                => 'item_type',
                'uom_code'            => 'uom'
            ];
NEW;

if (strpos($content, $oldAliasBlock) !== false) {
    $content = str_replace($oldAliasBlock, $newAliasBlock, $content);
    echo "Replaced alias block.\n";
} else {
    echo "Alias block pattern not found.\n";
}

$oldStrictFilter = <<<'OLD'
            // Enforce strict schema validation: filter out any key not physically present in $dbColNames
            $mappedData = array_filter($mappedData, fn($k) => in_array($k, $dbColNames, true), ARRAY_FILTER_USE_KEY);
OLD;

$newStrictFilter = <<<'NEW'
            // Customer items table specific field adapters and FK lookups
            if ($targetTable === 'items') {
                if (empty($mappedData['item_name']) && !empty($mappedData['item_description'])) {
                    $mappedData['item_name'] = $mappedData['item_description'];
                }
                if (empty($mappedData['item_type'])) {
                    $mappedData['item_type'] = 'Raw Material';
                }
                if (empty($mappedData['status'])) {
                    $mappedData['status'] = 'Active';
                }
                if (empty($mappedData['uom_id'])) {
                    $uomCandidate = !empty($mappedData['uom']) ? trim($mappedData['uom']) : 'PCS';
                    foreach ($headers as $hIdx => $hName) {
                        if (stripos($hName, 'uom') !== false || stripos($hName, 'unit') !== false) {
                            if (!empty($row[$hIdx])) {
                                $uomCandidate = trim($row[$hIdx]);
                                break;
                            }
                        }
                    }
                    $uomCodeVal = strtoupper($uomCandidate);
                    try {
                        $uomStmt = $pdo->prepare("SELECT uom_id FROM uoms WHERE UPPER(uom_code) = :code LIMIT 1");
                        $uomStmt->execute([':code' => $uomCodeVal]);
                        $resolvedUomId = $uomStmt->fetchColumn();
                        if (!$resolvedUomId) {
                            $insUom = $pdo->prepare("INSERT INTO uoms (uom_code, uom_name, description, status) VALUES (:code, :name, 'Auto-created on import', 'Active') RETURNING uom_id");
                            $insUom->execute([':code' => $uomCodeVal, ':name' => ucfirst(strtolower($uomCodeVal))]);
                            $resolvedUomId = $insUom->fetchColumn();
                        }
                    } catch (Throwable $uEx) {
                        $resolvedUomId = 1;
                    }
                    $mappedData['uom_id'] = $resolvedUomId;
                }
            }

            // Enforce strict schema validation: filter out any key not physically present in $dbColNames
            $mappedData = array_filter($mappedData, fn($k) => in_array($k, $dbColNames, true), ARRAY_FILTER_USE_KEY);
NEW;

if (strpos($content, $oldStrictFilter) !== false) {
    $content = str_replace($oldStrictFilter, $newStrictFilter, $content);
    echo "Replaced strict filter with customer items adapter.\n";
} else {
    echo "Strict filter pattern not found.\n";
}

file_put_contents('config/upload_centre.php', $content);
