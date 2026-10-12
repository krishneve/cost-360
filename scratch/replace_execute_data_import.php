<?php
$content = file_get_contents('config/upload_centre.php');

$newExecuteDataImport = <<<'NEWFUNC'
// Validate & Perform Real Import into Dedicated Customer Database (cust<ID>)
function executeDataImport($uploadId, $user) {
    $userId = $user['user_id'] ?? null;
    $username = $user['email'] ?? ($user['username'] ?? 'User');

    $upload = dbQuery("SELECT * FROM public.uploads WHERE id = ?", [$uploadId])->fetch(PDO::FETCH_ASSOC);
    if (!$upload) {
        return ['ok' => false, 'message' => 'Upload run not found.'];
    }

    $files = dbQuery("SELECT * FROM public.upload_files WHERE upload_id = ?", [$uploadId])->fetchAll(PDO::FETCH_ASSOC);
    if (empty($files)) {
        return ['ok' => false, 'message' => 'No upload files found to import.'];
    }

    // Resolve Customer DB context securely for this user
    $custDbContext = getUserCustomerDbContext($user);
    $custPdo = $custDbContext['pdo'];
    $custDbName = $custDbContext['database_name'];
    $customerId = $custDbContext['customer_id'];

    logUploadAudit($userId, $username, 'IMPORT_STARTED', 'Upload Centre', null, 'In Progress', [
        'upload_id' => $uploadId,
        'customer_id' => $customerId,
        'database_name' => $custDbName
    ]);

    $totalImported = 0;
    $totalSkipped = 0;
    $totalFailed = 0;

    foreach ($files as $f) {
        $headers = json_decode($f['headers'] ?? '[]', true) ?: [];
        $subCatId = $f['sub_category_id'];
        
        // Find target table
        $targetTable = 'items';
        if ($subCatId) {
            $tName = dbQuery("SELECT te.table_name FROM public.sub_categories sc JOIN public.target_entities te ON te.id = sc.target_entity_id WHERE sc.id = ?", [$subCatId])->fetchColumn();
            if ($tName) {
                $rawTable = strtolower(trim($tName));
                if (in_array($rawTable, ['item_master', 'product_master', 'items', 'item'], true)) {
                    $targetTable = 'items';
                } elseif (in_array($rawTable, ['uom_master', 'uoms', 'uom'], true)) {
                    $targetTable = 'uoms';
                } elseif (in_array($rawTable, ['period_master', 'periods', 'period'], true)) {
                    $targetTable = 'period';
                } else {
                    $targetTable = $rawTable;
                }
            }
        }

        // Dynamically ensure ONLY the requested target table exists in the dedicated customer database
        ensureTargetTableExistsInCustomerDb($custPdo, $targetTable);

        // Discover dynamic target columns for this table in $custPdo
        $dbCols = getTargetTableColumns($targetTable, $custPdo);
        $dbColNames = array_column($dbCols, 'column_name');

        if (!file_exists($f['storage_path'])) {
            continue;
        }

        $handle = fopen($f['storage_path'], 'rb');
        if ($handle === false) {
            continue;
        }

        $rawHeaders = fgetcsv($handle);
        $rowNum = 0;

        // Perform import in atomic transaction on Customer DB
        $custPdo->beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (empty(array_filter($row))) {
                    $totalSkipped++;
                    continue;
                }

                $aliasMap = [
                    'cc_code'             => 'work_center_code',
                    'cost_center_code'    => 'work_center_code',
                    'std_machine_hr_rate' => 'hourly_rate',
                    'machine_rate'        => 'hourly_rate',
                    'std_hr_rate'         => 'hourly_rate',
                    'desc'                => 'description',
                    'description'         => 'description',
                    'item_desc'           => 'item_name',
                    'uom'                 => 'uom_code',
                    'unit_rate'           => 'rate',
                    'unit_cost'           => 'rate'
                ];

                $mappedData = [];
                foreach ($headers as $idx => $headerName) {
                    $cleanCol = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', trim($headerName))));
                    
                    if (in_array($cleanCol, $dbColNames, true)) {
                        $mappedData[$cleanCol] = $row[$idx] ?? null;
                    } elseif (isset($aliasMap[$cleanCol]) && in_array($aliasMap[$cleanCol], $dbColNames, true)) {
                        $mappedData[$aliasMap[$cleanCol]] = $row[$idx] ?? null;
                    } elseif ($targetTable === 'items' && ($cleanCol === 'uom' || $cleanCol === 'uom_code')) {
                        $mappedData['uom_code'] = $row[$idx] ?? null;
                    }
                }

                // Handle UOM value assignment for item tables safely
                if (in_array($targetTable, ['items', 'item_master'], true)) {
                    $uomVal = $mappedData['uom'] ?? ($mappedData['uom_code'] ?? ($mappedData['unit'] ?? 'PCS'));
                    if (in_array('uom', $dbColNames, true)) {
                        $mappedData['uom'] = $uomVal;
                    }
                    if (in_array('uom_id', $dbColNames, true)) {
                        ensureTargetTableExistsInCustomerDb($custPdo, 'uoms');
                        if (!empty($uomVal)) {
                            try {
                                $uStmt = $custPdo->prepare("SELECT uom_id FROM uoms WHERE UPPER(uom_code) = UPPER(:ucode) LIMIT 1");
                                $uStmt->execute([':ucode' => trim($uomVal)]);
                                $uId = $uStmt->fetchColumn();
                                if (!$uId) {
                                    $insUom = $custPdo->prepare("INSERT INTO uoms (uom_code, uom_name) VALUES (:code, :name) ON CONFLICT (uom_code) DO NOTHING RETURNING uom_id");
                                    $insUom->execute([':code' => strtoupper(trim($uomVal)), ':name' => trim($uomVal)]);
                                    $uId = $insUom->fetchColumn();
                                    if (!$uId) {
                                        $uStmt->execute([':ucode' => trim($uomVal)]);
                                        $uId = $uStmt->fetchColumn();
                                    }
                                }
                                if ($uId) {
                                    $mappedData['uom_id'] = $uId;
                                }
                            } catch (Throwable $uomErr) {}
                        }
                    }
                }

                // Enforce strict schema validation: filter out any key not physically present in $dbColNames
                $mappedData = array_filter($mappedData, fn($k) => in_array($k, $dbColNames, true), ARRAY_FILTER_USE_KEY);

                // Determine primary non-null identifier column for this table
                $primaryCol = $dbColNames[1] ?? ($dbColNames[0] ?? null);
                if ($primaryCol && empty($mappedData[$primaryCol])) {
                    foreach ($dbColNames as $col) {
                        if (!in_array($col, ['id', 'created_at', 'created_by', 'item_id', 'uom_id', 'period_id'], true) && !empty($mappedData[$col])) {
                            $primaryCol = $col;
                            break;
                        }
                    }
                }

                if ($primaryCol && empty($mappedData[$primaryCol])) {
                    $totalFailed++;
                    dbQuery("
                        INSERT INTO public.import_errors(upload_id, upload_file_id, row_number, field, error_type, error_message, severity)
                        VALUES(?, ?, ?, ?, 'Missing Required Field', 'Row missing required identifier', 'High')
                    ", [$uploadId, $f['id'], $rowNum, $primaryCol]);
                    continue;
                }

                $colsToInsert = array_keys($mappedData);
                if (empty($colsToInsert)) {
                    $totalSkipped++;
                    continue;
                }

                $colList = implode(', ', $colsToInsert);
                $placeholders = implode(', ', array_fill(0, count($colsToInsert), '?'));
                $values = array_values($mappedData);

                // Isolate row insertion with SAVEPOINT to prevent aborted transaction state (SQLSTATE 25P02)
                $spName = "sp_row_" . $rowNum;
                $custPdo->exec("SAVEPOINT {$spName}");

                try {
                    $insertSql = "INSERT INTO public.{$targetTable} ({$colList}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING";
                    $stmt = $custPdo->prepare($insertSql);
                    $stmt->execute($values);
                    $custPdo->exec("RELEASE SAVEPOINT {$spName}");
                    $totalImported++;
                } catch (Throwable $e) {
                    try {
                        $custPdo->exec("ROLLBACK TO SAVEPOINT {$spName}");
                    } catch (Throwable $rbErr) {}
                    $totalFailed++;
                    dbQuery("
                        INSERT INTO public.import_errors(upload_id, upload_file_id, row_number, field, error_type, error_message, severity)
                        VALUES(?, ?, ?, ?, 'Database Constraint Failure', ?, 'Critical')
                    ", [$uploadId, $f['id'], $rowNum, $primaryCol, $e->getMessage()]);
                }
            }
            fclose($handle);

            $custPdo->commit();
        } catch (Throwable $txErr) {
            $custPdo->rollBack();
            fclose($handle);
            throw $txErr;
        }
    }

    $finalStatus = ($totalFailed === 0) ? 'Completed' : 'Completed with Errors';

    dbQuery("
        UPDATE public.uploads 
        SET status = ?, success_rows = ?, warning_rows = ?, error_rows = ?, completed_at = now() 
        WHERE id = ?
    ", [$finalStatus, $totalImported, $totalSkipped, $totalFailed, $uploadId]);

    logUploadAudit($userId, $username, 'IMPORT_COMPLETED', 'Upload Centre', null, 'Success', [
        'imported'      => $totalImported,
        'skipped'       => $totalSkipped,
        'failed'        => $totalFailed,
        'customer_id'   => $customerId,
        'database_name' => $custDbName
    ]);

    clearUploadCentreCache();

    return [
        'ok'            => true,
        'imported'      => $totalImported,
        'skipped'       => $totalSkipped,
        'failed'        => $totalFailed,
        'status'        => $finalStatus,
        'database_name' => $custDbName,
        'message'       => "Data imported successfully into dedicated Customer Database '{$custDbName}'."
    ];
}
NEWFUNC;

// Find executeDataImport and replace it up to getUploadAuditLogs
$startPos = strpos($content, 'function executeDataImport(');
$endPos = strpos($content, 'function getUploadAuditLogs(');

if ($startPos !== false && $endPos !== false) {
    // Find the comment line right before function executeDataImport
    $commentPos = strrpos(substr($content, 0, $startPos), '//');
    if ($commentPos === false) $commentPos = $startPos;
    
    $prefix = substr($content, 0, $commentPos);
    $suffix = substr($content, $endPos);
    
    $content = $prefix . $newExecuteDataImport . "\n\n// Fetch Audit Logs\n" . $suffix;
    file_put_contents('config/upload_centre.php', $content);
    echo "Successfully cleanly replaced executeDataImport in config/upload_centre.php!\n";
} else {
    echo "Could not find start/end markers in config/upload_centre.php.\n";
}
