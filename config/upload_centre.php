<?php
/**
 * Cost360 - Upload Centre Core Service & Helpers
 * Handles file ingestion, keyword classification, customer database resolution, column mapping,
 * data validation, batch importing into dedicated customer databases (cust<ID>), import history, and audit logging.
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


/**
 * Returns the predefined SQL CREATE TABLE DDL for a supported business table.
 * Strictly uses backend-controlled schemas matching Cost360 architecture specifications.
 */
function getPredefinedTableSchema(string $tableName): ?string {
    $clean = strtolower(trim($tableName));
    switch ($clean) {
        case 'items':
            return "
                CREATE TABLE IF NOT EXISTS public.items (
                    item_id         BIGSERIAL PRIMARY KEY,
                    item_code       VARCHAR(100) NOT NULL UNIQUE,
                    item_name       VARCHAR(255),
                    item_description TEXT,
                    item_type       VARCHAR(50) DEFAULT 'Raw Material',
                    category        VARCHAR(100),
                    uom             VARCHAR(50),
                    uom_id          BIGINT,
                    rate            NUMERIC(15,4) DEFAULT 0.0000,
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_by      BIGINT,
                    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_items_code ON public.items(item_code);
            ";

        case 'item_master':
            return "
                CREATE TABLE IF NOT EXISTS public.item_master (
                    id              BIGSERIAL PRIMARY KEY,
                    item_code       VARCHAR(100) NOT NULL UNIQUE,
                    item_description TEXT,
                    item_name       VARCHAR(255),
                    uom             VARCHAR(50),
                    rate            NUMERIC(15,4),
                    category        VARCHAR(100),
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_by      BIGINT,
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
                CREATE INDEX IF NOT EXISTS idx_item_master_code ON public.item_master(item_code);
            ";

        case 'product_master':
            return "
                CREATE TABLE IF NOT EXISTS public.product_master (
                    id              BIGSERIAL PRIMARY KEY,
                    product_code    VARCHAR(100) NOT NULL UNIQUE,
                    product_name    TEXT,
                    uom             VARCHAR(50),
                    grade           VARCHAR(50),
                    net_weight_kg   NUMERIC(12,4),
                    pour_weight_kg  NUMERIC(12,4),
                    std_yield_pct   NUMERIC(8,2),
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_by      BIGINT,
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'standard_cost':
            return "
                CREATE TABLE IF NOT EXISTS public.standard_cost (
                    id              BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT,
                    item_code       VARCHAR(100) NOT NULL,
                    cost_component  VARCHAR(50) DEFAULT 'Total',
                    cost_value      NUMERIC(15,4),
                    standard_rate   NUMERIC(15,4),
                    effective_date  DATE DEFAULT CURRENT_DATE,
                    effective_from  DATE DEFAULT CURRENT_DATE,
                    effective_to    DATE,
                    currency        VARCHAR(10) DEFAULT 'INR',
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'bom':
            return "
                CREATE TABLE IF NOT EXISTS public.bom (
                    id                  BIGSERIAL PRIMARY KEY,
                    parent_item_code    VARCHAR(100) NOT NULL,
                    component_item_code VARCHAR(100) NOT NULL,
                    quantity            NUMERIC(15,4) NOT NULL,
                    uom                 VARCHAR(50),
                    scrap_pct           NUMERIC(8,2) DEFAULT 0,
                    status              VARCHAR(20) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'bom_headers':
            return "
                CREATE TABLE IF NOT EXISTS public.bom_headers (
                    bom_id          BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT,
                    parent_item_code VARCHAR(100),
                    revision_no     VARCHAR(20) DEFAULT '1',
                    effective_from  DATE DEFAULT CURRENT_DATE,
                    effective_to    DATE,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'bom_details':
            return "
                CREATE TABLE IF NOT EXISTS public.bom_details (
                    bom_detail_id       BIGSERIAL PRIMARY KEY,
                    bom_id              BIGINT,
                    component_item_id   BIGINT,
                    component_item_code VARCHAR(100),
                    quantity            NUMERIC(14,4) NOT NULL,
                    uom                 VARCHAR(50),
                    uom_id              BIGINT,
                    status              VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'routing':
            return "
                CREATE TABLE IF NOT EXISTS public.routing (
                    id                  BIGSERIAL PRIMARY KEY,
                    item_code           VARCHAR(100) NOT NULL,
                    operation_seq       INT NOT NULL,
                    work_center_code    VARCHAR(100),
                    setup_time_mins     NUMERIC(10,2),
                    run_time_mins       NUMERIC(10,2),
                    status              VARCHAR(20) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'purchase_transaction':
            return "
                CREATE TABLE IF NOT EXISTS public.purchase_transaction (
                    id              BIGSERIAL PRIMARY KEY,
                    po_number       VARCHAR(100),
                    vendor_code     VARCHAR(100),
                    item_code       VARCHAR(100) NOT NULL,
                    qty             NUMERIC(15,4) NOT NULL,
                    unit_price      NUMERIC(15,4) NOT NULL,
                    txn_date        DATE DEFAULT CURRENT_DATE,
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'consumption_transaction':
            return "
                CREATE TABLE IF NOT EXISTS public.consumption_transaction (
                    id              BIGSERIAL PRIMARY KEY,
                    issue_number    VARCHAR(100),
                    cost_center_code VARCHAR(100),
                    item_code       VARCHAR(100) NOT NULL,
                    qty             NUMERIC(15,4) NOT NULL,
                    txn_date        DATE DEFAULT CURRENT_DATE,
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'production_transaction':
            return "
                CREATE TABLE IF NOT EXISTS public.production_transaction (
                    id              BIGSERIAL PRIMARY KEY,
                    wo_number       VARCHAR(100),
                    product_code    VARCHAR(100) NOT NULL,
                    produced_qty    NUMERIC(15,4) NOT NULL,
                    rejected_qty    NUMERIC(15,4) DEFAULT 0,
                    txn_date        DATE DEFAULT CURRENT_DATE,
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'machine_master':
        case 'work_centers':
            return "
                CREATE TABLE IF NOT EXISTS public.machine_master (
                    id                  BIGSERIAL PRIMARY KEY,
                    machine_code        VARCHAR(100) NOT NULL UNIQUE,
                    machine_name        VARCHAR(200),
                    work_center_code    VARCHAR(100),
                    hourly_rate         NUMERIC(15,4),
                    status              VARCHAR(20) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT now()
                );
                CREATE TABLE IF NOT EXISTS public.work_centers (
                    work_center_id      BIGSERIAL PRIMARY KEY,
                    work_center_code    VARCHAR(100) NOT NULL UNIQUE,
                    work_center_name    VARCHAR(200),
                    hourly_rate         NUMERIC(15,4) DEFAULT 0.00,
                    status              VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'vendor_master':
        case 'vendors':
            return "
                CREATE TABLE IF NOT EXISTS public.vendor_master (
                    id              BIGSERIAL PRIMARY KEY,
                    vendor_code     VARCHAR(100) NOT NULL UNIQUE,
                    vendor_name     VARCHAR(200) NOT NULL,
                    contact_email   VARCHAR(150),
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
                CREATE TABLE IF NOT EXISTS public.vendors (
                    vendor_id       BIGSERIAL PRIMARY KEY,
                    vendor_code     VARCHAR(100) NOT NULL UNIQUE,
                    vendor_name     VARCHAR(200) NOT NULL,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'overhead_ledger':
            return "
                CREATE TABLE IF NOT EXISTS public.overhead_ledger (
                    id              BIGSERIAL PRIMARY KEY,
                    account_code    VARCHAR(100) NOT NULL,
                    account_name    VARCHAR(200),
                    amount          NUMERIC(15,4) NOT NULL,
                    period          VARCHAR(50),
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'cost_center_master':
        case 'cost_centers':
            return "
                CREATE TABLE IF NOT EXISTS public.cost_center_master (
                    id                  BIGSERIAL PRIMARY KEY,
                    cost_center_code    VARCHAR(100) NOT NULL UNIQUE,
                    cost_center_name    VARCHAR(200) NOT NULL,
                    department          VARCHAR(100),
                    status              VARCHAR(20) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT now()
                );
                CREATE TABLE IF NOT EXISTS public.cost_centers (
                    cost_center_id      BIGSERIAL PRIMARY KEY,
                    cost_center_code    VARCHAR(100) NOT NULL UNIQUE,
                    cost_center_name    VARCHAR(200) NOT NULL,
                    status              VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'uoms':
            return "
                CREATE TABLE IF NOT EXISTS public.uoms (
                    uom_id          BIGSERIAL PRIMARY KEY,
                    uom_code        VARCHAR(50) NOT NULL UNIQUE,
                    uom_name        VARCHAR(100) NOT NULL,
                    description     VARCHAR(255),
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'period':
        case 'periods':
            return "
                CREATE TABLE IF NOT EXISTS public.period (
                    period_id       BIGSERIAL PRIMARY KEY,
                    period_name     VARCHAR(50) NOT NULL,
                    start_date      DATE NOT NULL,
                    end_date        DATE NOT NULL,
                    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
                CREATE TABLE IF NOT EXISTS public.periods (
                    period_id       BIGSERIAL PRIMARY KEY,
                    period_name     VARCHAR(50) NOT NULL,
                    start_date      DATE NOT NULL,
                    end_date        DATE NOT NULL,
                    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'transactions':
            return "
                CREATE TABLE IF NOT EXISTS public.transactions (
                    txn_id          BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT,
                    item_code       VARCHAR(100),
                    warehouse_id    BIGINT,
                    period_id       BIGINT,
                    txn_date        DATE NOT NULL DEFAULT CURRENT_DATE,
                    txn_type        VARCHAR(30) NOT NULL,
                    quantity        NUMERIC(14,4) NOT NULL,
                    value           NUMERIC(14,2) NOT NULL DEFAULT 0.00,
                    source_upload_id BIGINT,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        default:
            return null;
    }
}

/**
 * Dynamically creates ONLY the required target table inside the customer's dedicated database on-demand.
 * Does not create unnecessary tables or schemas.
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
        if ($chk->fetch()) {
            return true;
        }

        $ddl = getPredefinedTableSchema($clean);
        if ($ddl) {
            $custPdo->exec($ddl);
            return true;
        }

        // Generic fallback for any other registered target entity
        $custPdo->exec("
            CREATE TABLE IF NOT EXISTS public.{$clean} (
                id          BIGSERIAL PRIMARY KEY,
                code        VARCHAR(100),
                name        VARCHAR(255),
                description TEXT,
                status      VARCHAR(20) DEFAULT 'Active',
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");
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
            $altTable = ($tableName === 'item_master') ? 'items' : (($tableName === 'items') ? 'item_master' : (($tableName === 'period') ? 'periods' : (($tableName === 'periods') ? 'period' : null)));
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

// Ingest & Analyze Uploaded Files
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
            ['uom', 'Item Master', 'UOM Master', 98],
            ['unit of measure', 'Item Master', 'UOM Master', 98],
            ['period', 'Period Master', 'Period Master', 98],
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
function getFilePreviewData($fileId, ?array $user = null) {
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

    // Resolve Customer DB context securely
    $custDbContext = getUserCustomerDbContext($user);
    $custPdo = $custDbContext['pdo'];

    $targetTable = 'items';
    if (!empty($file['sub_category_id'])) {
        $tName = dbQuery("SELECT te.table_name FROM public.sub_categories sc JOIN public.target_entities te ON te.id = sc.target_entity_id WHERE sc.id = ?", [$file['sub_category_id']])->fetchColumn();
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

    ensureTargetTableExistsInCustomerDb($custPdo, $targetTable);
    $targetCols = getTargetTableColumns($targetTable, $custPdo);

    return [
        'ok' => true,
        'file' => $file,
        'headers' => $headers,
        'data' => $data,
        'target_table' => $targetTable,
        'database_name' => $custDbContext['database_name'],
        'customer_id' => $custDbContext['customer_id'],
        'target_columns' => array_column($targetCols, 'column_name')
    ];
}

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

        // Ensure core customer tables exist in the customer database
        if (function_exists('ensureCustomerTablesExist')) {
            ensureCustomerTablesExist($custPdo);
        }

        // Check physically if $targetTable exists in customer database
        $chkTable = $custPdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = :tname 
            LIMIT 1
        ");
        $chkTable->execute([':tname' => $targetTable]);
        if (!$chkTable->fetch()) {
            if ($targetTable === 'period') {
                $chkAlt = $custPdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'periods' LIMIT 1");
                if ($chkAlt && $chkAlt->fetch()) {
                    $targetTable = 'periods';
                }
            }
        }

        // Final physical table existence check (if missing, auto-create dynamic table)
        $chkTableFinal = $custPdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = :tname 
            LIMIT 1
        ");
        $chkTableFinal->execute([':tname' => $targetTable]);
        if (!$chkTableFinal->fetch()) {
            // Auto-create missing target table in Customer Database on-the-fly
            try {
                $custPdo->exec("
                    CREATE TABLE IF NOT EXISTS public.{$targetTable} (
                        id BIGSERIAL PRIMARY KEY,
                        code VARCHAR(100),
                        name VARCHAR(255),
                        description TEXT,
                        status VARCHAR(20) DEFAULT 'Active',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    );
                ");
            } catch (Throwable $createEx) {
                return [
                    'ok' => false,
                    'message' => "Target table '{$targetTable}' does not exist in customer database ({$custDbName}) and auto-creation failed: " . $createEx->getMessage()
                ];
            }
        }

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

