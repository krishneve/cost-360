<?php
/**
 * Cost360 - Upload Centre Core Service & Helpers
 * Supports the finalized 11-category Excel structure from EXCEL For Upload center (1).xlsx.
 * Handles multi-sheet XLSX workbook parsing, keyword and signature classification,
 * customer database resolution, column mapping, data validation, pre-flight profiling,
 * controlled atomic importing into dedicated customer databases (cust<ID>),
 * duplicate handling, organization & audit field enforcement, and row-level error traceability.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

// Helper function for global database execution
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

/**
 * Resolves the authenticated user's dedicated Customer Database context.
 * Performs secure backend resolution exclusively from session/user context.
 * Never trusts frontend-supplied customer_id or database_name.
 */
if (!function_exists('getUserCustomerDbContext')) {
    function getUserCustomerDbContext(?array $user = null): array {
        if (!$user) {
            $user = getAuthUser();
        }
        $userId = (int)($user['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        if ($userId <= 0) {
            throw new Exception("Unauthenticated user context.");
        }

        $globalPdo = getDbConnection();

        // 1. Resolve Customer ID
        $customerId = null;
        if (isset($_SESSION['active_customer_id']) && $_SESSION['active_customer_id'] !== 'ALL' && is_numeric($_SESSION['active_customer_id'])) {
            $customerId = (int)$_SESSION['active_customer_id'];
        }

        if (!$customerId) {
            $stmt = $globalPdo->prepare("
                SELECT uoa.customer_id
                FROM user_organization_access uoa
                WHERE uoa.user_id = :uid AND UPPER(uoa.status) = 'ACTIVE' AND uoa.customer_id IS NOT NULL
                ORDER BY uoa.access_id ASC LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $customerId = (int)($stmt->fetchColumn() ?: 0);
        }

        if (!$customerId || $customerId <= 0) {
            if (isPlatformAdmin($userId)) {
                $stmtFirst = $globalPdo->query("SELECT customer_id FROM customers WHERE UPPER(status) = 'ACTIVE' ORDER BY customer_id ASC LIMIT 1");
                $customerId = (int)($stmtFirst->fetchColumn() ?: 1);
            } else {
                throw new Exception("Unable to identify customer organization for logged-in user.");
            }
        }

        // 2. Retrieve database_name from customer_databases table in Global DB
        $stmtDb = $globalPdo->prepare("
            SELECT database_name, status
            FROM customer_databases
            WHERE customer_id = :cid AND UPPER(status) = 'ACTIVE'
            LIMIT 1
        ");
        $stmtDb->execute([':cid' => $customerId]);
        $dbRecord = $stmtDb->fetch(PDO::FETCH_ASSOC);

        if (!$dbRecord || empty($dbRecord['database_name'])) {
            // Auto-provision if missing
            $provisionRes = provisionCustomerDatabase($globalPdo, $customerId);
            if ($provisionRes['success']) {
                $dbName = $provisionRes['database_name'];
            } else {
                throw new Exception("Customer database is not provisioned or active for Customer ID {$customerId}: " . ($provisionRes['error'] ?? ''));
            }
        } else {
            $dbName = $dbRecord['database_name'];
        }

        // 3. Connect to dedicated Customer Database
        $custPdo = getCustomerDbConnection($dbName);

        return [
            'customer_id'   => $customerId,
            'database_name' => $dbName,
            'pdo'           => $custPdo
        ];
    }
}

/**
 * Returns the 11 finalized data category definitions from EXCEL For Upload center (1).xlsx
 */
function getFinalizedCategoryDefinitions(): array {
    return [
        '01_ITEM_PRODUCT_MASTER' => [
            'key'             => '01_ITEM_PRODUCT_MASTER',
            'dataset_type'    => 'MASTER',
            'name'            => 'Item Master Product Master',
            'sheet_name'      => '01.ITEM MASTER PRODUCT MASTER',
            'group_code'      => 'ITEM_PRODUCT_MASTER',
            'group_name'      => 'Item Master & Product Master',
            'target_table'    => 'items',
            'business_key'    => ['item_code'],
            'required_fields' => ['item_code'],
            'numeric_fields'  => ['std_rate', 'material_rate', 'labour_rate', 'overheads', 'actual_rate', 'total', 'net_weight_kg', 'gross_weight_kg', 'std_yield_pct'],
            'date_fields'     => [],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'item_type',
                'item_group_code', 'item_group_name', 'item_code', 'item_name', 'unit_of_measure',
                'std_rate', 'material_rate', 'labour_rate', 'overheads', 'actual_rate', 'total',
                'grade', 'net_weight_kg', 'gross_weight_kg', 'upload', 'raw_material', 'product',
                'std_yield_pct', 'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ],
        '02_WORK_CENTER_MASTER' => [
            'key'             => '02_WORK_CENTER_MASTER',
            'dataset_type'    => 'MASTER',
            'name'            => 'Work Center Master',
            'sheet_name'      => '02.WORK CENTER MASTER',
            'group_code'      => 'WORK_CENTER_MASTER',
            'group_name'      => 'Work Center Master',
            'target_table'    => 'work_centers',
            'business_key'    => ['wc_code'],
            'required_fields' => ['wc_code'],
            'numeric_fields'  => ['std_mhr', 'rated_hrs_day', 'hourly_rate'],
            'date_fields'     => [],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'wc_code',
                'wc_name', 'wc_group_code', 'wc_group_name', 'cc_code', 'cc_name', 'std_mhr',
                'rated_hrs_day', 'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ],
        '03_PARTY_MASTER' => [
            'key'             => '03_PARTY_MASTER',
            'dataset_type'    => 'MASTER',
            'name'            => 'Party Master',
            'sheet_name'      => '03.PARTY MASTER',
            'group_code'      => 'PARTY_MASTER',
            'group_name'      => 'Party Master',
            'target_table'    => 'party_master',
            'business_key'    => ['party_code'],
            'required_fields' => ['party_code'],
            'numeric_fields'  => ['payment_terms_days'],
            'date_fields'     => [],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'type',
                'party_code', 'party_name', 'party_pan', 'party_account', 'state',
                'payment_terms_days', 'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ],
        '04_COST_CENTER_MASTER' => [
            'key'             => '04_COST_CENTER_MASTER',
            'dataset_type'    => 'MASTER',
            'name'            => 'Cost Center Master',
            'sheet_name'      => '04.COST_CENTER_MASTER',
            'group_code'      => 'COST_CENTER_MASTER',
            'group_name'      => 'Cost Center Master',
            'target_table'    => 'cost_center_master',
            'business_key'    => ['cc_code'],
            'required_fields' => ['cc_code'],
            'numeric_fields'  => ['shift_capacity_hrs_per_month', 'is_production'],
            'date_fields'     => [],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'cc_code',
                'cc_name', 'cc_type', 'is_production', 'shift_capacity_hrs_per_month',
                'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ],
        '05_STANDARD_COST' => [
            'key'             => '05_STANDARD_COST',
            'dataset_type'    => 'MASTER',
            'name'            => 'Standard Cost',
            'sheet_name'      => '05. STANDARD_COST',
            'group_code'      => 'STANDARD_COST',
            'group_name'      => 'Standard Cost',
            'target_table'    => 'standard_cost',
            'business_key'    => ['product_code'],
            'required_fields' => ['product_code'],
            'numeric_fields'  => ['std_material', 'std_conversion', 'std_total_cost', 'processed_material', 'processed_conversion', 'processed_total_cost'],
            'date_fields'     => ['std_revision_date'],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'product_code',
                'std_material', 'std_conversion', 'std_total_cost', 'std_revision_date',
                'processed_material', 'processed_conversion', 'processed_total_cost',
                'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ],
        '05A_BOM' => [
            'key'             => '05A_BOM',
            'dataset_type'    => 'MASTER',
            'name'            => 'Bill of Materials',
            'sheet_name'      => '05.A_BOM',
            'group_code'      => 'BOM',
            'group_name'      => 'BOM',
            'target_table'    => 'bom',
            'business_key'    => ['parent_item_code', 'item_code'],
            'required_fields' => ['parent_item_code', 'item_code'],
            'numeric_fields'  => ['parent_item_qty', 'child_item_qty', 'material_rate', 'labour_rate', 'std_rate', 'processed_material_rate', 'processed_labour_rate', 'processed_std_rate', 'scrap_qty', 'byproduct_qty'],
            'date_fields'     => [],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'parent_item_code',
                'item_code', 'parent_item_qty', 'child_item_qty', 'uom', 'material_rate',
                'labour_rate', 'std_rate', 'processed_material_rate', 'processed_labour_rate',
                'processed_std_rate', 'scrap_item_code', 'scrap_qty', 'scrap_unit',
                'byproduct_item_code', 'byproduct_qty', 'byproduct_unit', 'wc_code',
                'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ],
        '05B_ROUTING' => [
            'key'             => '05B_ROUTING',
            'dataset_type'    => 'MASTER',
            'name'            => 'Routing',
            'sheet_name'      => '05.B_ROUTING',
            'group_code'      => 'ROUTING',
            'group_name'      => 'Routing',
            'target_table'    => 'routing',
            'business_key'    => ['product_code', 'cc_code', 'machine_code'],
            'required_fields' => ['product_code', 'cc_code'],
            'numeric_fields'  => ['std_machine_hrs', 'std_labour_hrs', 'std_machine_hr_rate', 'std_labour_rate', 'std_conversion_cost'],
            'date_fields'     => [],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'product_code',
                'cc_code', 'machine_code', 'std_machine_hrs', 'std_labour_hrs', 'std_machine_hr_rate',
                'std_labour_rate', 'std_conversion_cost', 'created_at', 'created_by',
                'modified_at', 'modified_by'
            ]
        ],
        '06_MATERIAL_TRANSACTION' => [
            'key'             => '06_MATERIAL_TRANSACTION',
            'dataset_type'    => 'TRANSACTION',
            'name'            => 'Material Transaction',
            'sheet_name'      => '06.MATERIAL TRANSACTION',
            'group_code'      => 'MATERIAL_TRANSACTION',
            'group_name'      => 'Material Transaction',
            'target_table'    => 'material_transaction',
            'business_key'    => ['doc_no', 'item_code'],
            'required_fields' => ['doc_no', 'item_code'],
            'numeric_fields'  => ['qty', 'rejected_qty', 'gross_weight', 'net_weight', 'actual_yield', 'std_yield', 'rate', 'amount', 'std_rate', 'actual_rate', 'std_realisation_rate'],
            'date_fields'     => ['doc_date'],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'doc_type',
                'doc_no', 'doc_date', 'party_code', 'party_name', 'item_code', 'item_name',
                'uom', 'qty', 'rejected_qty', 'gross_weight', 'net_weight', 'weight_unit',
                'actual_yield', 'std_yield', 'rate', 'amount', 'std_rate', 'actual_rate',
                'std_realisation_rate', 'approval_ref', 'remarks', 'cc_code', 'product_code',
                'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ],
        '07_DOWNTIME' => [
            'key'             => '07_DOWNTIME',
            'dataset_type'    => 'TRANSACTION',
            'name'            => 'Downtime',
            'sheet_name'      => '07.DOWNTIME',
            'group_code'      => 'DOWNTIME',
            'group_name'      => 'Downtime',
            'target_table'    => 'downtime',
            'business_key'    => ['log_date', 'machine_code'],
            'required_fields' => ['log_date', 'machine_code'],
            'numeric_fields'  => ['rated_hrs', 'run_hrs', 'breakdown_hrs', 'idle_hrs', 'power_units_kwh'],
            'date_fields'     => ['log_date'],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'log_date',
                'machine_code', 'machine_name', 'cc_code', 'rated_hrs', 'run_hrs',
                'breakdown_hrs', 'idle_hrs', 'power_units_kwh', 'created_at', 'created_by',
                'modified_at', 'modified_by'
            ]
        ],
        '08_FINANCE_TRANSACTION' => [
            'key'             => '08_FINANCE_TRANSACTION',
            'dataset_type'    => 'TRANSACTION',
            'name'            => 'Finance Transaction',
            'sheet_name'      => '08.FINANCE TRANSACTION',
            'group_code'      => 'FINANCE_TRANSACTION',
            'group_name'      => 'Finance Transaction',
            'target_table'    => 'finance_transaction',
            'business_key'    => ['no', 'date', 'oh_code'],
            'required_fields' => ['no', 'amount'],
            'numeric_fields'  => ['amount'],
            'date_fields'     => ['date'],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'no',
                'date', 'oh_code', 'oh_name', 'oh_behaviour', 'cc_code', 'cc_name',
                'amount', 'remarks', 'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ],
        '09_TRIAL_BALANCE' => [
            'key'             => '09_TRIAL_BALANCE',
            'dataset_type'    => 'TRANSACTION',
            'name'            => 'Trial Balance',
            'sheet_name'      => '09.TRIAL BALANCE',
            'group_code'      => 'TRIAL_BALANCE',
            'group_name'      => 'Trial Balance',
            'target_table'    => 'trial_balance',
            'business_key'    => ['acc_code'],
            'required_fields' => ['acc_code'],
            'numeric_fields'  => ['opening_balance', 'opening_debit_opening_credit', 'transacted_debit', 'transacted_credit', 'closing_balance', 'closing_debit_closing_credit'],
            'date_fields'     => [],
            'headers'         => [
                'customer_code', 'company_code', 'division_code', 'location_code', 'acc_code',
                'description', 'opening_balance', 'opening_balance_sign_d_c',
                'opening_debit_opening_credit', 'transacted_debit', 'transacted_credit',
                'closing_balance', 'closing_balance_sign_d_c', 'closing_debit_closing_credit',
                'created_at', 'created_by', 'modified_at', 'modified_by'
            ]
        ]
    ];
}

/**
 * Native, memory-efficient XLSX Workbook Parser.
 * Returns metadata and headers for all worksheets in the XLSX archive.
 */
function parseXlsxWorkbook(string $filePath): array {
    if (!file_exists($filePath)) {
        throw new Exception("File not found: {$filePath}");
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception("Unable to open XLSX spreadsheet archive: {$filePath}");
    }

    // 1. Shared Strings Table
    $sharedStrings = [];
    $ssContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssContent !== false) {
        $cleanedSs = preg_replace('/<(\/?)[a-zA-Z0-9_\-]+:([a-zA-Z0-9_\-]+)/', '<$1$2', $ssContent);
        $xml = simplexml_load_string($cleanedSs);
        if ($xml) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $str = '';
                    foreach ($si->r as $r) {
                        $str .= (string)$r->t;
                    }
                    $sharedStrings[] = $str;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    // 2. Workbook Relationships
    $rels = [];
    $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsContent !== false) {
        $relsXml = simplexml_load_string($relsContent);
        if ($relsXml) {
            foreach ($relsXml->Relationship as $rel) {
                $rels[(string)$rel['Id']] = (string)$rel['Target'];
            }
        }
    }

    // 3. Workbook definition & Sheets
    $wbContent = $zip->getFromName('xl/workbook.xml');
    if ($wbContent === false) {
        $zip->close();
        throw new Exception("Invalid XLSX file: missing xl/workbook.xml");
    }

    $wbXml = simplexml_load_string($wbContent);
    if (!$wbXml || !isset($wbXml->sheets->sheet)) {
        $zip->close();
        throw new Exception("No sheets found in workbook");
    }

    $sheets = [];
    $sheetIndex = 0;

    $colIndexFromCoord = function($cellRef) {
        $colLetters = preg_replace('/[0-9]/', '', $cellRef);
        $col = 0;
        for ($i = 0; $i < strlen($colLetters); $i++) {
            $col = $col * 26 + (ord(strtoupper($colLetters[$i])) - 64);
        }
        return $col - 1;
    };

    foreach ($wbXml->sheets->sheet as $s) {
        $sheetName = (string)$s['name'];
        $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $rels[$rId] ?? '';
        if (strpos($target, 'xl/') !== 0 && strpos($target, '/') !== 0) {
            $target = 'xl/' . $target;
        } else {
            $target = ltrim($target, '/');
        }

        $sheetContent = $zip->getFromName($target);
        if ($sheetContent === false) {
            continue;
        }

        $cleanedSheet = preg_replace('/<(\/?)[a-zA-Z0-9_\-]+:([a-zA-Z0-9_\-]+)/', '<$1$2', $sheetContent);
        $sheetXml = simplexml_load_string($cleanedSheet);
        if (!$sheetXml || !isset($sheetXml->sheetData->row)) {
            $sheets[] = [
                'sheet_name'    => $sheetName,
                'sheet_index'   => $sheetIndex++,
                'headers'       => [],
                'rows_count'    => 0,
                'columns_count' => 0,
                'sample_rows'   => []
            ];
            continue;
        }

        $headers = [];
        $sampleRows = [];
        $dataRowCount = 0;

        foreach ($sheetXml->sheetData->row as $r) {
            $rowVals = [];
            $maxCol = 0;
            $currCol = 0;

            foreach ($r->c as $c) {
                $ref = (string)$c['r'];
                if ($ref !== '') {
                    $colIdx = $colIndexFromCoord($ref);
                    $currCol = $colIdx + 1;
                } else {
                    $colIdx = $currCol++;
                }
                if ($colIdx > $maxCol) $maxCol = $colIdx;
                $t = (string)$c['t'];
                $val = isset($c->v) ? (string)$c->v : '';
                if ($t === 's' && is_numeric($val) && isset($sharedStrings[(int)$val])) {
                    $val = $sharedStrings[(int)$val];
                } elseif ($t === 'inlineStr' && isset($c->is->t)) {
                    $val = (string)$c->is->t;
                }
                $rowVals[$colIdx] = trim($val);
            }

            $fullRow = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $fullRow[$i] = $rowVals[$i] ?? '';
            }

            if (empty($headers)) {
                while (!empty($fullRow) && end($fullRow) === '') {
                    array_pop($fullRow);
                }
                $headers = $fullRow;
            } else {
                $hasData = false;
                foreach ($fullRow as $v) {
                    if ($v !== '') {
                        $hasData = true;
                        break;
                    }
                }
                if ($hasData) {
                    $nonEmpty = array_values(array_filter($fullRow, fn($v) => $v !== ''));
                    // Skip instructional template hints like '(supplier/customer)' or '(percentage)'
                    $isInstructionRow = (count($nonEmpty) === 1 && strpos($nonEmpty[0], '(') === 0 && str_ends_with($nonEmpty[0], ')'));
                    if (!$isInstructionRow) {
                        $dataRowCount++;
                        if (count($sampleRows) < 25) {
                            $sampleRows[] = $fullRow;
                        }
                    }
                }
            }
        }

        $sheets[] = [
            'sheet_name'    => $sheetName,
            'sheet_index'   => $sheetIndex++,
            'headers'       => $headers,
            'rows_count'    => $dataRowCount,
            'columns_count' => count($headers),
            'sample_rows'   => $sampleRows
        ];
    }

    $zip->close();
    return $sheets;
}

/**
 * Reads data rows for a specific worksheet in an XLSX archive.
 */
function parseXlsxSheetRows(string $filePath, string $targetSheetName, ?int $maxRows = null): array {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [];
    }

    $sharedStrings = [];
    $ssContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssContent !== false) {
        $cleanedSs = preg_replace('/<(\/?)[a-zA-Z0-9_\-]+:([a-zA-Z0-9_\-]+)/', '<$1$2', $ssContent);
        $xml = simplexml_load_string($cleanedSs);
        if ($xml) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $str = '';
                    foreach ($si->r as $r) {
                        $str .= (string)$r->t;
                    }
                    $sharedStrings[] = $str;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    $rels = [];
    $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsContent !== false) {
        $relsXml = simplexml_load_string($relsContent);
        if ($relsXml) {
            foreach ($relsXml->Relationship as $rel) {
                $rels[(string)$rel['Id']] = (string)$rel['Target'];
            }
        }
    }

    $wbContent = $zip->getFromName('xl/workbook.xml');
    if ($wbContent === false) {
        $zip->close();
        return [];
    }

    $wbXml = simplexml_load_string($wbContent);
    $targetPath = null;

    foreach ($wbXml->sheets->sheet as $s) {
        if (trim(strtolower((string)$s['name'])) === trim(strtolower($targetSheetName))) {
            $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $rels[$rId] ?? '';
            $targetPath = (strpos($target, 'xl/') !== 0 && strpos($target, '/') !== 0) ? 'xl/' . $target : ltrim($target, '/');
            break;
        }
    }

    if (!$targetPath) {
        // Fallback: search by prefix or index if exact match not found
        foreach ($wbXml->sheets->sheet as $s) {
            $sName = (string)$s['name'];
            if (stripos($sName, $targetSheetName) !== false || stripos($targetSheetName, $sName) !== false) {
                $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = $rels[$rId] ?? '';
                $targetPath = (strpos($target, 'xl/') !== 0 && strpos($target, '/') !== 0) ? 'xl/' . $target : ltrim($target, '/');
                break;
            }
        }
    }

    if (!$targetPath) {
        $zip->close();
        return [];
    }

    $sheetContent = $zip->getFromName($targetPath);
    if ($sheetContent === false) {
        $zip->close();
        return [];
    }

    $cleanedSheet = preg_replace('/<(\/?)[a-zA-Z0-9_\-]+:([a-zA-Z0-9_\-]+)/', '<$1$2', $sheetContent);
    $sheetXml = simplexml_load_string($cleanedSheet);
    $rows = [];
    $headers = [];

    if (!$sheetXml || !isset($sheetXml->sheetData->row)) {
        $zip->close();
        return ['headers' => [], 'rows' => []];
    }

    $colIndexFromCoord = function($cellRef) {
        $colLetters = preg_replace('/[0-9]/', '', $cellRef);
        $col = 0;
        for ($i = 0; $i < strlen($colLetters); $i++) {
            $col = $col * 26 + (ord(strtoupper($colLetters[$i])) - 64);
        }
        return $col - 1;
    };

    foreach ($sheetXml->sheetData->row as $r) {
        $rowVals = [];
        $maxCol = 0;
        $currCol = 0;

        foreach ($r->c as $c) {
            $ref = (string)$c['r'];
            if ($ref !== '') {
                $colIdx = $colIndexFromCoord($ref);
                $currCol = $colIdx + 1;
            } else {
                $colIdx = $currCol++;
            }
            if ($colIdx > $maxCol) $maxCol = $colIdx;
            $t = (string)$c['t'];
            $val = isset($c->v) ? (string)$c->v : '';
            if ($t === 's' && is_numeric($val) && isset($sharedStrings[(int)$val])) {
                $val = $sharedStrings[(int)$val];
            } elseif ($t === 'inlineStr' && isset($c->is->t)) {
                $val = (string)$c->is->t;
            }
            $rowVals[$colIdx] = trim($val);
        }

        $fullRow = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $fullRow[$i] = $rowVals[$i] ?? '';
        }

        if (empty($headers)) {
            while (!empty($fullRow) && end($fullRow) === '') {
                array_pop($fullRow);
            }
            $headers = $fullRow;
        } else {
            $hasData = false;
            foreach ($fullRow as $v) {
                if ($v !== '') {
                    $hasData = true;
                    break;
                }
            }
            if ($hasData) {
                $nonEmpty = array_values(array_filter($fullRow, fn($v) => $v !== ''));
                $isInstructionRow = (count($nonEmpty) === 1 && strpos($nonEmpty[0], '(') === 0 && str_ends_with($nonEmpty[0], ')'));
                if (!$isInstructionRow) {
                    $rows[] = $fullRow;
                    if ($maxRows !== null && count($rows) >= $maxRows) {
                        break;
                    }
                }
            }
        }
    }

    $zip->close();
    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Normalizes Excel dates (converting serial numbers like 45210 or various date strings into YYYY-MM-DD).
 */
function normalizeExcelDate($val): ?string {
    if ($val === null || $val === '') {
        return null;
    }
    $clean = trim((string)$val);
    if (is_numeric($clean) && (float)$clean > 20000 && (float)$clean < 90000) {
        // Excel serial date to UNIX timestamp
        $unixTimestamp = ((float)$clean - 25569) * 86400;
        return gmdate('Y-m-d', (int)$unixTimestamp);
    }
    $ts = strtotime($clean);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }
    return null;
}

/**
 * Normalizes material transaction type across all 5 canonical types:
 * Purchase, Consumption, Production, Issue, Scrap Sales
 */
function normalizeMaterialTransactionType(string $rawType, string $sheetName = '', string $fileName = '', string $subCode = ''): string {
    $sources = [$rawType, $subCode, $sheetName, $fileName];
    foreach ($sources as $src) {
        $s = strtolower(trim($src));
        if ($s === '') continue;
        if (strpos($s, 'purch') !== false || strpos($s, 'grn') !== false || strpos($s, 'po') !== false || strpos($s, 'receipt') !== false || strpos($s, 'inward') !== false || strpos($s, 'mrn') !== false) {
            return 'Purchase';
        }
        if (strpos($s, 'consum') !== false || strpos($s, 'used') !== false) {
            return 'Consumption';
        }
        if (strpos($s, 'product') !== false || strpos($s, 'fg') !== false || strpos($s, 'wo') !== false || strpos($s, 'output') !== false) {
            return 'Production';
        }
        if (strpos($s, 'scrap') !== false || strpos($s, 'waste') !== false || strpos($s, 'byproduct') !== false) {
            return 'Scrap Sales';
        }
        if (strpos($s, 'issue') !== false || strpos($s, 'transfer') !== false || strpos($s, 'dispatch') !== false || strpos($s, 'outward') !== false) {
            return 'Issue';
        }
    }
    return !empty($rawType) ? ucfirst(strtolower(trim($rawType))) : 'Purchase';
}

/**
 * High-precision worksheet classification into one of the 11 finalized categories.
 */

/**
 * Returns true if the dataset category is Master/Reference data (Worksheets 1-7).
 */
function isMasterDataset(string $categoryKey): bool {
    $defs = getFinalizedCategoryDefinitions();
    return ($defs[$categoryKey]['dataset_type'] ?? 'MASTER') === 'MASTER';
}

/**
 * Resolves accounting period context from request, session, or latest open period.
 */
function getAccountingPeriodContext(?int $periodId = null): ?array {
    $globalPdo = getDbConnection();
    if ($periodId && $periodId > 0) {
        $stmt = $globalPdo->prepare("SELECT * FROM public.periods WHERE period_id = ?");
        $stmt->execute([$periodId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) return $p;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['active_period_id'])) {
        $stmt = $globalPdo->prepare("SELECT * FROM public.periods WHERE period_id = ?");
        $stmt->execute([(int)$_SESSION['active_period_id']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) return $p;
    }
    // Fallback to latest active period
    $stmt = $globalPdo->query("SELECT * FROM public.periods WHERE UPPER(status) = 'ACTIVE' ORDER BY start_date DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Validates accounting period before transaction import.
 * Ensures period is not locked and is currently active.
 */
function validateAccountingPeriodForImport(?array $period): array {
    if (!$period) {
        return ['valid' => false, 'error' => 'No active accounting period selected or resolved for transaction import.'];
    }
    if (!empty($period['is_locked']) || $period['is_locked'] === true || $period['is_locked'] === 't' || $period['is_locked'] === 'true' || $period['is_locked'] == 1) {
        return ['valid' => false, 'error' => "Accounting period '{$period['period_name']}' is LOCKED. Transaction data imports are strictly prohibited into locked periods."];
    }
    if (strtoupper($period['status'] ?? '') !== 'ACTIVE') {
        return ['valid' => false, 'error' => "Accounting period '{$period['period_name']}' is not Active (Status: {$period['status']}). Transactions cannot be imported."];
    }
    return ['valid' => true, 'period' => $period];
}

function classifyWorksheet(string $nameOrSheet, array $headers): array {
    $defs = getFinalizedCategoryDefinitions();
    $cleanName = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $nameOrSheet)));
    $cleanHeaders = array_map(fn($h) => strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $h))), $headers);

    $bestMatch = null;
    $bestScore = 0;

    foreach ($defs as $key => $def) {
        $score = 0;
        $defCleanSheet = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $def['sheet_name'])));
        $defCleanName = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $def['name'])));

        // 1. Sheet name match
        if ($cleanName === $defCleanSheet || strpos($cleanName, $defCleanSheet) !== false || strpos($defCleanSheet, $cleanName) !== false) {
            $score += 60;
        } elseif (strpos($cleanName, $defCleanName) !== false || strpos($defCleanName, $cleanName) !== false) {
            $score += 45;
        }

        // 2. Specific distinctive key phrases in sheet name
        if ($key === '01_ITEM_PRODUCT_MASTER' && (strpos($cleanName, 'itemmaster') !== false || strpos($cleanName, 'productmaster') !== false || strpos($cleanName, '01item') !== false)) {
            $score += 35;
        } elseif ($key === '02_WORK_CENTER_MASTER' && (strpos($cleanName, 'workcenter') !== false || strpos($cleanName, '02work') !== false)) {
            $score += 35;
        } elseif ($key === '03_PARTY_MASTER' && (strpos($cleanName, 'party') !== false || strpos($cleanName, '03party') !== false)) {
            $score += 35;
        } elseif ($key === '04_COST_CENTER_MASTER' && (strpos($cleanName, 'costcenter') !== false || strpos($cleanName, '04cost') !== false)) {
            $score += 35;
        } elseif ($key === '05_STANDARD_COST' && (strpos($cleanName, 'standardcost') !== false || strpos($cleanName, 'stdcost') !== false || strpos($cleanName, '05standard') !== false)) {
            $score += 35;
        } elseif ($key === '05A_BOM' && (strpos($cleanName, 'bom') !== false || strpos($cleanName, '05abom') !== false)) {
            $score += 35;
        } elseif ($key === '05B_ROUTING' && (strpos($cleanName, 'routing') !== false || strpos($cleanName, '05brouting') !== false)) {
            $score += 35;
        } elseif ($key === '06_MATERIAL_TRANSACTION' && (
            strpos($cleanName, 'materialtransaction') !== false ||
            strpos($cleanName, '06material') !== false ||
            strpos($cleanName, 'material') !== false ||
            strpos($cleanName, 'purchase') !== false ||
            strpos($cleanName, 'consumption') !== false ||
            strpos($cleanName, 'production') !== false ||
            strpos($cleanName, 'issue') !== false ||
            strpos($cleanName, 'scrapsales') !== false ||
            strpos($cleanName, 'scrap') !== false ||
            strpos($cleanName, 'grn') !== false
        )) {
            $score += 35;
        } elseif ($key === '07_DOWNTIME' && (strpos($cleanName, 'downtime') !== false || strpos($cleanName, '07down') !== false)) {
            $score += 35;
        } elseif ($key === '08_FINANCE_TRANSACTION' && (strpos($cleanName, 'financetransaction') !== false || strpos($cleanName, '08finance') !== false)) {
            $score += 35;
        } elseif ($key === '09_TRIAL_BALANCE' && (strpos($cleanName, 'trialbalance') !== false || strpos($cleanName, '09trial') !== false)) {
            $score += 35;
        }

        // 3. Header signature match
        $defCleanHeaders = array_map(fn($h) => strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $h))), $def['headers']);
        $matchedHeadersCount = 0;
        foreach ($cleanHeaders as $ch) {
            if (in_array($ch, $defCleanHeaders, true)) {
                $matchedHeadersCount++;
            }
        }

        if (count($defCleanHeaders) > 0) {
            $headerRatio = $matchedHeadersCount / count($defCleanHeaders);
            $score += ($headerRatio * 50);
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $def;
        }
    }

    $confidence = min(99, (int)round($bestScore));
    if (!$bestMatch || $confidence < 30) {
        $bestMatch = $defs['01_ITEM_PRODUCT_MASTER'];
        $confidence = 40;
    }

    return [
        'category'   => $bestMatch,
        'confidence' => $confidence
    ];
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
                   count(*) FILTER (WHERE status LIKE 'Completed%') as imports,
                   (SELECT count(*) FROM public.upload_files WHERE validation_status = 'Pending' OR status='Pending') as pending
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
                   u.inserted_rows, u.updated_rows, u.unchanged_rows, u.period_id, u.period_code,
                   string_agg(DISTINCT f.original_name, ', ') as files
            FROM public.uploads u 
            LEFT JOIN public.upload_files f ON f.upload_id = u.id 
            $userClause
            GROUP BY u.id 
            ORDER BY u.created_at DESC 
            LIMIT " . (int)$limit;

        $stmt = dbQuery($sql, $params);
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $_SESSION[$cacheKey] = $recent;
        $_SESSION[$cacheKey . '_time'] = time();
    } catch (Throwable $e) {
        error_log("Error getting recent uploads: " . $e->getMessage());
    }

    return $recent;
}

