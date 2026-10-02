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

        if ($pdo !== null) {
            try {
                $pdo->query("SELECT 1");
                return $pdo;
            } catch (Throwable $e) {
                $pdo = null;
            }
        }

        $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: 'ep-super-rain-a5zoqjy7-pooler.us-east-2.aws.neon.tech';
        $port = getenv('PGPORT') ?: getenv('DB_PORT') ?: '5432';
        $dbname = getenv('PGDATABASE') ?: getenv('DB_NAME') ?: 'code-360-v1';
        $user = getenv('PGUSER') ?: getenv('DB_USER') ?: 'neondb_owner';
        $password = getenv('PGPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
        $sslmode = getenv('PGSSLMODE') ?: getenv('DB_SSLMODE') ?: null;

        $dsnOptions = [];

        // Detect if host is a Neon cloud database
        $isNeon = (strpos($host, 'neon.tech') !== false);

        if ($isNeon) {
            $sslmode = $sslmode ?: 'require';
            $rawEndpoint = explode('.', $host)[0];
            $cleanEndpointId = preg_replace('/-pooler$/', '', $rawEndpoint);
            $directHost = str_replace('-pooler.', '.', $host);

            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=5;options=endpoint={$cleanEndpointId}";
            $dsnOptions[] = "pgsql:host={$directHost};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=5;options=endpoint={$cleanEndpointId}";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=5";

            // Dynamic IPv6 DNS resolution fallback
            $ipList = @dns_get_record($host, DNS_AAAA);
            if (!empty($ipList)) {
                foreach ($ipList as $ipRec) {
                    if (!empty($ipRec['ipv6'])) {
                        $dsnOptions[] = "pgsql:host={$ipRec['ipv6']};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=3;options=endpoint={$cleanEndpointId}";
                    }
                }
            }

            // Known static IPv6 endpoints fallback
            $knownIpv6List = [
                '2600:1f16:12b2:b40a:533d:22ad:38c2:f393',
                '2600:1f16:12b2:b424:fdaf:6c84:8d6e:429f',
                '2600:1f16:12b2:b416:3645:2f59:5554:9b89'
            ];
            foreach ($knownIpv6List as $ipv6) {
                $dsnOptions[] = "pgsql:host={$ipv6};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=3;options=endpoint={$cleanEndpointId}";
            }
        } else {
            // Standard / Local PostgreSQL connection with cloud fallback
            $sslParam = $sslmode ? ";sslmode={$sslmode}" : ";sslmode=prefer";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname}{$sslParam};connect_timeout=3";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=disable;connect_timeout=3";
            $dsnOptions[] = "pgsql:host=ep-super-rain-a5zoqjy7-pooler.us-east-2.aws.neon.tech;port=5432;dbname=cost360-neon-local;sslmode=require;connect_timeout=3;options=endpoint=ep-super-rain-a5zoqjy7";
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
                ]);
                return $pdo;
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

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
     * Enforces user authorization, resolves database name from Central DB customer_databases table,
     * and shields credentials from frontend exposure.
     *
     * @param int|null $customerId
     * @param int|null $userId
     * @return PDO
     * @throws Exception
     */
    function getCustomerDbConnection($customerId = null, $userId = null) {
        static $customerPdos = [];

        if ($userId === null && session_status() === PHP_SESSION_ACTIVE) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if ($customerId === null) {
            if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['active_customer_id'])) {
                $customerId = (int)$_SESSION['active_customer_id'];
            } elseif ($userId && function_exists('getUserCustomerTenantId')) {
                $customerId = (int)getUserCustomerTenantId($userId);
            }
        }

        $cid = (int)$customerId;
        if ($cid <= 0) {
            throw new Exception("No active customer context available for database resolution.");
        }

        // Enforce backend user authorization
        if ($userId !== null && $userId > 0) {
            if (!userCanAccessCustomerDb($userId, $cid)) {
                throw new Exception("Unauthorized access: User {$userId} is not authorized to access Customer ID {$cid} database.");
            }
        }

        // Cache connection per customer ID within request lifecycle
        if (isset($customerPdos[$cid]) && $customerPdos[$cid] !== null) {
            return $customerPdos[$cid];
        }

        // 1. Resolve mapping from Central DB
        $mapping = getCustomerDatabaseMapping($cid);
        $dbName = $mapping['database_name'];

        // 2. Resolve credentials (with fallback to central env vars)
        $host = !empty($mapping['host']) ? $mapping['host'] : (getenv('DB_HOST') ?: getenv('PGHOST') ?: '100.102.117.42');
        $port = !empty($mapping['port']) ? $mapping['port'] : (getenv('DB_PORT') ?: getenv('PGPORT') ?: '5432');
        $user = !empty($mapping['db_user']) ? $mapping['db_user'] : (getenv('DB_USER') ?: getenv('PGUSER') ?: 'cost360_dev');
        $password = !empty($mapping['db_password']) ? $mapping['db_password'] : (getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: 'krish');
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
                $pdo = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $customerPdos[$cid] = $pdo;
                return $pdo;
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        error_log("Customer Database Connection Failure (Customer ID {$cid}, DB: {$dbName}): " . ($lastException ? $lastException->getMessage() : 'Unknown error'));
        throw new Exception("Database connection failure for Customer ID {$cid} ({$dbName}): " . ($lastException ? $lastException->getMessage() : 'Connection failed'));
    }
}



