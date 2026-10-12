<?php
$file = __DIR__ . '/../config/upload_centre.php';
$content = file_get_contents($file);

// Find start of executeDataImport
$startToken = "function executeDataImport(";
$posStart = strpos($content, $startToken);
if ($posStart === false) {
    die("Start token not found\n");
}

// Find clearUploadCentreCache(); followed by return [
$endToken = "    return [\n        'ok'            => true,\n        'imported'      => \$totalImported,\n        'updated'       => \$totalUpdated,\n        'skipped'       => \$totalSkipped,\n        'failed'        => \$totalFailed,\n        'database_name' => \$custDbName\n    ];\n}";
$posEnd = strpos($content, $endToken, $posStart);

if ($posEnd === false) {
    // Try CRLF
    $endToken = "    return [\r\n        'ok'            => true,\r\n        'imported'      => \$totalImported,\r\n        'updated'       => \$totalUpdated,\r\n        'skipped'       => \$totalSkipped,\r\n        'failed'        => \$totalFailed,\r\n        'database_name' => \$custDbName\r\n    ];\r\n}";
    $posEnd = strpos($content, $endToken, $posStart);
}

if ($posEnd === false) {
    // Search for just closing brace of executeDataImport
    $endStr = "clearUploadCentreCache();\n\n    return [";
    $posEndStr = strpos($content, $endStr, $posStart);
    if ($posEndStr !== false) {
        $posClosing = strpos($content, "\n}", $posEndStr);
        if ($posClosing !== false) {
            $endToken = substr($content, $posEndStr, ($posClosing + 2) - $posEndStr);
            $posEnd = $posEndStr;
        }
    }
}