/**
 * Returns predefined table schema DDL for all 11 business entities in Customer DB.
 * Incorporates organization scope columns and audit columns.
 */
function getPredefinedTableSchema(string $tableName): ?string {
    switch (strtolower(trim($tableName))) {
        case 'items':
        case 'item_master':
            return "
                CREATE TABLE IF NOT EXISTS public.items (
                    item_id          BIGSERIAL PRIMARY KEY,
                    customer_code    VARCHAR(100),
                    company_code     VARCHAR(100),
                    division_code    VARCHAR(100),
                    location_code    VARCHAR(100),
                    item_type        VARCHAR(100),
                    item_group_code  VARCHAR(100),
                    item_group_name  VARCHAR(255),
                    item_code        VARCHAR(100) NOT NULL,
                    item_name        VARCHAR(255),
                    unit_of_measure  VARCHAR(50),
                    std_rate         NUMERIC(15,4) DEFAULT 0,
                    material_rate    NUMERIC(15,4) DEFAULT 0,
                    labour_rate      NUMERIC(15,4) DEFAULT 0,
                    overheads        NUMERIC(15,4) DEFAULT 0,
                    actual_rate      NUMERIC(15,4) DEFAULT 0,
                    total            NUMERIC(15,4) DEFAULT 0,
                    grade            VARCHAR(100),
                    net_weight_kg    NUMERIC(15,4) DEFAULT 0,
                    gross_weight_kg  NUMERIC(15,4) DEFAULT 0,
                    upload           VARCHAR(50),
                    raw_material     VARCHAR(100),
                    product          VARCHAR(100),
                    std_yield_pct    NUMERIC(10,4) DEFAULT 0,
                    status           VARCHAR(50) DEFAULT 'Active',
                    created_at       TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by       BIGINT,
                    modified_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by      BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_items_code ON public.items(item_code);
            ";

        case 'work_centers':
        case 'work_center_master':
            return "
                CREATE TABLE IF NOT EXISTS public.work_centers (
                    work_center_id   BIGSERIAL PRIMARY KEY,
                    customer_code    VARCHAR(100),
                    company_code     VARCHAR(100),
                    division_code    VARCHAR(100),
                    location_code    VARCHAR(100),
                    wc_code          VARCHAR(100) NOT NULL,
                    wc_name          VARCHAR(255),
                    wc_group_code    VARCHAR(100),
                    wc_group_name    VARCHAR(255),
                    cc_code          VARCHAR(100),
                    cc_name          VARCHAR(255),
                    std_mhr          NUMERIC(15,4) DEFAULT 0,
                    rated_hrs_day    NUMERIC(15,4) DEFAULT 0,
                    hourly_rate      NUMERIC(15,4) DEFAULT 0,
                    work_center_code VARCHAR(100),
                    work_center_name VARCHAR(255),
                    status           VARCHAR(50) DEFAULT 'Active',
                    created_at       TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by       BIGINT,
                    modified_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by      BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_wc_code ON public.work_centers(wc_code);
            ";

        case 'party_master':
        case 'parties':
            return "
                CREATE TABLE IF NOT EXISTS public.party_master (
                    id                 BIGSERIAL PRIMARY KEY,
                    customer_code      VARCHAR(100),
                    company_code       VARCHAR(100),
                    division_code      VARCHAR(100),
                    location_code      VARCHAR(100),
                    type               VARCHAR(50),
                    party_code         VARCHAR(100) NOT NULL,
                    party_name         VARCHAR(255),
                    party_pan          VARCHAR(50),
                    party_account      VARCHAR(100),
                    state              VARCHAR(100),
                    payment_terms_days NUMERIC(10,2) DEFAULT 0,
                    status             VARCHAR(50) DEFAULT 'Active',
                    created_at         TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by         BIGINT,
                    modified_at        TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by        BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_party_code ON public.party_master(party_code);
            ";

        case 'cost_center_master':
        case 'cost_centers':
            return "
                CREATE TABLE IF NOT EXISTS public.cost_center_master (
                    id                           BIGSERIAL PRIMARY KEY,
                    customer_code                VARCHAR(100),
                    company_code                 VARCHAR(100),
                    division_code                VARCHAR(100),
                    location_code                VARCHAR(100),
                    cc_code                      VARCHAR(100) NOT NULL,
                    cc_name                      VARCHAR(255),
                    cc_type                      VARCHAR(100),
                    is_production                INT DEFAULT 1,
                    shift_capacity_hrs_per_month NUMERIC(15,4) DEFAULT 0,
                    department                   VARCHAR(100),
                    cost_center_code             VARCHAR(100),
                    cost_center_name             VARCHAR(255),
                    status                       VARCHAR(50) DEFAULT 'Active',
                    created_at                   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by                   BIGINT,
                    modified_at                  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by                  BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_cc_master_code ON public.cost_center_master(cc_code);
            ";

        case 'standard_cost':
            return "
                CREATE TABLE IF NOT EXISTS public.standard_cost (
                    cost_id                 BIGSERIAL PRIMARY KEY,
                    id                      BIGSERIAL,
                    customer_code           VARCHAR(100),
                    company_code            VARCHAR(100),
                    division_code           VARCHAR(100),
                    location_code           VARCHAR(100),
                    product_code            VARCHAR(100) NOT NULL,
                    item_code               VARCHAR(100),
                    std_material            NUMERIC(15,4) DEFAULT 0,
                    std_conversion          NUMERIC(15,4) DEFAULT 0,
                    std_total_cost          NUMERIC(15,4) DEFAULT 0,
                    std_revision_date       DATE DEFAULT CURRENT_DATE,
                    processed_material      NUMERIC(15,4) DEFAULT 0,
                    processed_conversion    NUMERIC(15,4) DEFAULT 0,
                    processed_total_cost    NUMERIC(15,4) DEFAULT 0,
                    cost_component          VARCHAR(100) DEFAULT 'Total',
                    cost_value              NUMERIC(15,4) DEFAULT 0,
                    status                  VARCHAR(50) DEFAULT 'Active',
                    created_at              TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by              BIGINT,
                    modified_at             TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by             BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_std_cost_prod ON public.standard_cost(product_code);
            ";

        case 'bom':
            return "
                CREATE TABLE IF NOT EXISTS public.bom (
                    id                     BIGSERIAL PRIMARY KEY,
                    customer_code          VARCHAR(100),
                    company_code           VARCHAR(100),
                    division_code          VARCHAR(100),
                    location_code          VARCHAR(100),
                    parent_item_code       VARCHAR(100) NOT NULL,
                    item_code              VARCHAR(100) NOT NULL,
                    parent_item_qty        NUMERIC(15,4) DEFAULT 1,
                    child_item_qty         NUMERIC(15,4) DEFAULT 1,
                    quantity               NUMERIC(15,4) DEFAULT 1,
                    uom                    VARCHAR(50),
                    material_rate          NUMERIC(15,4) DEFAULT 0,
                    labour_rate            NUMERIC(15,4) DEFAULT 0,
                    std_rate               NUMERIC(15,4) DEFAULT 0,
                    processed_material_rate NUMERIC(15,4) DEFAULT 0,
                    processed_labour_rate  NUMERIC(15,4) DEFAULT 0,
                    processed_std_rate     NUMERIC(15,4) DEFAULT 0,
                    scrap_item_code        VARCHAR(100),
                    scrap_qty              NUMERIC(15,4) DEFAULT 0,
                    scrap_unit             VARCHAR(50),
                    byproduct_item_code    VARCHAR(100),
                    byproduct_qty          NUMERIC(15,4) DEFAULT 0,
                    byproduct_unit         VARCHAR(50),
                    wc_code                VARCHAR(100),
                    status                 VARCHAR(50) DEFAULT 'Active',
                    created_at             TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by             BIGINT,
                    modified_at            TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by            BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_bom_parent ON public.bom(parent_item_code);
                CREATE INDEX IF NOT EXISTS idx_bom_child ON public.bom(item_code);
            ";

        case 'routing':
            return "
                CREATE TABLE IF NOT EXISTS public.routing (
                    id                  BIGSERIAL PRIMARY KEY,
                    customer_code       VARCHAR(100),
                    company_code        VARCHAR(100),
                    division_code       VARCHAR(100),
                    location_code       VARCHAR(100),
                    product_code        VARCHAR(100) NOT NULL,
                    item_code           VARCHAR(100),
                    cc_code             VARCHAR(100) NOT NULL,
                    machine_code        VARCHAR(100),
                    std_machine_hrs     NUMERIC(15,5) DEFAULT 0,
                    std_labour_hrs      NUMERIC(15,5) DEFAULT 0,
                    std_machine_hr_rate NUMERIC(15,4) DEFAULT 0,
                    std_labour_rate     NUMERIC(15,4) DEFAULT 0,
                    std_conversion_cost NUMERIC(15,4) DEFAULT 0,
                    operation_seq       INT DEFAULT 1,
                    status              VARCHAR(50) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by          BIGINT,
                    modified_at         TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by         BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_routing_prod ON public.routing(product_code);
            ";

        case 'material_transaction':
        case 'material_transactions':
            return "
                CREATE TABLE IF NOT EXISTS public.material_transaction (
                    id                   BIGSERIAL PRIMARY KEY,
                    customer_code        VARCHAR(100),
                    company_code         VARCHAR(100),
                    division_code        VARCHAR(100),
                    location_code        VARCHAR(100),
                    doc_type             VARCHAR(100),
                    transaction_type     VARCHAR(100),
                    doc_no               VARCHAR(100),
                    doc_date             DATE,
                    party_code           VARCHAR(100),
                    party_name           VARCHAR(255),
                    item_code            VARCHAR(100),
                    item_name            VARCHAR(255),
                    uom                  VARCHAR(50),
                    qty                  NUMERIC(15,4) DEFAULT 0,
                    rejected_qty         NUMERIC(15,4) DEFAULT 0,
                    gross_weight         NUMERIC(15,4) DEFAULT 0,
                    net_weight           NUMERIC(15,4) DEFAULT 0,
                    weight_unit          VARCHAR(50),
                    actual_yield         NUMERIC(10,4) DEFAULT 0,
                    std_yield            NUMERIC(10,4) DEFAULT 0,
                    rate                 NUMERIC(15,4) DEFAULT 0,
                    amount               NUMERIC(15,4) DEFAULT 0,
                    std_rate             NUMERIC(15,4) DEFAULT 0,
                    actual_rate          NUMERIC(15,4) DEFAULT 0,
                    std_realisation_rate NUMERIC(15,4) DEFAULT 0,
                    approval_ref         VARCHAR(100),
                    remarks              TEXT,
                    cc_code              VARCHAR(100),
                    product_code         VARCHAR(100),
                    status               VARCHAR(50) DEFAULT 'Active',
                    created_at           TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by           BIGINT,
                    modified_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by          BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_mat_txn_doc ON public.material_transaction(doc_no);
                CREATE INDEX IF NOT EXISTS idx_mat_txn_item ON public.material_transaction(item_code);
                CREATE INDEX IF NOT EXISTS idx_mat_txn_type ON public.material_transaction(transaction_type);
                CREATE INDEX IF NOT EXISTS idx_mat_txn_doctype ON public.material_transaction(doc_type);
            ";

        case 'downtime':
        case 'machine_downtime':
            return "
                CREATE TABLE IF NOT EXISTS public.downtime (
                    id              BIGSERIAL PRIMARY KEY,
                    customer_code   VARCHAR(100),
                    company_code    VARCHAR(100),
                    division_code   VARCHAR(100),
                    location_code   VARCHAR(100),
                    log_date        DATE,
                    machine_code    VARCHAR(100),
                    machine_name    VARCHAR(255),
                    cc_code         VARCHAR(100),
                    rated_hrs       NUMERIC(12,4) DEFAULT 0,
                    run_hrs         NUMERIC(12,4) DEFAULT 0,
                    breakdown_hrs   NUMERIC(12,4) DEFAULT 0,
                    idle_hrs        NUMERIC(12,4) DEFAULT 0,
                    power_units_kwh NUMERIC(15,4) DEFAULT 0,
                    status          VARCHAR(50) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by      BIGINT,
                    modified_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by     BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_downtime_machine ON public.downtime(machine_code);
                CREATE INDEX IF NOT EXISTS idx_downtime_date ON public.downtime(log_date);
            ";

        case 'finance_transaction':
        case 'finance_transactions':
            return "
                CREATE TABLE IF NOT EXISTS public.finance_transaction (
                    id            BIGSERIAL PRIMARY KEY,
                    customer_code VARCHAR(100),
                    company_code  VARCHAR(100),
                    division_code VARCHAR(100),
                    location_code VARCHAR(100),
                    no            VARCHAR(100),
                    date          DATE,
                    oh_code       VARCHAR(100),
                    oh_name       VARCHAR(255),
                    oh_behaviour  VARCHAR(100),
                    cc_code       VARCHAR(100),
                    cc_name       VARCHAR(255),
                    amount        NUMERIC(15,4) DEFAULT 0,
                    remarks       TEXT,
                    status        VARCHAR(50) DEFAULT 'Active',
                    created_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by    BIGINT,
                    modified_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by   BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_fin_txn_no ON public.finance_transaction(no);
                CREATE INDEX IF NOT EXISTS idx_fin_txn_oh ON public.finance_transaction(oh_code);
            ";

        case 'trial_balance':
            return "
                CREATE TABLE IF NOT EXISTS public.trial_balance (
                    id                          BIGSERIAL PRIMARY KEY,
                    customer_code               VARCHAR(100),
                    company_code                VARCHAR(100),
                    division_code               VARCHAR(100),
                    location_code               VARCHAR(100),
                    acc_code                    VARCHAR(100) NOT NULL,
                    description                 TEXT,
                    opening_balance             NUMERIC(15,4) DEFAULT 0,
                    opening_balance_sign_d_c    VARCHAR(10),
                    opening_debit_opening_credit NUMERIC(15,4) DEFAULT 0,
                    transacted_debit            NUMERIC(15,4) DEFAULT 0,
                    transacted_credit           NUMERIC(15,4) DEFAULT 0,
                    closing_balance             NUMERIC(15,4) DEFAULT 0,
                    closing_balance_sign_d_c    VARCHAR(10),
                    closing_debit_closing_credit NUMERIC(15,4) DEFAULT 0,
                    status                      VARCHAR(50) DEFAULT 'Active',
                    created_at                  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    created_by                  BIGINT,
                    modified_at                 TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    modified_by                 BIGINT
                );
                CREATE INDEX IF NOT EXISTS idx_tb_acc ON public.trial_balance(acc_code);
            ";

        default:
            return null;
    }
}

/**
 * Dynamically ensures that the required target entity table exists in the dedicated
 * Customer Database, and adds all necessary columns.
 */
function ensureTargetTableExistsInCustomerDb(PDO $custPdo, string $tableName): bool {
    $clean = strtolower(trim($tableName));
    if (empty($clean)) {
        return false;
    }

    try {
        $chk = $custPdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = :tname 
            LIMIT 1
        ");
        $chk->execute([':tname' => $clean]);
        $tableExists = (bool)$chk->fetch();

        if (!$tableExists) {
            $ddl = getPredefinedTableSchema($clean);
            if ($ddl) {
                $custPdo->exec($ddl);
            } else {
                $custPdo->exec("
                    CREATE TABLE IF NOT EXISTS public.{$clean} (
                        id            BIGSERIAL PRIMARY KEY,
                        customer_code VARCHAR(100),
                        company_code  VARCHAR(100),
                        division_code VARCHAR(100),
                        location_code VARCHAR(100),
                        code          VARCHAR(100),
                        name          VARCHAR(255),
                        status        VARCHAR(50) DEFAULT 'Active',
                        created_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                        created_by    BIGINT,
                        modified_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                        modified_by   BIGINT
                    );
                ");
            }
        }

        // Schema evolution: Ensure organization & audit columns exist in every table
        $commonCols = [
            'customer_code VARCHAR(100)',
            'company_code VARCHAR(100)',
            'division_code VARCHAR(100)',
            'location_code VARCHAR(100)',
            'created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP',
            'created_by BIGINT',
            'modified_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP',
            'modified_by BIGINT',
            'status VARCHAR(50) DEFAULT \'Active\''
        ];

        foreach ($commonCols as $cDef) {
            try { $custPdo->exec("ALTER TABLE public.{$clean} ADD COLUMN IF NOT EXISTS {$cDef}"); } catch (Throwable $e) {}
        }

        // Specific columns evolution for key entities
        if ($clean === 'items' || $clean === 'item_master') {
            $colsToAdd = [
                'item_type VARCHAR(100)', 'item_group_code VARCHAR(100)', 'item_group_name VARCHAR(255)',
                'item_code VARCHAR(100)', 'item_name VARCHAR(255)', 'unit_of_measure VARCHAR(50)',
                'std_rate NUMERIC(15,4) DEFAULT 0', 'material_rate NUMERIC(15,4) DEFAULT 0',
                'labour_rate NUMERIC(15,4) DEFAULT 0', 'overheads NUMERIC(15,4) DEFAULT 0',
                'actual_rate NUMERIC(15,4) DEFAULT 0', 'total NUMERIC(15,4) DEFAULT 0',
                'grade VARCHAR(100)', 'net_weight_kg NUMERIC(15,4) DEFAULT 0',
                'gross_weight_kg NUMERIC(15,4) DEFAULT 0', 'upload VARCHAR(50)',
                'raw_material VARCHAR(100)', 'product VARCHAR(100)', 'std_yield_pct NUMERIC(10,4) DEFAULT 0'
            ];
            foreach ($colsToAdd as $cDef) {
                try { $custPdo->exec("ALTER TABLE public.items ADD COLUMN IF NOT EXISTS {$cDef}"); } catch (Throwable $e) {}
            }
        } elseif ($clean === 'work_centers') {
            $colsToAdd = [
                'wc_code VARCHAR(100)', 'wc_name VARCHAR(255)', 'wc_group_code VARCHAR(100)',
                'wc_group_name VARCHAR(255)', 'cc_code VARCHAR(100)', 'cc_name VARCHAR(255)',
                'std_mhr NUMERIC(15,4) DEFAULT 0', 'rated_hrs_day NUMERIC(15,4) DEFAULT 0',
                'hourly_rate NUMERIC(15,4) DEFAULT 0', 'work_center_code VARCHAR(100)', 'work_center_name VARCHAR(255)'
            ];
            foreach ($colsToAdd as $cDef) {
                try { $custPdo->exec("ALTER TABLE public.work_centers ADD COLUMN IF NOT EXISTS {$cDef}"); } catch (Throwable $e) {}
            }
            try { $custPdo->exec("ALTER TABLE public.work_centers ALTER COLUMN work_center_code DROP NOT NULL"); } catch (Throwable $e) {}
        } elseif ($clean === 'cost_center_master' || $clean === 'cost_centers') {
            $colsToAdd = [
                'cc_code VARCHAR(100)', 'cc_name VARCHAR(255)', 'cc_type VARCHAR(100)',
                'is_production INT DEFAULT 1', 'shift_capacity_hrs_per_month NUMERIC(15,4) DEFAULT 0',
                'cost_center_code VARCHAR(100)', 'cost_center_name VARCHAR(255)', 'department VARCHAR(100)'
            ];
            foreach ($colsToAdd as $cDef) {
                try { $custPdo->exec("ALTER TABLE public.cost_center_master ADD COLUMN IF NOT EXISTS {$cDef}"); } catch (Throwable $e) {}
            }
        } elseif ($clean === 'standard_cost') {
            $colsToAdd = [
                'product_code VARCHAR(100)', 'item_code VARCHAR(100)', 'std_material NUMERIC(15,4) DEFAULT 0',
                'std_conversion NUMERIC(15,4) DEFAULT 0', 'std_total_cost NUMERIC(15,4) DEFAULT 0',
                'std_revision_date DATE DEFAULT CURRENT_DATE', 'processed_material NUMERIC(15,4) DEFAULT 0',
                'processed_conversion NUMERIC(15,4) DEFAULT 0', 'processed_total_cost NUMERIC(15,4) DEFAULT 0',
                'cost_component VARCHAR(100) DEFAULT \'Total\'', 'cost_value NUMERIC(15,4) DEFAULT 0'
            ];
            foreach ($colsToAdd as $cDef) {
                try { $custPdo->exec("ALTER TABLE public.standard_cost ADD COLUMN IF NOT EXISTS {$cDef}"); } catch (Throwable $e) {}
            }
            try { $custPdo->exec("ALTER TABLE public.standard_cost ALTER COLUMN item_id DROP NOT NULL"); } catch (Throwable $e) {}
            try { $custPdo->exec("ALTER TABLE public.standard_cost ALTER COLUMN cost_component DROP NOT NULL"); } catch (Throwable $e) {}
            try { $custPdo->exec("ALTER TABLE public.standard_cost ALTER COLUMN cost_value DROP NOT NULL"); } catch (Throwable $e) {}
        } elseif ($clean === 'bom') {
            $colsToAdd = [
                'parent_item_code VARCHAR(100)', 'item_code VARCHAR(100)', 'parent_item_qty NUMERIC(15,4) DEFAULT 1',
                'child_item_qty NUMERIC(15,4) DEFAULT 1', 'quantity NUMERIC(15,4) DEFAULT 1', 'uom VARCHAR(50)',
                'material_rate NUMERIC(15,4) DEFAULT 0', 'labour_rate NUMERIC(15,4) DEFAULT 0',
                'std_rate NUMERIC(15,4) DEFAULT 0', 'processed_material_rate NUMERIC(15,4) DEFAULT 0',
                'processed_labour_rate NUMERIC(15,4) DEFAULT 0', 'processed_std_rate NUMERIC(15,4) DEFAULT 0',
                'scrap_item_code VARCHAR(100)', 'scrap_qty NUMERIC(15,4) DEFAULT 0', 'scrap_unit VARCHAR(50)',
                'byproduct_item_code VARCHAR(100)', 'byproduct_qty NUMERIC(15,4) DEFAULT 0', 'byproduct_unit VARCHAR(50)',
                'wc_code VARCHAR(100)'
            ];
            foreach ($colsToAdd as $cDef) {
                try { $custPdo->exec("ALTER TABLE public.bom ADD COLUMN IF NOT EXISTS {$cDef}"); } catch (Throwable $e) {}
            }
        } elseif ($clean === 'routing') {
            $colsToAdd = [
                'product_code VARCHAR(100)', 'item_code VARCHAR(100)', 'cc_code VARCHAR(100)',
                'machine_code VARCHAR(100)', 'std_machine_hrs NUMERIC(15,5) DEFAULT 0',
                'std_labour_hrs NUMERIC(15,5) DEFAULT 0', 'std_machine_hr_rate NUMERIC(15,4) DEFAULT 0',
                'std_labour_rate NUMERIC(15,4) DEFAULT 0', 'std_conversion_cost NUMERIC(15,4) DEFAULT 0',
                'operation_seq INT DEFAULT 1'
            ];
            foreach ($colsToAdd as $cDef) {
                try { $custPdo->exec("ALTER TABLE public.routing ADD COLUMN IF NOT EXISTS {$cDef}"); } catch (Throwable $e) {}
            }
        } elseif ($clean === 'material_transaction' || $clean === 'material_transactions') {
            $colsToAdd = [
                'doc_type VARCHAR(100)', 'transaction_type VARCHAR(100)', 'doc_no VARCHAR(100)',
                'doc_date DATE', 'party_code VARCHAR(100)', 'party_name VARCHAR(255)',
                'item_code VARCHAR(100)', 'item_name VARCHAR(255)', 'uom VARCHAR(50)',
                'qty NUMERIC(15,4) DEFAULT 0', 'rejected_qty NUMERIC(15,4) DEFAULT 0',
                'gross_weight NUMERIC(15,4) DEFAULT 0', 'net_weight NUMERIC(15,4) DEFAULT 0',
                'weight_unit VARCHAR(50)', 'actual_yield NUMERIC(10,4) DEFAULT 0',
                'std_yield NUMERIC(10,4) DEFAULT 0', 'rate NUMERIC(15,4) DEFAULT 0',
                'amount NUMERIC(15,4) DEFAULT 0', 'std_rate NUMERIC(15,4) DEFAULT 0',
                'actual_rate NUMERIC(15,4) DEFAULT 0', 'std_realisation_rate NUMERIC(15,4) DEFAULT 0',
                'approval_ref VARCHAR(100)', 'remarks TEXT', 'cc_code VARCHAR(100)',
                'product_code VARCHAR(100)'
            ];
            foreach ($colsToAdd as $cDef) {
                try { $custPdo->exec("ALTER TABLE public.material_transaction ADD COLUMN IF NOT EXISTS {$cDef}"); } catch (Throwable $e) {}
            }
            try { $custPdo->exec("CREATE INDEX IF NOT EXISTS idx_mat_txn_type ON public.material_transaction(transaction_type)"); } catch (Throwable $e) {}
            try { $custPdo->exec("CREATE INDEX IF NOT EXISTS idx_mat_txn_doctype ON public.material_transaction(doc_type)"); } catch (Throwable $e) {}
        }

        return true;
    } catch (Throwable $e) {
        error_log("Dynamic Table Creation Error for table '{$clean}': " . $e->getMessage());
        return false;
    }
}

// Dynamically discover columns and data types of target tables in customer or global schema
function getTargetTableColumns($tableName, $pdoOrCustomerId = null) {
    try {
        if ($pdoOrCustomerId instanceof PDO) {
            $pdo = $pdoOrCustomerId;
        } elseif (!empty($pdoOrCustomerId)) {
            $pdo = getCustomerDbConnection($pdoOrCustomerId);
        } elseif (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['active_customer_id'])) {
            $pdo = getCustomerDbConnection((int)$_SESSION['active_customer_id']);
        } else {
            $pdo = getDbConnection();
        }

        $stmt = $pdo->prepare("
            SELECT column_name, data_type, is_nullable 
            FROM information_schema.columns 
            WHERE table_schema = 'public' AND table_name = :tname
            ORDER BY ordinal_position
        ");
        $stmt->execute([':tname' => $tableName]);
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cols)) {
            $altTable = ($tableName === 'item_master') ? 'items' : (($tableName === 'items') ? 'item_master' : null);
            if ($altTable) {
                $stmt->execute([':tname' => $altTable]);
                $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Ingest, profile and analyze uploaded files.
 * Supports multi-sheet Excel workbooks (.xlsx), detecting each worksheet
 * as a separate data category according to EXCEL For Upload center (1).xlsx.
 */
function analyzeUploadedFiles($files, $user) {
    $userId = $user['user_id'] ?? null;
    $username = $user['email'] ?? ($user['username'] ?? 'User');

    if (empty($files) || !isset($files['name'])) {
        return ['ok' => false, 'message' => 'No files selected for upload.'];
    }

    // Backend Organizational Scope Validation
    $orgContext = getActiveOrganizationContext();
    $customerId = $orgContext['customer_id'] ?? ($_SESSION['active_customer_id'] ?? null);
    $companyId = $orgContext['company_id'] ?? ($_SESSION['active_company_id'] ?? null);
    $divisionId = $orgContext['division_id'] ?? ($_SESSION['active_division_id'] ?? null);
    $activeLoc = getActiveOperationalLocation();
    $locationType = $activeLoc['type_code'] ?? ($_SESSION['active_location_type'] ?? 'plant');
    $locationId = $activeLoc['id'] ?? ($_SESSION['active_plant_id'] ?? $_SESSION['active_warehouse_id'] ?? null);
    $periodId = $orgContext['period_id'] ?? ($_SESSION['active_period_id'] ?? null);

    if (!$customerId && $userId && function_exists('getUserCustomerTenantId')) {
        $customerId = getUserCustomerTenantId($userId);
    }

    if (!$companyId || !$locationId || !$periodId) {
        return ['ok' => false, 'message' => 'Validation Error: Active scope (Company, Location, and Period) must be selected before uploading files.'];
    }

    if ($userId && !isPlatformAdmin($userId) && !isCmaAdmin($userId)) {
        if ($customerId && function_exists('userCanAccessCustomer') && !userCanAccessCustomer($userId, $customerId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized for the selected customer tenant.'];
        }
        if ($companyId && function_exists('userCanAccessCompany') && !userCanAccessCompany($userId, $companyId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized to upload data for the selected company scope.'];
        }
        if ($locationId && function_exists('userCanAccessLocation') && !userCanAccessLocation($userId, $companyId, $divisionId, $locationType, $locationId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized to upload data for the selected location scope.'];
        }
        if ($periodId && function_exists('userCanAccessPeriod') && !userCanAccessPeriod($periodId, $userId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized to upload data for the selected period scope.'];
        }
    }

    $allowedExts = ['csv', 'xls', 'xlsx'];
    $importCode = 'IMP-' . date('ymdHis') . '-' . random_int(100, 999);

    $uploadDir = __DIR__ . '/../uploads/temp/' . date('Ymd') . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $primaryFileHash = null;
    $processedFiles = [];
    $totalRunRows = 0;
    $duplicateWarning = null;

    // First pass: save file, calculate SHA-256 hash, and check for duplicate submission
    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            continue;
        }

        $tmpPath = $files['tmp_name'][$i];
        $fileHash = hash_file('sha256', $tmpPath);
        if (!$primaryFileHash) {
            $primaryFileHash = $fileHash;
        }

        // Check recent uploads for exact same hash
        $recentUpload = dbQuery("
            SELECT import_code, created_at, status 
            FROM public.uploads 
            WHERE file_hash = ? AND created_at > now() - interval '24 hours'
            LIMIT 1
        ", [$fileHash])->fetch(PDO::FETCH_ASSOC);

        if ($recentUpload) {
            $duplicateWarning = "Notice: This exact file was previously uploaded under {$recentUpload['import_code']} at " . date('d M Y H:i', strtotime($recentUpload['created_at'])) . " (Status: {$recentUpload['status']}).";
        }
    }

    // Create main upload run record with file hash
    $uploadId = dbQuery(
        "INSERT INTO public.uploads(import_code, uploaded_by, username, status, file_hash) VALUES(?, ?, ?, 'Analyzing', ?) RETURNING id",
        [$importCode, $userId, $username, $primaryFileHash]
    )->fetchColumn();

    // Link upload run to active scope
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

    $defs = getFinalizedCategoryDefinitions();

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

        if ($ext === 'xlsx' || $ext === 'xls') {
            // Multi-sheet Excel workbook parsing
            try {
                $sheets = parseXlsxWorkbook($storagePath);
            } catch (Throwable $pe) {
                error_log("Excel parser error: " . $pe->getMessage());
                $sheets = [];
            }

            if (empty($sheets)) {
                $sheets = [[
                    'sheet_name'    => pathinfo($name, PATHINFO_FILENAME),
                    'sheet_index'   => 0,
                    'headers'       => ['customer_code', 'company_code', 'division_code', 'location_code'],
                    'rows_count'    => 0,
                    'columns_count' => 4,
                    'sample_rows'   => []
                ]];
            }

            foreach ($sheets as $sh) {
                $sheetName = $sh['sheet_name'];
                $headers = $sh['headers'];
                $rows = $sh['rows_count'];
                $cols = count($headers);
                $totalRunRows += $rows;

                $classification = classifyWorksheet($sheetName, $headers);
                $matchedCat = $classification['category'];
                $confidence = $classification['confidence'];

                // Look up master group & sub category in Global DB
                $mgId = dbQuery("SELECT id FROM public.master_groups WHERE code = ? OR name = ?", [$matchedCat['group_code'], $matchedCat['group_name']])->fetchColumn() ?: null;
                $scId = dbQuery("SELECT id FROM public.sub_categories WHERE code = ? OR name = ?", [$matchedCat['key'], $matchedCat['name']])->fetchColumn() ?: null;
                if (!$scId && $mgId) {
                    $scId = dbQuery("SELECT id FROM public.sub_categories WHERE master_group_id = ? LIMIT 1", [$mgId])->fetchColumn() ?: null;
                }
                $mvId = $scId ? dbQuery("SELECT mv.id FROM public.mapping_configurations mc JOIN public.mapping_versions mv ON mv.id = mc.active_version_id WHERE mc.sub_category_id = ?", [$scId])->fetchColumn() ?: null : null;

                // Header validation against finalized contract
                $expectedHeaders = $matchedCat['headers'];
                $missingHeaders = array_values(array_diff($expectedHeaders, $headers));
                $unexpectedHeaders = array_values(array_diff($headers, $expectedHeaders));
                $isExactMatch = empty($missingHeaders) && empty($unexpectedHeaders);

                $status = ($confidence >= 90) ? 'Ready' : 'Review';
                $valStatus = $isExactMatch ? 'Validated (Exact Contract)' : 'Ready for Mapping';

                $fileLabel = count($sheets) > 1 ? "{$name} [{$sheetName}]" : $name;

                $fileId = dbQuery("
                    INSERT INTO public.upload_files(
                        upload_id, original_name, safe_name, file_type, size_bytes, 
                        rows_count, columns_count, headers, master_group_id, sub_category_id, 
                        mapping_version_id, validation_status, status, storage_path, sheet_name, sheet_index
                    ) VALUES(?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
                ", [
                    $uploadId, $fileLabel, $safeName, $fileType, $size,
                    $rows, $cols, json_encode($headers), $mgId, $scId,
                    $mvId, $valStatus, $status, $storagePath, $sheetName, $sh['sheet_index']
                ])->fetchColumn();

                $processedFiles[] = [
                    'id'                 => $fileId,
                    'name'               => $fileLabel,
                    'original_file'      => $name,
                    'sheet_name'         => $sheetName,
                    'sheet_index'        => $sh['sheet_index'],
                    'type'               => $fileType,
                    'size'               => $size,
                    'rows'               => $rows,
                    'columns'            => $cols,
                    'headers'            => $headers,
                    'target_table'       => $matchedCat['target_table'],
                    'dataset_type'       => $matchedCat['dataset_type'] ?? 'MASTER',
                    'master_group_id'    => $mgId,
                    'sub_category_id'    => $scId,
                    'master'             => $matchedCat['group_name'],
                    'sub'                => $matchedCat['name'],
                    'confidence'         => $confidence,
                    'mapping'            => $isExactMatch ? 'Exact Template Match' : 'Auto Mapped',
                    'missing_headers'    => $missingHeaders,
                    'unexpected_headers' => $unexpectedHeaders,
                    'status'             => $status
                ];

                logUploadAudit($userId, $username, 'WORKSHEET_ANALYZED', 'Upload Centre', $fileLabel, 'Success', [
                    'sheet' => $sheetName, 'category' => $matchedCat['name'], 'rows' => $rows
                ]);
            }
        } else {
            // CSV Ingestion
            $headers = [];
            $rows = 0;
            $cols = 0;
            $handle = fopen($storagePath, 'rb');
            if ($handle !== false) {
                $rawHeaders = fgetcsv($handle);
                if ($rawHeaders !== false) {
                    $headers = array_map('trim', $rawHeaders);
                    $cols = count($headers);
                }
                while (($row = fgetcsv($handle)) !== false) {
                    if (!empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                        $rows++;
                    }
                }
                fclose($handle);
            }

            $totalRunRows += $rows;
            $classification = classifyWorksheet($name, $headers);
            $matchedCat = $classification['category'];
            $confidence = $classification['confidence'];

            $mgId = dbQuery("SELECT id FROM public.master_groups WHERE code = ? OR name = ?", [$matchedCat['group_code'], $matchedCat['group_name']])->fetchColumn() ?: null;
            $scId = dbQuery("SELECT id FROM public.sub_categories WHERE code = ? OR name = ?", [$matchedCat['key'], $matchedCat['name']])->fetchColumn() ?: null;
            $mvId = $scId ? dbQuery("SELECT mv.id FROM public.mapping_configurations mc JOIN public.mapping_versions mv ON mv.id = mc.active_version_id WHERE mc.sub_category_id = ?", [$scId])->fetchColumn() ?: null : null;

            $expectedHeaders = $matchedCat['headers'];
            $missingHeaders = array_values(array_diff($expectedHeaders, $headers));
            $unexpectedHeaders = array_values(array_diff($headers, $expectedHeaders));
            $isExactMatch = empty($missingHeaders) && empty($unexpectedHeaders);

            $status = ($confidence >= 90) ? 'Ready' : 'Review';
            $valStatus = $isExactMatch ? 'Validated (Exact Contract)' : 'Ready for Mapping';

            $fileId = dbQuery("
                INSERT INTO public.upload_files(
                    upload_id, original_name, safe_name, file_type, size_bytes, 
                    rows_count, columns_count, headers, master_group_id, sub_category_id, 
                    mapping_version_id, validation_status, status, storage_path, sheet_name, sheet_index
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
            ", [
                $uploadId, $name, $safeName, $fileType, $size,
                $rows, $cols, json_encode($headers), $mgId, $scId,
                $mvId, $valStatus, $status, $storagePath, null, 0
            ])->fetchColumn();

            $processedFiles[] = [
                'id'                 => $fileId,
                'name'               => $name,
                'original_file'      => $name,
                'sheet_name'         => pathinfo($name, PATHINFO_FILENAME),
                'sheet_index'        => 0,
                'type'               => $fileType,
                'size'               => $size,
                'rows'               => $rows,
                'columns'            => $cols,
                'headers'            => $headers,
                'target_table'       => $matchedCat['target_table'],
                'dataset_type'       => $matchedCat['dataset_type'] ?? 'MASTER',
                'master_group_id'    => $mgId,
                'sub_category_id'    => $scId,
                'master'             => $matchedCat['group_name'],
                'sub'                => $matchedCat['name'],
                'confidence'         => $confidence,
                'mapping'            => $isExactMatch ? 'Exact Template Match' : 'Auto Mapped',
                'missing_headers'    => $missingHeaders,
                'unexpected_headers' => $unexpectedHeaders,
                'status'             => $status
            ];
        }
    }

    dbQuery("UPDATE public.uploads SET status = 'Ready', total_rows = ? WHERE id = ?", [$totalRunRows, $uploadId]);
    clearUploadCentreCache();

    $masterGroupOptions = getMasterGroupsAndSubCategories();

    return [
        'ok'                => true,
        'upload_id'         => $uploadId,
        'import_code'       => $importCode,
        'duplicate_warning' => $duplicateWarning,
        'files'             => $processedFiles,
        'master_groups'     => $masterGroupOptions,
        'total_rows'        => $totalRunRows
    ];
}

// Fetch Master Groups & Sub Categories for Dropdowns
function getMasterGroupsAndSubCategories() {
    try {
        $groups = dbQuery("SELECT id, name, code, description FROM public.master_groups ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $subCats = dbQuery("
            SELECT sc.id, sc.master_group_id, sc.name, sc.code, sc.target_entity_id, te.table_name, te.entity_name
            FROM public.sub_categories sc
            LEFT JOIN public.target_entities te ON te.id = sc.target_entity_id
            ORDER BY sc.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($groups as $g) {
            $gSubs = array_values(array_filter($subCats, fn($s) => $s['master_group_id'] == $g['id']));
            $result[] = [
                'id'             => $g['id'],
                'name'           => $g['name'],
                'code'           => $g['code'],
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
        $sub = dbQuery("
            SELECT sc.*, te.table_name 
            FROM public.sub_categories sc 
            LEFT JOIN public.target_entities te ON te.id = sc.target_entity_id 
            WHERE sc.id = ?
        ", [$subCategoryId])->fetch(PDO::FETCH_ASSOC);
        
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

// Fetch File Rows Preview & Auto Column Mapping
function getFilePreviewData($fileId, ?array $user = null) {
    $file = dbQuery("SELECT * FROM public.upload_files WHERE id = ?", [$fileId])->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        return ['ok' => false, 'message' => 'Upload file record not found.'];
    }

    $ext = strtolower($file['file_type']);
    $data = [];
    $headers = json_decode($file['headers'] ?? '[]', true) ?: [];

    // Read preview rows
    if (file_exists($file['storage_path'])) {
        if ($ext === 'xlsx' || $ext === 'xls') {
            $res = parseXlsxSheetRows($file['storage_path'], $file['sheet_name'] ?: '', 25);
            $parsedHeaders = $res['headers'];
            if (!empty($parsedHeaders)) {
                $headers = $parsedHeaders;
            }
            foreach ($res['rows'] as $r) {
                if (count($r) === count($headers)) {
                    $data[] = array_combine($headers, $r);
                } else {
                    $rPad = array_pad($r, count($headers), '');
                    $data[] = array_combine($headers, array_slice($rPad, 0, count($headers)));
                }
            }
        } elseif ($ext === 'csv') {
            $handle = fopen($file['storage_path'], 'rb');
            if ($handle !== false) {
                $rawHeaders = fgetcsv($handle);
                $count = 0;
                while (($row = fgetcsv($handle)) !== false && $count < 25) {
                    if (count($row) === count($headers)) {
                        $data[] = array_combine($headers, $row);
                    } else {
                        $rPad = array_pad($row, count($headers), '');
                        $data[] = array_combine($headers, array_slice($rPad, 0, count($headers)));
                    }
                    $count++;
                }
                fclose($handle);
            }
        }
    }

    // Resolve Customer DB context securely
    $custDbContext = getUserCustomerDbContext($user);
    $custPdo = $custDbContext['pdo'];

    // Determine target table
    $targetTable = 'items';
    if (!empty($file['sub_category_id'])) {
        $tName = dbQuery("
            SELECT te.table_name 
            FROM public.sub_categories sc 
            JOIN public.target_entities te ON te.id = sc.target_entity_id 
            WHERE sc.id = ?
        ", [$file['sub_category_id']])->fetchColumn();
        if ($tName) {
            $targetTable = strtolower(trim($tName));
        }
    }

    ensureTargetTableExistsInCustomerDb($custPdo, $targetTable);
    $targetCols = getTargetTableColumns($targetTable, $custPdo);
    $targetColNames = array_column($targetCols, 'column_name');

    // Pre-calculate auto mapping
    $mapping = [];
    foreach ($headers as $h) {
        $cleanH = strtolower(trim(preg_replace('/[^a-z0-9_]/i', '', str_replace(' ', '_', $h))));
        if (in_array($cleanH, $targetColNames, true)) {
            $mapping[$h] = $cleanH;
        } else {
            // Find fuzzy match
            $matched = null;
            foreach ($targetColNames as $tc) {
                if ($cleanH === $tc || strpos($cleanH, $tc) !== false || strpos($tc, $cleanH) !== false) {
                    $matched = $tc;
                    break;
                }
            }
            $mapping[$h] = $matched ?: '__IGNORE__';
        }
    }

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
}

/**
 * Validates and executes real atomic data import into the authenticated user's
 * dedicated Customer Database (cust<ID>).
 * Strictly populates organization & audit columns server-side.
 * Enforces controlled insert/update policy by primary business keys with SAVEPOINT isolation.
 */
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
        $activeLocationCode = !empty($loc['code']) ? $loc['code'] : ($_SESSION['active_location_code'] ?? ($_SESSION['active_plant_code'] ?? ($_SESSION['active_warehouse_code'] ?? 'LOC001')));
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
                dbQuery("UPDATE public.uploads SET status = 'Failed', error_rows = 1 WHERE id = ?", [$uploadId]);
                dbQuery("
                    INSERT INTO public.import_errors(upload_id, upload_file_id, sheet_name, row_number, field, error_type, error_message, severity)
                    VALUES(?, ?, ?, 1, 'period_id', 'Period Validation Failed', ?, 'Critical')
                ", [$uploadId, $f['id'], $sheetName, $periodValidation['error']]);
                return [
                    'ok'      => false,
                    'message' => $periodValidation['error']
                ];
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

// Fetch Detailed Row-Level Errors for an Upload Run from Neon DB
function getImportErrorsByUploadId($uploadId) {
    try {
        return dbQuery("
            SELECT id, upload_id, upload_file_id, sheet_name, row_number, field, source_value, error_type, error_message, severity, created_at
            FROM public.import_errors
            WHERE upload_id = ?
            ORDER BY sheet_name ASC, row_number ASC, id ASC
            LIMIT 500
        ", [$uploadId])->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
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
                   coalesce(mv.confidence, 99.00) as confidence,
                   coalesce(mv.status, sc.status) as status,
                   sc.created_at
            FROM public.sub_categories sc
            JOIN public.master_groups mg ON mg.id = sc.master_group_id
            LEFT JOIN public.target_entities te ON te.id = sc.target_entity_id
            LEFT JOIN public.mapping_configurations mc ON mc.sub_category_id = sc.id
            LEFT JOIN public.mapping_versions mv ON mv.id = mc.active_version_id
            ORDER BY mg.id, sc.id
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
            ORDER BY mg.id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