if (!function_exists('provisionCustomerDatabase')) {
    /**
     * Provisions a dedicated PostgreSQL database for a customer (cust_<customer_id>).
     * Creates the database if missing, initializes empty periods/uoms/items schemas,
     * and updates the customer_databases mapping in the Global DB.
     * Idempotent and safe to run multiple times.
     *
     * @param int $customerId
     * @return array
     * @throws Exception
     */
    function provisionCustomerDatabase($customerId) {
        $cid = (int)$customerId;
        if ($cid <= 0) {
            throw new Exception("Invalid Customer ID {$customerId} provided for database provisioning.");
        }

        $centralPdo = getDbConnection();
        $dbName = "cust_" . $cid;

        // 1. Check if the database already exists on the PostgreSQL server
        $stmt = $centralPdo->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
        $stmt->execute([':dbname' => $dbName]);
        $dbExists = (bool)$stmt->fetchColumn();

        if (!$dbExists) {
            try {
                // CREATE DATABASE cannot run in a multi-statement transaction block in PostgreSQL
                $centralPdo->exec("CREATE DATABASE \"{$dbName}\"");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false) {
                    if (strpos($e->getMessage(), 'permission denied') !== false || strpos($e->getMessage(), 'Insufficient privilege') !== false) {
                        throw new Exception("Customer database provisioning failed for '{$dbName}': PostgreSQL user requires CREATEDB privilege on server. (Error: " . $e->getMessage() . ")");
                    }
                    throw new Exception("Failed to create database '{$dbName}': " . $e->getMessage());
                }
            }
        }

        // 2. Insert or update the mapping record in Central DB customer_databases table
        $mappingStmt = $centralPdo->prepare("
            INSERT INTO public.customer_databases (customer_id, database_name, status, created_at, initialized_at)
            VALUES (:cid, :dbname, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (customer_id) 
            DO UPDATE SET 
                database_name = EXCLUDED.database_name, 
                status = 'Active', 
                initialized_at = CURRENT_TIMESTAMP
        ");
        $mappingStmt->execute([':cid' => $cid, ':dbname' => $dbName]);

        // 3. Connect to the customer's dedicated database
        $custPdo = getCustomerDbConnection($cid);

        // 4. Initialize customer schema: empty periods, uoms, items, bom, transactions, standard_cost
        $customerSchemaSql = "
        CREATE TABLE IF NOT EXISTS uoms (
            uom_id          BIGSERIAL PRIMARY KEY,
            uom_code        VARCHAR(20) NOT NULL UNIQUE,
            uom_name        VARCHAR(50) NOT NULL,
            description     VARCHAR(255),
            status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
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

        CREATE TABLE IF NOT EXISTS items (
            item_id         BIGSERIAL PRIMARY KEY,
            item_code       VARCHAR(50)  NOT NULL UNIQUE,
            item_name       VARCHAR(150) NOT NULL,
            item_type       VARCHAR(30) NOT NULL DEFAULT 'Raw Material'
                                CHECK (item_type IN ('Raw Material','Finished Good','Semi-Finished','Consumable','Service')),
            uom_id          BIGINT NOT NULL REFERENCES uoms(uom_id),
            status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
        );

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
        ";

        $custPdo->exec($customerSchemaSql);

        return [
            'success'       => true,
            'customer_id'   => $cid,
            'database_name' => $dbName,
            'status'        => 'Active',
            'tables'        => ['uoms', 'periods', 'items', 'bom_headers', 'bom_details', 'transactions', 'standard_cost']
        ];
    }
}