$newFunc = <<<'PHP'
function executeDataImport($uploadId, $user, ?array $selectedFileIds = null, ?int $periodId = null) {
    $userId = (int)($user['user_id'] ?? 0);
    $username = $user['email'] ?? ($user['username'] ?? 'User');

    $upload = dbQuery("SELECT * FROM public.uploads WHERE id = ?", [$uploadId])->fetch(PDO::FETCH_ASSOC);
    if (!$upload) {
        return ['ok' => false, 'message' => 'Upload run record not found.'];
    }

    $fileQuery = "SELECT * FROM public.upload_files WHERE upload_id = ?";
    $params = [$uploadId];
    if (!empty($selectedFileIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($selectedFileIds), '?'));
        $fileQuery .= " AND id IN ({$inPlaceholders})";
        $params = array_merge($params, array_map('intval', $selectedFileIds));
    }
    $fileQuery .= " ORDER BY sheet_index ASC, id ASC";

    $files = dbQuery($fileQuery, $params)->fetchAll(PDO::FETCH_ASSOC);
    if (empty($files)) {
        return ['ok' => false, 'message' => 'No files or worksheets found to import.'];
    }

    // Resolve Customer DB Context securely
    $custDbContext = getUserCustomerDbContext($user);
    $custPdo = $custDbContext['pdo'];
    $custDbName = $custDbContext['database_name'];
    $customerId = $custDbContext['customer_id'];

    // Resolve Authenticated Organization Scope strictly from server context
    $orgContext = getActiveOrganizationContext();
    $activeCustomerCode = trim($orgContext['customer_code'] ?? '');
    $activeCompanyCode  = trim($orgContext['company_code'] ?? '');
    $activeDivisionCode = trim($orgContext['division_code'] ?? '');
    $activeLocationCode = trim($orgContext['location_code'] ?? '');

    // Auto-resolve missing codes from database if needed
    $globalPdo = getDbConnection();
    if (empty($activeCustomerCode) && $customerId > 0) {
        $cStmt = $globalPdo->prepare("SELECT customer_code FROM customers WHERE customer_id = ?");
        $cStmt->execute([$customerId]);
        $activeCustomerCode = $cStmt->fetchColumn() ?: 'CUST' . $customerId;
    }
    if (empty($activeCompanyCode) && !empty($orgContext['company_id']) && is_numeric($orgContext['company_id'])) {
        $coStmt = $globalPdo->prepare("SELECT company_code FROM companies WHERE company_id = ?");
        $coStmt->execute([(int)$orgContext['company_id']]);
        $activeCompanyCode = $coStmt->fetchColumn() ?: 'COMP001';
    }
    if (empty($activeLocationCode)) {
        $loc = getActiveOperationalLocation();
        $activeLocationCode = $loc['code'] ?? 'LOC001';
    }

    if (empty($activeCompanyCode) || empty($activeLocationCode)) {
        return [
            'ok'      => false,
            'message' => 'Active organizational scope (Company and Location) must be selected before importing data.'
        ];
    }

    // Resolve Accounting Period for transactions
    $accountingPeriod = getAccountingPeriodContext($periodId);

    logUploadAudit($userId, $username, 'IMPORT_STARTED', 'Upload Centre', null, 'In Progress', [
        'upload_id'     => $uploadId,
        'customer_id'   => $customerId,
        'database_name' => $custDbName,
        'company_code'  => $activeCompanyCode,
        'location_code' => $activeLocationCode,
        'period'        => $accountingPeriod ? $accountingPeriod['period_name'] : null
    ]);

    $defs = getFinalizedCategoryDefinitions();
    $totalImported = 0;
    $totalUpdated = 0;
    $totalUnchanged = 0;
    $totalSkipped = 0;
    $totalFailed = 0;

    foreach ($files as $f) {
        $headers = json_decode($f['headers'] ?? '[]', true) ?: [];
        $subCatId = $f['sub_category_id'];
        $sheetName = $f['sheet_name'];

        // Determine target entity table
        $targetTable = 'items';
        if ($subCatId) {
            $tName = dbQuery("
                SELECT te.table_name 
                FROM public.sub_categories sc 
                JOIN public.target_entities te ON te.id = sc.target_entity_id 
                WHERE sc.id = ?
            ", [$subCatId])->fetchColumn();
            if ($tName) {
                $targetTable = strtolower(trim($tName));
            }
        }

        // Find category contract definition
        $catDef = null;
        foreach ($defs as $d) {
            if ($d['target_table'] === $targetTable || $d['sheet_name'] === $sheetName) {
                $catDef = $d;
                break;
            }
        }
        if (!$catDef) {
            $catDef = $defs['01_ITEM_PRODUCT_MASTER'];
        }

        $isMaster = isMasterDataset($catDef['key'] ?? '');

        // Validate Period for Transaction Sheets
        if (!$isMaster) {
            $periodValidation = validateAccountingPeriodForImport($accountingPeriod);
            if (!$periodValidation['valid']) {
                $totalFailed++;
                dbQuery("
                    INSERT INTO public.import_errors(upload_id, upload_file_id, sheet_name, row_number, field, error_type, error_message, severity)
                    VALUES(?, ?, ?, 1, 'period_id', 'Period Validation Failed', ?, 'Critical')
                ", [$uploadId, $f['id'], $sheetName, $periodValidation['error']]);
                continue;
            }
        }

        // Ensure table and all columns exist in Customer DB
        ensureTargetTableExistsInCustomerDb($custPdo, $targetTable);
        $dbCols = getTargetTableColumns($targetTable, $custPdo);
        $dbColNames = array_column($dbCols, 'column_name');

        if (!file_exists($f['storage_path'])) {
            continue;
        }

        // Read all rows for this sheet / file
        $ext = strtolower($f['file_type']);
        $rawRows = [];

        if ($ext === 'xlsx' || $ext === 'xls') {
            $parsed = parseXlsxSheetRows($f['storage_path'], $sheetName ?: '');
            if (!empty($parsed['headers'])) {
                $headers = $parsed['headers'];
            }
            $rawRows = $parsed['rows'];
        } elseif ($ext === 'csv') {
            $handle = fopen($f['storage_path'], 'rb');
            if ($handle !== false) {
                $rawHeaders = fgetcsv($handle);
                if ($rawHeaders !== false) {
                    $headers = array_map('trim', $rawHeaders);
                }
                while (($row = fgetcsv($handle)) !== false) {
                    $rawRows[] = $row;
                }
                fclose($handle);
            }
        }

        if (empty($rawRows)) {
            continue;
        }

        // Atomic transaction on customer database for this sheet
        $custPdo->beginTransaction();

        try {
            foreach ($rawRows as $rowIdx => $row) {
                $rowNum = $rowIdx + 2; // 1-indexed Excel data row (row 1 is header)

                if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                    $totalSkipped++;
                    continue;
                }

                // Check for instruction rows e.g. "(supplier/customer)"
                $nonEmpty = array_values(array_filter($row, fn($v) => trim((string)$v) !== ''));
                if (count($nonEmpty) === 1 && strpos($nonEmpty[0], '(') === 0 && str_ends_with($nonEmpty[0], ')')) {
                    $totalSkipped++;
                    continue;
                }

                $mappedData = [];
                $sourceRowMap = [];
                foreach ($headers as $colIdx => $hName) {
                    $cleanH = strtolower(trim(preg_replace('/[^a-z0-9_]/i', '', str_replace(' ', '_', $hName))));
                    $val = isset($row[$colIdx]) ? trim((string)$row[$colIdx]) : null;
                    if ($val === '') {
                        $val = null;
                    }
                    $sourceRowMap[$cleanH] = $val;

                    // Normalize date values if date column
                    if ($val !== null && in_array($cleanH, $catDef['date_fields'], true)) {
                        $val = normalizeExcelDate($val);
                    }

                    // Normalize numeric fields
                    if ($val !== null && in_array($cleanH, $catDef['numeric_fields'], true)) {
                        $cleanNum = str_replace([',', ' '], '', $val);
                        if (is_numeric($cleanNum)) {
                            $val = $cleanNum;
                        }
                    }

                    if (in_array($cleanH, $dbColNames, true)) {
                        $mappedData[$cleanH] = $val;
                    }
                }

                // Strictly populate Organization Context fields from server-verified context
                if (in_array('customer_code', $dbColNames, true)) {
                    $mappedData['customer_code'] = $activeCustomerCode;
                }
                if (in_array('company_code', $dbColNames, true)) {
                    $mappedData['company_code'] = $activeCompanyCode;
                }
                if (in_array('division_code', $dbColNames, true)) {
                    $mappedData['division_code'] = $activeDivisionCode ?: 'DIV001';
                }
                if (in_array('location_code', $dbColNames, true)) {
                    $mappedData['location_code'] = $activeLocationCode;
                }

                // Tag Transaction Data with Accounting Period
                if (!$isMaster && $accountingPeriod) {
                    if (in_array('period_id', $dbColNames, true)) {
                        $mappedData['period_id'] = (int)$accountingPeriod['period_id'];
                    }
                    if (in_array('period_code', $dbColNames, true)) {
                        $mappedData['period_code'] = $accountingPeriod['period_code'] ?? $accountingPeriod['period_name'];
                    }
                }

                // Compatibility aliases for pre-existing customer schema columns
                if ($targetTable === 'work_centers') {
                    if (in_array('work_center_code', $dbColNames, true) && !empty($mappedData['wc_code'])) {
                        $mappedData['work_center_code'] = $mappedData['wc_code'];
                    }
                    if (in_array('work_center_name', $dbColNames, true) && !empty($mappedData['wc_name'])) {
                        $mappedData['work_center_name'] = $mappedData['wc_name'];
                    }
                } elseif ($targetTable === 'cost_center_master') {
                    if (in_array('cost_center_code', $dbColNames, true) && !empty($mappedData['cc_code'])) {
                        $mappedData['cost_center_code'] = $mappedData['cc_code'];
                    }
                    if (in_array('cost_center_name', $dbColNames, true) && !empty($mappedData['cc_name'])) {
                        $mappedData['cost_center_name'] = $mappedData['cc_name'];
                    }
                } elseif ($targetTable === 'material_transaction') {
                    // Normalize transaction type across all 5 canonical types:
                    // Purchase, Consumption, Production, Issue, Scrap Sales
                    $rawTxn = $mappedData['doc_type'] ?? $mappedData['transaction_type'] ?? $sourceRowMap['doc_type'] ?? $sourceRowMap['transaction_type'] ?? $sourceRowMap['type'] ?? $sourceRowMap['txn_type'] ?? $sourceRowMap['movement_type'] ?? '';
                    $normalizedTxn = normalizeMaterialTransactionType((string)$rawTxn, $sheetName ?: ($f['original_name'] ?? ''), $f['original_name'] ?? '', $f['sub_category_code'] ?? '');

                    if (in_array('doc_type', $dbColNames, true)) {
                        $mappedData['doc_type'] = $normalizedTxn;
                    }
                    if (in_array('transaction_type', $dbColNames, true)) {
                        $mappedData['transaction_type'] = $normalizedTxn;
                    }

                    // Flexible aliases from common transaction headers
                    if (empty($mappedData['doc_no'])) {
                        $mappedData['doc_no'] = $sourceRowMap['po_number'] ?? $sourceRowMap['wo_number'] ?? $sourceRowMap['issue_no'] ?? ('TXN-' . strtoupper(substr($normalizedTxn, 0, 3)) . '-' . $rowNum);
                    }
                    if (empty($mappedData['doc_date']) && !empty($sourceRowMap['txn_date'])) {
                        $mappedData['doc_date'] = normalizeExcelDate($sourceRowMap['txn_date']);
                    }
                    if (empty($mappedData['party_code']) && !empty($sourceRowMap['vendor_code'])) {
                        $mappedData['party_code'] = $sourceRowMap['vendor_code'];
                    }
                    if (empty($mappedData['party_name']) && !empty($sourceRowMap['vendor_name'])) {
                        $mappedData['party_name'] = $sourceRowMap['vendor_name'];
                    }
                    if (empty($mappedData['qty']) && !empty($sourceRowMap['produced_qty'])) {
                        $mappedData['qty'] = is_numeric($sourceRowMap['produced_qty']) ? $sourceRowMap['produced_qty'] : 0;
                    }
                    if (empty($mappedData['rate']) && !empty($sourceRowMap['unit_price'])) {
                        $mappedData['rate'] = is_numeric($sourceRowMap['unit_price']) ? $sourceRowMap['unit_price'] : 0;
                    }
                }

                // Check mandatory field validation
                $missingField = null;
                foreach ($catDef['required_fields'] as $reqField) {
                    if (empty($mappedData[$reqField])) {
                        $missingField = $reqField;
                        break;
                    }
                }

                if ($missingField) {
                    $totalFailed++;
                    dbQuery("
                        INSERT INTO public.import_errors(upload_id, upload_file_id, sheet_name, row_number, field, error_type, error_message, severity)
                        VALUES(?, ?, ?, ?, ?, 'Missing Required Field', ?, 'Critical')
                    ", [$uploadId, $f['id'], $sheetName, $rowNum, $missingField, "Mandatory column '{$missingField}' is empty or invalid"]);
                    continue;
                }

                // Controlled Insert / Update / Unchanged handling
                $businessKeys = $catDef['business_key'];
                $whereClauses = [];
                $whereParams = [];

                foreach ($businessKeys as $bk) {
                    if (isset($mappedData[$bk]) && $mappedData[$bk] !== null) {
                        $whereClauses[] = "{$bk} = ?";
                        $whereParams[] = $mappedData[$bk];
                    }
                }

                // Add scope filter: company_code
                if (in_array('company_code', $dbColNames, true)) {
                    $whereClauses[] = "company_code = ?";
                    $whereParams[] = $activeCompanyCode;
                }

                // For transaction data, enforce strict period scoping
                if (!$isMaster && $accountingPeriod && in_array('period_id', $dbColNames, true) && !in_array('period_id', $businessKeys, true)) {
                    $whereClauses[] = "period_id = ?";
                    $whereParams[] = (int)$accountingPeriod['period_id'];
                }

                $existingRow = null;
                if (!empty($whereClauses)) {
                    $whereSql = implode(' AND ', $whereClauses);
                    $checkSql = "SELECT * FROM public.{$targetTable} WHERE {$whereSql} LIMIT 1";

                    try {
                        $chkStmt = $custPdo->prepare($checkSql);
                        $chkStmt->execute($whereParams);
                        $existingRow = $chkStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {}
                }

                // Isolate row operation with SAVEPOINT to guarantee consistency
                $spName = "sp_r_" . $rowNum;
                $custPdo->exec("SAVEPOINT {$spName}");

                try {
                    $nowTimestamp = date('Y-m-d H:i:s');
                    if ($existingRow) {
                        // Compare incoming business fields vs existing row to determine if anything changed
                        $hasChanges = false;
                        $ignoredCols = ['id', 'item_id', 'work_center_id', 'cost_id', 'created_at', 'created_by', 'modified_at', 'modified_by'];

                        foreach ($mappedData as $c => $v) {
                            if (in_array($c, $ignoredCols, true)) continue;
                            $oldVal = $existingRow[$c] ?? null;

                            $vTrim = ($v === null) ? '' : trim((string)$v);
                            $oTrim = ($oldVal === null) ? '' : trim((string)$oldVal);

                            if (is_numeric($vTrim) && is_numeric($oTrim)) {
                                if (abs((float)$vTrim - (float)$oTrim) > 0.00001) {
                                    $hasChanges = true;
                                    break;
                                }
                            } else {
                                if ($vTrim !== $oTrim) {
                                    $hasChanges = true;
                                    break;
                                }
                            }
                        }

                        if (!$hasChanges) {
                            // UNCHANGED: Identical record, leave untouched (preserves modified_at & created_at)
                            $totalUnchanged++;
                        } else {
                            // CHANGED: Update existing record, preserve created_at & created_by, update modified_at & modified_by
                            $mappedData['modified_at'] = $nowTimestamp;
                            $mappedData['modified_by'] = $userId;
                            unset($mappedData['created_at'], $mappedData['created_by']);

                            $setClauses = [];
                            $setValues = [];
                            foreach ($mappedData as $col => $val) {
                                $setClauses[] = "{$col} = ?";
                                $setValues[] = $val;
                            }
                            $pkCol = ($targetTable === 'items') ? 'item_id' : (($targetTable === 'work_centers') ? 'work_center_id' : (($targetTable === 'standard_cost') ? 'cost_id' : 'id'));
                            $existingPk = $existingRow[$pkCol] ?? ($existingRow['id'] ?? null);
                            $setValues[] = $existingPk;

                            $updateSql = "UPDATE public.{$targetTable} SET " . implode(', ', $setClauses) . " WHERE {$pkCol} = ?";
                            $updStmt = $custPdo->prepare($updateSql);
                            $updStmt->execute($setValues);
                            $totalUpdated++;
                        }
                    } else {
                        // INSERT: Brand new record, populate creation & modification audit fields
                        $mappedData['created_at']  = $nowTimestamp;
                        $mappedData['created_by']  = $userId;
                        $mappedData['modified_at'] = $nowTimestamp;
                        $mappedData['modified_by'] = $userId;

                        $colsToInsert = array_keys($mappedData);
                        $colList = implode(', ', $colsToInsert);
                        $placeholders = implode(', ', array_fill(0, count($colsToInsert), '?'));
                        $insertValues = array_values($mappedData);

                        $insertSql = "INSERT INTO public.{$targetTable} ({$colList}) VALUES ({$placeholders})";
                        $insStmt = $custPdo->prepare($insertSql);
                        $insStmt->execute($insertValues);
                        $totalImported++;
                    }

                    $custPdo->exec("RELEASE SAVEPOINT {$spName}");
                } catch (Throwable $rowErr) {
                    try {
                        $custPdo->exec("ROLLBACK TO SAVEPOINT {$spName}");
                    } catch (Throwable $rbErr) {}

                    $totalFailed++;
                    dbQuery("
                        INSERT INTO public.import_errors(upload_id, upload_file_id, sheet_name, row_number, field, error_type, error_message, severity)
                        VALUES(?, ?, ?, ?, ?, 'Database Constraint Violation', ?, 'High')
                    ", [$uploadId, $f['id'], $sheetName, $rowNum, $catDef['business_key'][0] ?? 'row', $rowErr->getMessage()]);
                }
            }

            $custPdo->commit();
        } catch (Throwable $txErr) {
            $custPdo->rollBack();
            error_log("Transaction error during import: " . $txErr->getMessage());
            throw $txErr;
        }
    }

    $finalStatus = ($totalFailed === 0) ? 'Completed' : 'Completed with Errors';

    dbQuery("
        UPDATE public.uploads 
        SET status = ?, 
            success_rows = ?, 
            warning_rows = ?, 
            error_rows = ?, 
            duplicate_rows = ?, 
            inserted_rows = ?, 
            updated_rows = ?, 
            unchanged_rows = ?, 
            period_id = ?, 
            period_code = ?, 
            completed_at = now() 
        WHERE id = ?
    ", [
        $finalStatus, 
        $totalImported + $totalUpdated + $totalUnchanged, 
        $totalSkipped, 
        $totalFailed, 
        $totalUnchanged, 
        $totalImported, 
        $totalUpdated, 
        $totalUnchanged, 
        $accountingPeriod ? (int)$accountingPeriod['period_id'] : null, 
        $accountingPeriod ? ($accountingPeriod['period_code'] ?? $accountingPeriod['period_name']) : null, 
        $uploadId
    ]);

    logUploadAudit($userId, $username, 'IMPORT_COMPLETED', 'Upload Centre', null, 'Success', [
        'imported'      => $totalImported,
        'updated'       => $totalUpdated,
        'unchanged'     => $totalUnchanged,
        'skipped'       => $totalSkipped,
        'failed'        => $totalFailed,
        'customer_id'   => $customerId,
        'database_name' => $custDbName,
        'period'        => $accountingPeriod ? $accountingPeriod['period_name'] : null
    ]);

    clearUploadCentreCache();

    return [
        'ok'            => true,
        'imported'      => $totalImported,
        'updated'       => $totalUpdated,
        'unchanged'     => $totalUnchanged,
        'skipped'       => $totalSkipped,
        'failed'        => $totalFailed,
        'period'        => $accountingPeriod ? $accountingPeriod['period_name'] : null,
        'period_id'     => $accountingPeriod ? (int)$accountingPeriod['period_id'] : null,
        'database_name' => $custDbName,
        'message'       => "Import Complete: {$totalImported} inserted, {$totalUpdated} updated, {$totalUnchanged} unchanged, {$totalFailed} errors."
    ];
}
PHP;

$content = substr($content, 0, $posStart) . $newFunc . substr($content, $posEnd + strlen($endToken));
file_put_contents($file, $content);
echo "SUCCESS: executeDataImport updated with Master Upsert and Period Management\n";
