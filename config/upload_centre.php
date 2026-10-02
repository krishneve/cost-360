<?php
/**
 * Cost360 - Upload Centre Core Service & Helpers
 * Handles file ingestion, keyword classification, database discovery, column mapping,
 * data validation, batch importing, import history, and audit logging.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

// Helper function for database execution
if (!function_exists('dbQuery')) {
    function dbQuery($sql, $params = []) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

// Audit Logger
if (!function_exists('logUploadAudit')) {
    function logUploadAudit($userId, $username, $action, $module = 'Upload Centre', $fileName = null, $status = 'Success', $details = []) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            dbQuery(
                "INSERT INTO public.audit_logs(user_id, username, action, module, file_name, status, details, ip_address) 
                 VALUES(?, ?, ?, ?, ?, ?, ?::jsonb, ?::inet)",
                [$userId, $username, $action, $module, $fileName, $status, json_encode($details), $ip]
            );
        } catch (Throwable $e) {
            error_log("Upload Centre Audit Log Error: " . $e->getMessage());
        }
    }
}

function clearUploadCentreCache() {
    unset($_SESSION['upload_stats_cache'], $_SESSION['upload_stats_cache_time']);
    foreach (array_keys($_SESSION) as $key) {
        if (strpos($key, 'recent_uploads_cache_') === 0) {
            unset($_SESSION[$key]);
        }
    }
}

// Fetch Summary Statistics
function getUploadCentreStats($userId = null) {
    if (isset($_SESSION['upload_stats_cache']) && (time() - ($_SESSION['upload_stats_cache_time'] ?? 0)) < 30) {
        return $_SESSION['upload_stats_cache'];
    }

    $stats = [
        'files' => 0,
        'imports' => 0,
        'rows' => 0,
        'warnings' => 0,
        'errors' => 0,
        'pending' => 0
    ];

    try {
        $userClause = "";
        $params = [];
        if ($userId) {
            $userClause = " WHERE uploaded_by = ? ";
            $params[] = $userId;
        }

        $r = dbQuery("
            SELECT count(*) as files, 
                   coalesce(sum(total_rows), 0) as rows, 
                   coalesce(sum(warning_rows), 0) as warnings, 
                   coalesce(sum(error_rows), 0) as errors,
                   count(*) FILTER (WHERE status = 'Completed') as imports,
                   (SELECT count(*) FROM public.upload_files WHERE mapping_version_id IS NULL OR status='Pending') as pending
            FROM public.uploads
            $userClause
        ", $params)->fetch(PDO::FETCH_ASSOC);

        if ($r) {
            $stats['files'] = (int)$r['files'];
            $stats['rows'] = (int)$r['rows'];
            $stats['warnings'] = (int)$r['warnings'];
            $stats['errors'] = (int)$r['errors'];
            $stats['imports'] = (int)$r['imports'];
            $stats['pending'] = (int)$r['pending'];
        }

        $_SESSION['upload_stats_cache'] = $stats;
        $_SESSION['upload_stats_cache_time'] = time();
    } catch (Throwable $e) {
        error_log("Error getting Upload Centre stats: " . $e->getMessage());
    }

    return $stats;
}

// Fetch Recent Imports List
function getRecentUploads($userId = null, $limit = 8) {
    $cacheKey = 'recent_uploads_cache_' . ($userId ?: 'all') . '_' . (int)$limit;
    if (isset($_SESSION[$cacheKey]) && (time() - ($_SESSION[$cacheKey . '_time'] ?? 0)) < 30) {
        return $_SESSION[$cacheKey];
    }

    $recent = [];
    try {
        $userClause = "";
        $params = [];
        if ($userId) {
            $userClause = " WHERE u.uploaded_by = ? ";
            $params[] = $userId;
        }

        $sql = "
            SELECT u.id, u.import_code, u.username, u.created_at, u.status, 
                   u.total_rows, u.success_rows, u.warning_rows, u.error_rows, 
                   string_agg(f.original_name, ', ') as files
            FROM public.uploads u 
            LEFT JOIN public.upload_files f ON f.upload_id = u.id 
            $userClause
            GROUP BY u.id 
            ORDER BY u.created_at DESC 
            LIMIT " . (int)$limit;

        $recent = dbQuery($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $_SESSION[$cacheKey] = $recent;
        $_SESSION[$cacheKey . '_time'] = time();
    } catch (Throwable $e) {
        error_log("Error fetching recent uploads: " . $e->getMessage());
    }
    return $recent;
}

// Dynamically discover columns and data types of target tables in public schema
function getTargetTableColumns($tableName) {
    try {
        $stmt = dbQuery("
            SELECT column_name, data_type, is_nullable 
            FROM information_schema.columns 
            WHERE table_schema = 'public' AND table_name = ?
            ORDER BY ordinal_position
        ", [$tableName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// Ingest & Analyze Uploaded Files
function analyzeUploadedFiles($files, $user) {
    $userId = $user['user_id'] ?? null;
    $username = $user['email'] ?? ($user['username'] ?? 'User');

    if (empty($files) || !isset($files['name'])) {
        return ['ok' => false, 'message' => 'No files selected for upload.'];
    }

    // Backend Organizational Scope Validation
    $orgContext = getActiveOrganizationContext();
    $customerId = $orgContext['customer_id'] ?? null;
    $companyId = $orgContext['company_id'] ?? null;
    $divisionId = $orgContext['division_id'] ?? null;
    $activeLoc = getActiveOperationalLocation();
    $locationType = $activeLoc['type_code'] ?? 'plant';
    $locationId = $activeLoc['id'] ?? null;
    $periodId = $orgContext['period_id'] ?? null;

    if (!$companyId || !$locationId || !$periodId) {
        return ['ok' => false, 'message' => 'Validation Error: Active scope (Company, Location, and Period) must be selected before uploading files.'];
    }

    if ($userId && !isPlatformAdmin($userId)) {
        if (!userCanAccessCompany($companyId, $userId) || !userCanAccessLocation($locationType, $locationId, $userId) || !userCanAccessPeriod($periodId, $userId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized to upload data for the selected organizational scope.'];
        }
    }

    $allowedExts = ['csv', 'xls', 'xlsx', 'pdf'];
    $importCode = 'IMP-' . date('ymdHis') . '-' . random_int(100, 999);

    // Create main upload run record
    $uploadId = dbQuery(
        "INSERT INTO public.uploads(import_code, uploaded_by, username, status) VALUES(?, ?, ?, 'Analyzing') RETURNING id",
        [$importCode, $userId, $username]
    )->fetchColumn();

    // Link upload run to active scope in upload_scopes table
    try {
        dbQuery("
            INSERT INTO public.upload_scopes (upload_id, customer_id, company_id, division_id, location_type, location_id, period_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ", [
            $uploadId,
            $customerId,
            $companyId,
            $divisionId,
            strtoupper($locationType ?: 'PLANT'),
            $locationId,
            $periodId
        ]);
    } catch (Throwable $se) {
        error_log("Upload Scope Insertion Warning: " . $se->getMessage());
    }

    $uploadDir = __DIR__ . '/../uploads/temp/' . date('Ymd') . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $processedFiles = [];
    $totalRunRows = 0;

    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = (int)$files['size'][$i];

        if (!in_array($ext, $allowedExts, true)) {
            continue;
        }

        $safeName = bin2hex(random_bytes(12)) . '.' . $ext;
        $storagePath = $uploadDir . $safeName;

        if (is_uploaded_file($files['tmp_name'][$i])) {
            move_uploaded_file($files['tmp_name'][$i], $storagePath);
        } else if (!empty($files['tmp_name'][$i]) && file_exists($files['tmp_name'][$i])) {
            copy($files['tmp_name'][$i], $storagePath);
        }

        $fileType = strtoupper($ext);
        $rows = 0;
        $cols = 0;
        $headers = [];
        $sampleData = [];

        if ($ext === 'csv') {
            $handle = fopen($storagePath, 'rb');
            if ($handle !== false) {
                $rawHeaders = fgetcsv($handle);
                if ($rawHeaders !== false) {
                    $headers = array_map('trim', $rawHeaders);
                    $cols = count($headers);
                }
                while (($row = fgetcsv($handle)) !== false) {
                    $rows++;
                    if (count($sampleData) < 5 && count($row) === $cols) {
                        $sampleData[] = array_combine($headers, $row);
                    }
                }
                fclose($handle);
            }
        } else {
            // XLS / XLSX / PDF fallback parser or basic text reader
            $content = file_get_contents($storagePath);
            $lines = array_filter(explode("\n", str_replace("\r", "", $content)));
            if (!empty($lines)) {
                $headers = explode(",", array_shift($lines));
                $cols = count($headers);
                $rows = count($lines);
            } else {
                $headers = ['Item Code', 'Description', 'Quantity', 'Rate'];
                $cols = 4;
                $rows = 1;
            }
        }

        $totalRunRows += $rows;

        // Keyword & Header Classification Rules
        $masterName = null;
        $subName = null;
        $confidence = 0;

        $haystack = strtolower(implode(' ', array_merge([$name], $headers)));
        
        $classificationRules = [
            ['purchase', 'Stock Ledger', 'Purchase Transaction', 98],
            ['consumption', 'Stock Ledger', 'Consumption Transaction', 98],
            ['production', 'Stock Ledger', 'Production Transaction', 98],
            ['machine', 'Work Center Master', 'Machine Master', 94],
            ['vendor', 'Account Master', 'Vendor Master', 98],
            ['cost', 'Cost Center Master', 'Cost Center Master', 92],
            ['overhead', 'Account Master', 'Overhead Ledger', 98],
            ['bom', 'BOM', 'BOM', 98],
            ['routing', 'BOM', 'Routing', 98],
            ['standard', 'Item Master', 'Standard Cost', 98],
            ['product', 'Item Master', 'Product Master', 98],
            ['item', 'Item Master', 'Item Master', 96]
        ];

        foreach ($classificationRules as $rule) {
            if (str_contains($haystack, $rule[0])) {
                $masterName = $rule[1];
                $subName = $rule[2];
                $confidence = $rule[3];
                break;
            }
        }

        if (!$masterName) {
            $masterName = 'Item Master';
            $subName = 'Item Master';
            $confidence = 75;
        }

        // Look up IDs from DB
        $mgId = dbQuery("SELECT id FROM public.master_groups WHERE name = ?", [$masterName])->fetchColumn() ?: null;
        $scId = dbQuery("SELECT id FROM public.sub_categories WHERE name = ? AND master_group_id = ?", [$subName, $mgId])->fetchColumn() ?: null;
        $mvId = $scId ? dbQuery("SELECT mv.id FROM public.mapping_configurations mc JOIN public.mapping_versions mv ON mv.id = mc.active_version_id WHERE mc.sub_category_id = ?", [$scId])->fetchColumn() ?: null : null;

        $status = $confidence >= 90 ? 'Ready' : ($confidence >= 70 ? 'Review' : 'Manual Review');
        $valStatus = $mvId ? 'Ready' : 'Pending Mapping';

        $fileId = dbQuery("
            INSERT INTO public.upload_files(upload_id, original_name, safe_name, file_type, size_bytes, rows_count, columns_count, headers, master_group_id, sub_category_id, mapping_version_id, validation_status, status, storage_path)
            VALUES(?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?) RETURNING id
        ", [
            $uploadId, $name, $safeName, $fileType, $size, $rows, $cols,
            json_encode($headers), $mgId, $scId, $mvId, $valStatus, $status, $storagePath
        ])->fetchColumn();

        $processedFiles[] = [
            'id' => $fileId,
            'name' => $name,
            'type' => $fileType,
            'size' => $size,
            'rows' => $rows,
            'columns' => $cols,
            'headers' => $headers,
            'master_group_id' => $mgId,
            'sub_category_id' => $scId,
            'master' => $masterName,
            'sub' => $subName,
            'confidence' => $confidence,
            'mapping' => $mvId ? 'Existing' : 'Auto Generated',
            'status' => $status
        ];

        logUploadAudit($userId, $username, 'FILE_UPLOADED', 'Upload Centre', $name, 'Success', ['rows' => $rows]);
        logUploadAudit($userId, $username, 'FILE_ANALYZED', 'Upload Centre', $name, 'Success', ['master' => $masterName, 'sub' => $subName, 'confidence' => $confidence]);
    }

    dbQuery("UPDATE public.uploads SET status = 'Ready', total_rows = ? WHERE id = ?", [$totalRunRows, $uploadId]);

    clearUploadCentreCache();

    $masterGroupOptions = getMasterGroupsAndSubCategories();

    return [
        'ok' => true,
        'upload_id' => $uploadId,
        'import_code' => $importCode,
        'files' => $processedFiles,
        'master_groups' => $masterGroupOptions
    ];
}

// Fetch Master Groups & Sub Categories for Dropdowns
function getMasterGroupsAndSubCategories() {
    try {
        $groups = dbQuery("SELECT id, name, code, description FROM public.master_groups ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $subCats = dbQuery("
            SELECT sc.id, sc.master_group_id, sc.name, sc.code, sc.target_entity_id, te.table_name, te.entity_name
            FROM public.sub_categories sc
            LEFT JOIN public.target_entities te ON te.id = sc.target_entity_id
            ORDER BY sc.name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($groups as $g) {
            $gSubs = array_values(array_filter($subCats, fn($s) => $s['master_group_id'] == $g['id']));
            $result[] = [
                'id' => $g['id'],
                'name' => $g['name'],
                'code' => $g['code'],
                'sub_categories' => $gSubs
            ];
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

// Update File Master Group / Sub Category Classification from Dropdown Change
function updateFileClassification($fileId, $subCategoryId) {
    try {
        $sub = dbQuery("SELECT * FROM public.sub_categories WHERE id = ?", [$subCategoryId])->fetch(PDO::FETCH_ASSOC);
        if (!$sub) return ['ok' => false, 'message' => 'Sub category not found'];

        dbQuery("
            UPDATE public.upload_files 
            SET master_group_id = ?, sub_category_id = ?, status = 'Ready' 
            WHERE id = ?
        ", [$sub['master_group_id'], $sub['id'], $fileId]);

        return ['ok' => true, 'sub_category' => $sub];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

// Fetch File Rows Preview
function getFilePreviewData($fileId) {
    $file = dbQuery("SELECT * FROM public.upload_files WHERE id = ?", [$fileId])->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        return ['ok' => false, 'message' => 'Upload file not found.'];
    }

    $ext = strtolower($file['file_type']);
    $data = [];
    $headers = json_decode($file['headers'] ?? '[]', true) ?: [];

    if (file_exists($file['storage_path']) && strtolower($ext) === 'csv') {
        $handle = fopen($file['storage_path'], 'rb');
        if ($handle !== false) {
            $rawHeaders = fgetcsv($handle);
            $count = 0;
            while (($row = fgetcsv($handle)) !== false && $count < 25) {
                if (count($row) === count($headers)) {
                    $data[] = array_combine($headers, $row);
                } else {
                    $rowPadded = array_pad($row, count($headers), '');
                    $data[] = array_combine($headers, array_slice($rowPadded, 0, count($headers)));
                }
                $count++;
            }
            fclose($handle);
        }
    }

    $targetTable = 'item_master';
    if (!empty($file['sub_category_id'])) {
        $tName = dbQuery("SELECT te.table_name FROM public.sub_categories sc JOIN public.target_entities te ON te.id = sc.target_entity_id WHERE sc.id = ?", [$file['sub_category_id']])->fetchColumn();
        if ($tName) {
            $targetTable = $tName;
        }
    }

    $targetCols = getTargetTableColumns($targetTable);

    return [
        'ok' => true,
        'file' => $file,
        'headers' => $headers,
        'data' => $data,
        'target_table' => $targetTable,
        'target_columns' => array_column($targetCols, 'column_name')
    ];
}

// Validate & Perform Real Import into Target Customer DB
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

    // Resolve active upload scope
    $scope = dbQuery("SELECT * FROM public.upload_scopes WHERE upload_id = ?", [$uploadId])->fetch(PDO::FETCH_ASSOC);
    $targetCustomerId = $scope['customer_id'] ?? (function_exists('getUserCustomerTenantId') ? getUserCustomerTenantId($userId) : 1);
    $targetCompanyId = $scope['company_id'] ?? ($_SESSION['active_company_id'] ?? null);
    $targetLocationType = $scope['location_type'] ?? 'PLANT';
    $targetLocationId = $scope['location_id'] ?? ($_SESSION['active_plant_id'] ?? $_SESSION['active_warehouse_id'] ?? null);
    $targetPeriodId = $scope['period_id'] ?? ($_SESSION['active_period_id'] ?? null);

    // Backend scope authorization validation
    if ($userId && function_exists('isPlatformAdmin') && !isPlatformAdmin($userId)) {
        if ($targetCompanyId && function_exists('userCanAccessCompany') && !userCanAccessCompany($targetCompanyId, $userId)) {
            return ['ok' => false, 'message' => 'Authorization Error: Access denied to target company scope.'];
        }
        if ($targetLocationId && function_exists('userCanAccessLocation') && !userCanAccessLocation($targetLocationType, $targetLocationId, $userId)) {
            return ['ok' => false, 'message' => 'Authorization Error: Access denied to target location scope.'];
        }
        if ($targetPeriodId && function_exists('userCanAccessPeriod') && !userCanAccessPeriod($targetPeriodId, $userId)) {
            return ['ok' => false, 'message' => 'Authorization Error: Access denied to target period scope.'];
        }
    }

    logUploadAudit($userId, $username, 'IMPORT_STARTED', 'Upload Centre', null, 'In Progress', ['upload_id' => $uploadId, 'customer_id' => $targetCustomerId]);

    $totalImported = 0;
    $totalSkipped = 0;
    $totalFailed = 0;

    // Resolve Customer DB PDO handle
    try {
        $pdo = getCustomerDbConnection($targetCustomerId, $userId);
    } catch (Throwable $e) {
        error_log("Customer DB Routing Warning (using central connection fallback): " . $e->getMessage());
        $pdo = getDbConnection();
    }

    foreach ($files as $f) {
        $headers = json_decode($f['headers'] ?? '[]', true) ?: [];
        $subCatId = $f['sub_category_id'];
        
        // Find target table
        $targetTable = 'item_master';
        if ($subCatId) {
            $tName = dbQuery("SELECT te.table_name FROM public.sub_categories sc JOIN public.target_entities te ON te.id = sc.target_entity_id WHERE sc.id = ?", [$subCatId])->fetchColumn();
            if ($tName) {
                $targetTable = $tName;
            }
        }

        // Discover dynamic target columns for this table
        $dbCols = getTargetTableColumns($targetTable);
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
                'desc'                => 'item_description',
                'description'         => 'item_description',
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
                }
            }

            // Enforce strict schema validation: filter out any key not physically present in $dbColNames
            $mappedData = array_filter($mappedData, fn($k) => in_array($k, $dbColNames, true), ARRAY_FILTER_USE_KEY);

            // Determine primary non-null identifier column for this table
            $primaryCol = $dbColNames[1] ?? ($dbColNames[0] ?? null);
            if ($primaryCol && !array_key_exists($primaryCol, $mappedData)) {
                // Primary column wasn't mapped from CSV headers at all — try to find an alternative identifier
                foreach ($dbColNames as $col) {
                    if ($col !== 'id' && $col !== 'created_at' && $col !== 'created_by' && !empty($mappedData[$col])) {
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

            // Build dynamic parameterised INSERT statement
            $colsToInsert = array_keys($mappedData);
            if (!in_array('created_by', $colsToInsert, true) && in_array('created_by', $dbColNames, true)) {
                $colsToInsert[] = 'created_by';
                $mappedData['created_by'] = $userId;
            }

            $colList = implode(', ', $colsToInsert);
            $placeholders = implode(', ', array_fill(0, count($colsToInsert), '?'));
            $values = array_values($mappedData);

            try {
                $insertSql = "INSERT INTO public.{$targetTable} ({$colList}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING";
                $stmt = $pdo->prepare($insertSql);
                $stmt->execute($values);
                $totalImported++;
            } catch (Throwable $e) {
                $totalFailed++;
                dbQuery("
                    INSERT INTO public.import_errors(upload_id, upload_file_id, row_number, field, error_type, error_message, severity)
                    VALUES(?, ?, ?, ?, 'Database Constraint Failure', ?, 'Critical')
                ", [$uploadId, $f['id'], $rowNum, $primaryCol, $e->getMessage()]);
            }
        }
        fclose($handle);
    }

    $finalStatus = ($totalFailed === 0) ? 'Completed' : 'Completed with Errors';

    dbQuery("
        UPDATE public.uploads 
        SET status = ?, success_rows = ?, warning_rows = ?, error_rows = ?, completed_at = now() 
        WHERE id = ?
    ", [$finalStatus, $totalImported, $totalSkipped, $totalFailed, $uploadId]);

    logUploadAudit($userId, $username, 'IMPORT_COMPLETED', 'Upload Centre', null, 'Success', [
        'imported' => $totalImported,
        'skipped' => $totalSkipped,
        'failed' => $totalFailed
    ]);

    clearUploadCentreCache();

    return [
        'ok' => true,
        'imported' => $totalImported,
        'skipped' => $totalSkipped,
        'failed' => $totalFailed,
        'status' => $finalStatus
    ];
}

// Fetch Audit Logs
function getUploadAuditLogs($limit = 100) {
    try {
        return dbQuery("
            SELECT audit_id, user_id, username, action, module, file_name, status, details, timestamp 
            FROM public.audit_logs 
            ORDER BY timestamp DESC 
            LIMIT " . (int)$limit
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// Fetch Dynamic Mapping Configurations Data from Neon DB
function getMappingConfigurationsData() {
    try {
        return dbQuery("
            SELECT mg.name as group_name, sc.name as sub_name, te.table_name,
                   coalesce(mv.version_label, 'v1.0') as version_label,
                   coalesce(mv.confidence, 98.00) as confidence,
                   coalesce(mv.status, sc.status) as status,
                   sc.created_at
            FROM public.sub_categories sc
            JOIN public.master_groups mg ON mg.id = sc.master_group_id
            LEFT JOIN public.target_entities te ON te.id = sc.target_entity_id
            LEFT JOIN public.mapping_configurations mc ON mc.sub_category_id = sc.id
            LEFT JOIN public.mapping_versions mv ON mv.id = mc.active_version_id
            ORDER BY mg.name, sc.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// Fetch Dynamic Master Groups Data from Neon DB
function getMasterGroupsData() {
    try {
        return dbQuery("
            SELECT mg.id, mg.code, mg.name, mg.description, mg.status, mg.created_at,
                   count(sc.id) as sub_count
            FROM public.master_groups mg
            LEFT JOIN public.sub_categories sc ON sc.master_group_id = mg.id
            GROUP BY mg.id
            ORDER BY mg.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// Fetch Detailed Row-Level Errors for an Upload Run from Neon DB
function getImportErrorsByUploadId($uploadId) {
    try {
        return dbQuery("
            SELECT id, upload_id, upload_file_id, row_number, field, source_value, error_type, error_message, severity, created_at
            FROM public.import_errors
            WHERE upload_id = ?
            ORDER BY row_number ASC, id ASC
            LIMIT 200
        ", [$uploadId])->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
