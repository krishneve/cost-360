<?php
$content = file_get_contents('config/upload_centre.php');

$oldImportLoop = <<<'OLD'
                // Foreign Key Resolution for items table (uom_code -> uom_id)
                if ($targetTable === 'items') {
                    if (!empty($mappedData['uom_code']) && empty($mappedData['uom_id'])) {
                        $uStmt = $custPdo->prepare("SELECT uom_id FROM uoms WHERE UPPER(uom_code) = UPPER(:ucode) LIMIT 1");
                        $uStmt->execute([':ucode' => trim($mappedData['uom_code'])]);
                        $uId = $uStmt->fetchColumn();
                        if (!$uId) {
                            $insUom = $custPdo->prepare("INSERT INTO uoms (uom_code, uom_name) VALUES (:code, :name) RETURNING uom_id");
                            $insUom->execute([':code' => strtoupper(trim($mappedData['uom_code'])), ':name' => trim($mappedData['uom_code'])]);
                            $uId = $insUom->fetchColumn();
                        }
                        $mappedData['uom_id'] = $uId;
                        unset($mappedData['uom_code']);
                    } elseif (empty($mappedData['uom_id'])) {
                        $defUomId = $custPdo->query("SELECT uom_id FROM uoms ORDER BY uom_id ASC LIMIT 1")->fetchColumn();
                        if (!$defUomId) {
                            $insDef = $custPdo->query("INSERT INTO uoms (uom_code, uom_name, description) VALUES ('PCS', 'Pieces', 'Default Piece Unit') RETURNING uom_id");
                            $defUomId = $insDef->fetchColumn();
                        }
                        $mappedData['uom_id'] = $defUomId;
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
                $colList = implode(', ', $colsToInsert);
                $placeholders = implode(', ', array_fill(0, count($colsToInsert), '?'));
                $values = array_values($mappedData);

                try {
                    $insertSql = "INSERT INTO public.{$targetTable} ({$colList}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING";
                    $stmt = $custPdo->prepare($insertSql);
                    $stmt->execute($values);
                    $totalImported++;
                } catch (Throwable $e) {
                    $totalFailed++;
                    dbQuery("
                        INSERT INTO public.import_errors(upload_id, upload_file_id, row_number, field, error_type, error_message, severity)
                        VALUES(?, ?, ?, ?, 'Database Constraint Failure', ?, 'Critical')
                    ", [$uploadId, $f['id'], $rowNum, $primaryCol, $e->getMessage()]);
                }
OLD;

$newImportLoop = <<<'NEW'
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
NEW;

if (strpos($content, '$oldImportLoop') !== false || strpos($content, '// Foreign Key Resolution for items table (uom_code -> uom_id)') !== false) {
    $content = str_replace($oldImportLoop, $newImportLoop, $content);
    file_put_contents('config/upload_centre.php', $content);
    echo "Successfully updated import loop with PostgreSQL SAVEPOINT isolation.\n";
} else {
    echo "Direct pattern match failed, trying preg_replace.\n";
    $pattern = '/\/\/\s*Foreign Key Resolution for items table[\s\S]*?INSERT INTO public\.import_errors[\s\S]*?\}\s*\}\s*\}/';
    // Let's inspect where it differs
}
