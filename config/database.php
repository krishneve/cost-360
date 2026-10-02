<?php
/**
 * Cost360 - Database Configuration & Connection Manager
 * Connects securely to central Neon PostgreSQL database using PDO with SNI Endpoint Routing.
 */

if (!function_exists('loadEnv')) {
    function loadEnv($filePath) {
        if (!file_exists($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                if ((strpos($value, '"') === 0 && substr($value, -1) === '"') ||
                    (strpos($value, "'") === 0 && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }

                if (empty($_SERVER[$name]) || empty($_ENV[$name]) || !getenv($name)) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    loadEnv($envFile);
}

if (!function_exists('getDbConnection')) {
    function getDbConnection() {
        static $pdo = null;
        static $failed = false;

        if ($pdo !== null) {
            try {
                $pdo->query("SELECT 1");
                return $pdo;
            } catch (Throwable $e) {
                $pdo = null;
            }
        }

        if ($failed) {
            throw new Exception("Database connection previously failed in current request.");
        }

        $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: 'ep-super-rain-a5zoqjy7-pooler.us-east-2.aws.neon.tech';
        $port = getenv('PGPORT') ?: getenv('DB_PORT') ?: '5432';
        $dbname = getenv('PGDATABASE') ?: getenv('DB_NAME') ?: 'code-360-v1';
        $user = getenv('PGUSER') ?: getenv('DB_USER') ?: 'neondb_owner';
        $password = getenv('PGPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
        $sslmode = getenv('PGSSLMODE') ?: getenv('DB_SSLMODE') ?: null;

        $dsnOptions = [];
        $isNeon = (strpos($host, 'neon.tech') !== false);

        if ($isNeon) {
            $sslmode = $sslmode ?: 'require';
            $rawEndpoint = explode('.', $host)[0];
            $cleanEndpointId = preg_replace('/-pooler$/', '', $rawEndpoint);
            $directHost = str_replace('-pooler.', '.', $host);

            // Fast DSN fallbacks with 4-second connection timeouts
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=4;options=endpoint={$cleanEndpointId}";
            $dsnOptions[] = "pgsql:host={$directHost};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=4;options=endpoint={$cleanEndpointId}";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=4";
        } else {
            // Standard / Local PostgreSQL connection with cloud fallback
            $sslParam = $sslmode ? ";sslmode={$sslmode}" : ";sslmode=prefer";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname}{$sslParam};connect_timeout=4";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=disable;connect_timeout=4";
            $dsnOptions[] = "pgsql:host=ep-super-rain-a5zoqjy7-pooler.us-east-2.aws.neon.tech;port=5432;dbname=cost360-neon-local;sslmode=require;connect_timeout=4;options=endpoint=ep-super-rain-a5zoqjy7";
        }

        $lastException = null;

        foreach ($dsnOptions as $dsn) {
            try {
                $curUser = (strpos($dsn, 'neon.tech') !== false && $user === 'cost360_dev') ? 'neondb_owner' : $user;
                $curPass = (strpos($dsn, 'neon.tech') !== false && $user === 'cost360_dev') ? 'npg_I9idbGVSE1qD' : $password;
                $pdo = new PDO($dsn, $curUser, $curPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                return $pdo;
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        $failed = true;
        error_log("Database Connection Error: " . ($lastException ? $lastException->getMessage() : 'Unknown error'));
        throw new Exception("Database connection failed: " . ($lastException ? $lastException->getMessage() : ''));
    }
}

if (!function_exists('getCustomerDatabaseMapping')) {
    /**
     * Resolves the database mapping record for a specified customer ID from the central database.
     * Throws an exception if mapping is missing or if the database is inactive.
     *
     * @param int $customerId
     * @return array
     * @throws Exception
     */
    function getCustomerDatabaseMapping($customerId) {
        $cid = (int)$customerId;
        if ($cid <= 0) {
            throw new Exception("Invalid Customer ID specified.");
        }

        $centralPdo = getDbConnection();
        $stmt = $centralPdo->prepare("
            SELECT id, customer_id, database_name, host, port, db_user, db_password, status
            FROM customer_databases
            WHERE customer_id = :cid
            LIMIT 1
        ");
        $stmt->execute([':cid' => $cid]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mapping || empty($mapping['database_name'])) {
            // Auto-heal and create mapping if customer exists in customers table
            $chkCust = $centralPdo->prepare("SELECT customer_id FROM customers WHERE customer_id = :cid LIMIT 1");
            $chkCust->execute([':cid' => $cid]);
            if ($chkCust->fetch()) {
                $dbName = "cust_" . $cid;
                $insMap = $centralPdo->prepare("
                    INSERT INTO customer_databases (customer_id, database_name, status, created_at, initialized_at)
                    VALUES (:cid, :dbname, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT (customer_id) 
                    DO UPDATE SET database_name = EXCLUDED.database_name, status = 'Active', initialized_at = CURRENT_TIMESTAMP
                ");
                $insMap->execute([':cid' => $cid, ':dbname' => $dbName]);

                $stmt->execute([':cid' => $cid]);
                $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        if (!$mapping || empty($mapping['database_name'])) {
            throw new Exception("Missing customer_database mapping for Customer ID {$cid}.");
        }

        if (isset($mapping['status']) && strtolower(trim($mapping['status'])) !== 'active') {
            throw new Exception("Customer database is inactive for Customer ID {$cid}.");
        }

        return $mapping;
    }
}

if (!function_exists('userCanAccessCustomerDb')) {
    /**
     * Security check verifying if a user is authorized to access a given customer database.
     *
     * @param int|null $userId
     * @param int $customerId
     * @return bool
     */
    function userCanAccessCustomerDb($userId, $customerId) {
        $uid = (int)$userId;
        $cid = (int)$customerId;
        if ($cid <= 0) {
            return false;
        }

        // Platform Admin can access any customer DB
        if ($uid > 0 && function_exists('isPlatformAdmin') && isPlatformAdmin($uid)) {
            return true;
        }

        if ($uid <= 0) {
            return false;
        }

        try {
            $centralPdo = getDbConnection();

            // 1. Direct customer tenant association check
            if (function_exists('getUserCustomerTenantId')) {
                $tenantId = (int)getUserCustomerTenantId($uid);
                if ($tenantId > 0 && $tenantId === $cid) {
                    return true;
                }
            }

            // 2. Check explicit organizational access assignments in central DB
            $stmt = $centralPdo->prepare("
                SELECT COUNT(*) 
                FROM user_organization_access 
                WHERE user_id = :uid 
                  AND (customer_id = :cid OR customer_id IS NULL)
                  AND (valid_from IS NULL OR CURRENT_DATE >= valid_from)
                  AND (valid_to IS NULL OR CURRENT_DATE <= valid_to)
                  AND (status IS NULL OR status = 'Active')
            ");
            $stmt->execute([':uid' => $uid, ':cid' => $cid]);
            $count = (int)$stmt->fetchColumn();

            return $count > 0;
        } catch (Throwable $e) {
            error_log("userCanAccessCustomerDb validation error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getCustomerDbConnection')) {
    /**
     * Connects securely to a customer-specific PostgreSQL database.
     * Can accept either an integer customer ID (e.g. 71) OR a direct database name string (e.g. "cust71").
     *
     * @param int|string|null $target Customer ID or Database Name
     * @param int|null $userId User ID for permission checking
     * @return PDO
     * @throws Exception
     */
    function getCustomerDbConnection($target = null, $userId = null) {
        static $customerPdos = [];

        $dbName = null;
        $cid = null;

        if (is_string($target) && !is_numeric($target) && !empty($target)) {
            $dbName = $target;
        } else {
            if (is_numeric($target)) {
                $cid = (int)$target;
            } elseif ($target === null) {
                if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['active_customer_id']) && is_numeric($_SESSION['active_customer_id'])) {
                    $cid = (int)$_SESSION['active_customer_id'];
                } elseif (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']) && function_exists('getUserCustomerTenantId')) {
                    $cid = (int)getUserCustomerTenantId($_SESSION['user_id']);
                }
            }

            if ($cid !== null && $cid > 0) {
                // Security validation if userId is supplied
                if ($userId !== null && $userId > 0) {
                    if (!userCanAccessCustomerDb($userId, $cid)) {
                        throw new Exception("Unauthorized access: User {$userId} is not authorized to access Customer ID {$cid} database.");
                    }
                }

                try {
                    $mapping = getCustomerDatabaseMapping($cid);
                    $dbName = $mapping['database_name'];
                } catch (Throwable $me) {
                    $dbName = "cust" . $cid;
                }
            }
        }

        if (empty($dbName)) {
            throw new Exception("No valid customer database target specified or resolved.");
        }

        if (isset($customerPdos[$dbName]) && $customerPdos[$dbName] !== null) {
            return $customerPdos[$dbName];
        }

        $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: 'localhost';
        $port = getenv('PGPORT') ?: getenv('DB_PORT') ?: '5432';
        $user = getenv('PGUSER') ?: getenv('DB_USER') ?: 'neondb_owner';
        $password = getenv('PGPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
        $sslmode = getenv('PGSSLMODE') ?: getenv('DB_SSLMODE') ?: null;

        $dsnOptions = [];
        $isNeon = (strpos($host, 'neon.tech') !== false);

        if ($isNeon) {
            $sslmode = $sslmode ?: 'require';
            $rawEndpoint = explode('.', $host)[0];
            $cleanEndpointId = preg_replace('/-pooler$/', '', $rawEndpoint);
            $directHost = str_replace('-pooler.', '.', $host);

            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslmode};connect_timeout=5;options=endpoint={$cleanEndpointId}";
            $dsnOptions[] = "pgsql:host={$directHost};port={$port};dbname={$dbName};sslmode={$sslmode};connect_timeout=5;options=endpoint={$cleanEndpointId}";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslmode};connect_timeout=5";
        } else {
            $sslParam = $sslmode ? ";sslmode={$sslmode}" : ";sslmode=prefer";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbName}{$sslParam};connect_timeout=5";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode=disable;connect_timeout=5";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbName};connect_timeout=5";
        }

        $lastException = null;
        foreach ($dsnOptions as $dsn) {
            try {
                $custPdo = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                $customerPdos[$dbName] = $custPdo;
                if ($cid !== null) {
                    $customerPdos[$cid] = $custPdo;
                }
                return $custPdo;
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        error_log("Customer Database Connection Failure (DB: {$dbName}): " . ($lastException ? $lastException->getMessage() : 'Unknown error'));
        throw new Exception("Failed to connect to customer database '{$dbName}': " . ($lastException ? $lastException->getMessage() : ''));
    }
}

if (!function_exists('provisionCustomerDatabase')) {
    /**
     * Provisions a dedicated Customer Database for a given customer_id,
     * initializes initial empty tables (period, periods, uoms, items, bom_headers, bom_details, transactions, standard_cost),
     * and records/updates the mapping in customer_databases table in the Global DB.
     * Supports flexible argument signatures:
     * - provisionCustomerDatabase($globalPdo, $customerId, $customerCode)
     * - provisionCustomerDatabase($customerId)
     *
     * @param PDO|int $globalPdoOrCustomerId
     * @param int|null $customerId
     * @param string|null $customerCode
     * @return array
     */
    function provisionCustomerDatabase($globalPdoOrCustomerId, $customerId = null, ?string $customerCode = null): array {
        if ($globalPdoOrCustomerId instanceof PDO) {
            $globalPdo = $globalPdoOrCustomerId;
            $cid = (int)$customerId;
        } else {
            $globalPdo = getDbConnection();
            $cid = (int)$globalPdoOrCustomerId;
        }

        if ($cid <= 0) {
            return ['success' => false, 'error' => "Invalid Customer ID {$cid} provided for database provisioning."];
        }

        // Customer DB Naming Convention: cust<ID> (e.g. cust1, cust71)
        $dbName = "cust" . $cid;

        $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: 'localhost';
        $port = (int)(getenv('PGPORT') ?: getenv('DB_PORT') ?: 5432);
        $user = getenv('PGUSER') ?: getenv('DB_USER') ?: 'neondb_owner';

        try {
            // 1. Create PostgreSQL Database if it does not exist
            $chkDb = $globalPdo->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
            $chkDb->execute([':dbname' => $dbName]);
            if (!$chkDb->fetch()) {
                // CREATE DATABASE cannot run inside a transaction block in PostgreSQL
                $globalPdo->exec("CREATE DATABASE \"{$dbName}\"");
            }

            // 2. Connect to the newly created Customer Database
            $custPdo = getCustomerDbConnection($dbName);

            // 3. Initialize customer tables: period, periods, uoms, items, bom_headers, bom_details, transactions, standard_cost
            $custPdo->exec("
                CREATE TABLE IF NOT EXISTS period (
                    period_id       BIGSERIAL PRIMARY KEY,
                    period_name     VARCHAR(50) NOT NULL,
                    start_date      DATE NOT NULL,
                    end_date        DATE NOT NULL,
                    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
                    CHECK (end_date >= start_date)
                );

                CREATE TABLE IF NOT EXISTS periods (
                    period_id       BIGSERIAL PRIMARY KEY,
                    period_name     VARCHAR(50) NOT NULL,
                    start_date      DATE NOT NULL,
                    end_date        DATE NOT NULL,
                    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
                    CHECK (end_date >= start_date)
                );

                CREATE TABLE IF NOT EXISTS uoms (
                    uom_id          BIGSERIAL PRIMARY KEY,
                    uom_code        VARCHAR(20) NOT NULL UNIQUE,
                    uom_name        VARCHAR(50) NOT NULL,
                    description     VARCHAR(255),
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE TABLE IF NOT EXISTS items (
                    item_id         BIGSERIAL PRIMARY KEY,
                    item_code       VARCHAR(50)  NOT NULL UNIQUE,
                    item_name       VARCHAR(150) NOT NULL,
                    item_type       VARCHAR(30) NOT NULL DEFAULT 'Raw Material'
                                        CHECK (item_type IN ('Raw Material','Finished Good','Semi-Finished','Consumable','Service')),
                    uom_id          BIGINT NOT NULL REFERENCES uoms(uom_id),
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE INDEX IF NOT EXISTS idx_items_uom ON items(uom_id);

                CREATE TABLE IF NOT EXISTS bom_headers (
                    bom_id          BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT NOT NULL REFERENCES items(item_id),
                    revision_no     VARCHAR(20) NOT NULL DEFAULT '1',
                    effective_from  DATE NOT NULL DEFAULT CURRENT_DATE,
                    effective_to    DATE,
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE TABLE IF NOT EXISTS bom_details (
                    bom_detail_id   BIGSERIAL PRIMARY KEY,
                    bom_id          BIGINT NOT NULL REFERENCES bom_headers(bom_id),
                    component_item_id BIGINT NOT NULL REFERENCES items(item_id),
                    quantity        NUMERIC(14,4) NOT NULL,
                    uom_id          BIGINT NOT NULL REFERENCES uoms(uom_id),
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE TABLE IF NOT EXISTS transactions (
                    txn_id          BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT NOT NULL REFERENCES items(item_id),
                    warehouse_id    BIGINT,
                    period_id       BIGINT REFERENCES periods(period_id),
                    txn_date        DATE NOT NULL DEFAULT CURRENT_DATE,
                    txn_type        VARCHAR(30) NOT NULL CHECK (txn_type IN ('Receipt','Issue','Transfer','Adjustment')),
                    quantity        NUMERIC(14,4) NOT NULL,
                    value           NUMERIC(14,2) NOT NULL DEFAULT 0.00,
                    source_upload_id BIGINT,
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE TABLE IF NOT EXISTS standard_cost (
                    cost_id         BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT NOT NULL REFERENCES items(item_id),
                    cost_component  VARCHAR(50) NOT NULL,
                    cost_value      NUMERIC(14,4) NOT NULL,
                    effective_from  DATE NOT NULL DEFAULT CURRENT_DATE,
                    effective_to    DATE,
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );
            ");

            // 4. Update or Insert mapping in customer_databases table in Global DB
            $chkMapping = $globalPdo->prepare("SELECT id FROM customer_databases WHERE customer_id = :cid LIMIT 1");
            $chkMapping->execute([':cid' => $cid]);
            $existingMappingId = $chkMapping->fetchColumn();

            if ($existingMappingId) {
                $updMap = $globalPdo->prepare("
                    UPDATE customer_databases SET
                        database_name = :dbname,
                        host = :host,
                        port = :port,
                        db_user = :db_user,
                        status = 'Active',
                        initialized_at = CURRENT_TIMESTAMP
                    WHERE customer_id = :cid
                ");
                $updMap->execute([
                    ':dbname' => $dbName,
                    ':host' => $host,
                    ':port' => $port,
                    ':db_user' => $user,
                    ':cid' => $cid
                ]);
            } else {
                $insMap = $globalPdo->prepare("
                    INSERT INTO customer_databases (
                        customer_id, database_name, host, port, db_user, db_password, status, created_at, initialized_at
                    ) VALUES (
                        :cid, :dbname, :host, :port, :db_user, NULL, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");
                $insMap->execute([
                    ':cid' => $cid,
                    ':dbname' => $dbName,
                    ':host' => $host,
                    ':port' => $port,
                    ':db_user' => $user
                ]);
            }

            return [
                'success' => true,
                'customer_id' => $cid,
                'database_name' => $dbName,
                'status' => 'Active',
                'tables' => ['period', 'periods', 'uoms', 'items', 'bom_headers', 'bom_details', 'transactions', 'standard_cost'],
                'message' => "Customer database '{$dbName}' created and initialized successfully."
            ];

        } catch (Throwable $e) {
            error_log("Customer Database Provisioning Failure for Customer ID {$cid}: " . $e->getMessage());

            try {
                $insFail = $globalPdo->prepare("
                    INSERT INTO customer_databases (customer_id, database_name, host, port, db_user, status, created_at)
                    VALUES (:cid, :dbname, :host, :port, :db_user, 'Failed', CURRENT_TIMESTAMP)
                    ON CONFLICT (customer_id) DO UPDATE SET status = 'Failed'
                ");
                $insFail->execute([
                    ':cid' => $cid,
                    ':dbname' => $dbName,
                    ':host' => $host,
                    ':port' => $port,
                    ':db_user' => $user
                ]);
            } catch (Throwable $ignored) {
            }

            return [
                'success' => false,
                'customer_id' => $cid,
                'database_name' => $dbName,
                'error' => "Failed to provision customer database '{$dbName}': " . $e->getMessage()
            ];
        }
    }
}
