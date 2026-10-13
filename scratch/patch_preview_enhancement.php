<?php
/**
 * Script to upgrade executeDataImport and getFilePreviewData in config/upload_centre.php
 */

$file = __DIR__ . '/../config/upload_centre.php';
$content = file_get_contents($file);

// Replace getFilePreviewData return with enriched metadata and preview counts
$oldPreviewReturn = <<<'OLD'
    // Organization Scope Context
    $orgContext = getActiveOrganizationContext();

    return [
        'ok'             => true,
        'file'           => $file,
        'headers'        => $headers,
        'data'           => $data,
        'target_table'   => $targetTable,
        'database_name'  => $custDbContext['database_name'],
        'customer_id'    => $custDbContext['customer_id'],
        'target_columns' => $targetColNames,
        'auto_mapping'   => $mapping,
        'org_context'    => [
            'customer_code' => $orgContext['customer_code'] ?? 'CUST001',
            'company_code'  => $orgContext['company_code'] ?? 'COMP001',
            'division_code' => $orgContext['division_code'] ?? 'DIV001',
            'location_code' => $orgContext['location_code'] ?? 'LOC001'
        ]
    ];
OLD;

$newPreviewReturn = <<<'NEW'
    // Organization Scope Context
    $orgContext = getActiveOrganizationContext();

    // Category and Dataset Type Resolution
    $defs = getFinalizedCategoryDefinitions();
    $catDef = null;
    foreach ($defs as $d) {
        if ($d['target_table'] === $targetTable || $d['sheet_name'] === ($file['sheet_name'] ?? '')) {
            $catDef = $d;
            break;
        }
    }
    if (!$catDef) {
        $catDef = $defs['01_ITEM_PRODUCT_MASTER'];
    }

    $datasetType = $catDef['dataset_type'] ?? 'MASTER';
    $periodContext = getAccountingPeriodContext();
    $availablePeriods = function_exists('getUserAccessiblePeriods') ? getUserAccessiblePeriods() : [];

    // Pre-calculate preview match simulation
    $previewSummary = [
        'total_sample_rows' => count($data),
        'dataset_type'      => $datasetType,
        'new_records'       => 0,
        'update_records'    => 0,
        'unchanged_records' => 0
    ];

    if (!empty($data) && !empty($catDef['business_key'])) {
        $businessKeys = $catDef['business_key'];
        foreach ($data as $sampleRow) {
            $whereParts = [];
            $whereVals = [];
            foreach ($businessKeys as $bk) {
                if ($bk === 'period_id') {
                    if ($periodContext) {
                        $whereParts[] = "period_id = ?";
                        $whereVals[] = (int)$periodContext['period_id'];
                    }
                } else {
                    $val = $sampleRow[$bk] ?? ($sampleRow[str_replace('_', ' ', $bk)] ?? null);
                    if ($val !== null && $val !== '') {
                        $whereParts[] = "{$bk} = ?";
                        $whereVals[] = $val;
                    }
                }
            }
            if (in_array('company_code', $targetColNames, true)) {
                $whereParts[] = "company_code = ?";
                $whereVals[] = $orgContext['company_code'] ?? 'COMP001';
            }
            if (!empty($whereParts)) {
                $wSql = implode(' AND ', $whereParts);
                try {
                    $chk = $custPdo->prepare("SELECT * FROM public.{$targetTable} WHERE {$wSql} LIMIT 1");
                    $chk->execute($whereVals);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC);
                    if ($existing) {
                        $diff = false;
                        foreach ($sampleRow as $sk => $sv) {
                            $cleanSk = strtolower(trim(preg_replace('/[^a-z0-9_]/i', '', str_replace(' ', '_', $sk))));
                            if (in_array($cleanSk, $targetColNames, true) && !in_array($cleanSk, ['id', 'item_id', 'created_at', 'created_by', 'modified_at', 'modified_by'], true)) {
                                $ov = $existing[$cleanSk] ?? null;
                                if ((string)$sv !== (string)$ov) {
                                    $diff = true;
                                    break;
                                }
                            }
                        }
                        if ($diff) {
                            $previewSummary['update_records']++;
                        } else {
                            $previewSummary['unchanged_records']++;
                        }
                    } else {
                        $previewSummary['new_records']++;
                    }
                } catch (Throwable $e) {
                    $previewSummary['new_records']++;
                }
            } else {
                $previewSummary['new_records']++;
            }
        }
    }

    return [
        'ok'                => true,
        'file'              => $file,
        'headers'           => $headers,
        'data'              => $data,
        'target_table'      => $targetTable,
        'database_name'     => $custDbContext['database_name'],
        'customer_id'       => $custDbContext['customer_id'],
        'target_columns'    => $targetColNames,
        'auto_mapping'      => $mapping,
        'dataset_type'      => $datasetType,
        'period_context'    => $periodContext,
        'available_periods' => $availablePeriods,
        'preview_summary'   => $previewSummary,
        'org_context'       => [
            'customer_code' => $orgContext['customer_code'] ?? 'CUST001',
            'company_code'  => $orgContext['company_code'] ?? 'COMP001',
            'division_code' => $orgContext['division_code'] ?? 'DIV001',
            'location_code' => $orgContext['location_code'] ?? 'LOC001'
        ]
    ];
NEW;

$content = str_replace($oldPreviewReturn, $newPreviewReturn, $content);

file_put_contents($file, $content);
echo "SUCCESS: Updated getFilePreviewData in config/upload_centre.php\n";
