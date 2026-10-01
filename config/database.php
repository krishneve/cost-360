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
            return $pdo;
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
            // Standard / Local PostgreSQL connection
            $sslParam = $sslmode ? ";sslmode={$sslmode}" : ";sslmode=prefer";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname}{$sslParam};connect_timeout=5";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=disable;connect_timeout=5";
        }

        $lastException = null;

        foreach ($dsnOptions as $dsn) {
            try {
                $pdo = new PDO($dsn, $user, $password, [
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

/* =====================================================================
   CUSTOMER DATABASE REGISTRY & CONNECTION SERVICES
   ===================================================================== */

if (!function_exists('initCustomerDatabaseRegistry')) {
    /**
     * Ensures the central customer_databases table exists in the Global DB.
     */
    function initCustomerDatabaseRegistry(PDO $pdo) {
        static $initialized = false;
        if ($initialized) return;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS customer_databases (
                    id BIGSERIAL PRIMARY KEY,
                    customer_id BIGINT NOT NULL UNIQUE,
                    database_name VARCHAR(100) NOT NULL UNIQUE,
                    host VARCHAR(150),
                    port INT DEFAULT 5432,
                    status VARCHAR(30) NOT NULL DEFAULT 'Active',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    initialized_at TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_cust_db_cust_id ON customer_databases(customer_id);
            ");
            $initialized = true;
        } catch (Throwable $e) {
            error_log("initCustomerDatabaseRegistry warning: " . $e->getMessage());
        }
    }
}

if (!function_exists('getCustomerDbName')) {
    /**
     * Resolves or generates the safe database name for a customer.
     */
    function getCustomerDbName($customerId, PDO $pdo = null) {
        $cid = (int)$customerId;
        if ($cid <= 0) return null;

        if ($pdo === null) {
            $pdo = getDbConnection();
        }

        initCustomerDatabaseRegistry($pdo);

        // Check if database name is already recorded in registry
        try {
            $stmt = $pdo->prepare("SELECT database_name FROM customer_databases WHERE customer_id = :cid LIMIT 1");
            $stmt->execute([':cid' => $cid]);
            $existing = $stmt->fetchColumn();
            if ($existing) return trim($existing);
        } catch (Throwable $ignore) {}

        // Look up customer code from customers table to build standard safe name
        $code = null;
        try {
            $stmt = $pdo->prepare("SELECT customer_code FROM customers WHERE customer_id = :cid LIMIT 1");
            $stmt->execute([':cid' => $cid]);
            $code = $stmt->fetchColumn();
        } catch (Throwable $ignore) {}

        $safeSuffix = $code ? strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($code))) : (string)$cid;
        $dbName = "cost360_customer_" . trim($safeSuffix, '_');

        // Register in customer_databases
        try {
            $ins = $pdo->prepare("
                INSERT INTO customer_databases (customer_id, database_name, status)
                VALUES (:cid, :dbname, 'Active')
                ON CONFLICT (customer_id) DO UPDATE SET database_name = EXCLUDED.database_name
            ");
            $ins->execute([':cid' => $cid, ':dbname' => $dbName]);
        } catch (Throwable $e) {
            error_log("Failed to register customer database name: " . $e->getMessage());
        }

        return $dbName;
    }
}

if (!function_exists('getCustomerDbConnection')) {
    /**
     * Returns a cached PDO connection to the customer's dedicated PostgreSQL database.
     */
    function getCustomerDbConnection($customerId) {
        static $customerPdos = [];

        $cid = (int)$customerId;
        if ($cid <= 0) {
            throw new Exception("Invalid Customer ID specified for customer database connection.");
        }

        if (isset($customerPdos[$cid])) {
            return $customerPdos[$cid];
        }

        $globalPdo = getDbConnection();
        $dbName = getCustomerDbName($cid, $globalPdo);

        $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('PGPORT') ?: getenv('DB_PORT') ?: '5432';
        $user = getenv('PGUSER') ?: getenv('DB_USER') ?: 'postgres';
        $password = getenv('PGPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
        $sslmode = getenv('PGSSLMODE') ?: getenv('DB_SSLMODE') ?: null;

        $isNeon = (strpos($host, 'neon.tech') !== false);
        $dsnOptions = [];

        if ($isNeon) {
            $sslmode = $sslmode ?: 'require';
            $rawEndpoint = explode('.', $host)[0];
            $cleanEndpointId = preg_replace('/-pooler$/', '', $rawEndpoint);
            $directHost = str_replace('-pooler.', '.', $host);

            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslmode};connect_timeout=5;options=endpoint={$cleanEndpointId}";
            $dsnOptions[] = "pgsql:host={$directHost};port={$port};dbname={$dbName};sslmode={$sslmode};connect_timeout=5;options=endpoint={$cleanEndpointId}";
        } else {
            $sslParam = $sslmode ? ";sslmode={$sslmode}" : ";sslmode=prefer";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbName}{$sslParam};connect_timeout=5";
            $dsnOptions[] = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode=disable;connect_timeout=5";
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

        // If direct customer database connection failed (e.g. pg_hba.conf restriction or single-DB plan),
        // gracefully fall back to the central database connection so the system remains functional
        error_log("Customer DB Connection to '{$dbName}' failed: " . ($lastException ? $lastException->getMessage() : 'unknown') . ". Falling back to central DB connection.");
        $customerPdos[$cid] = $globalPdo;
        return $globalPdo;
    }
}

if (!function_exists('provisionCustomerDatabase')) {
    /**
     * Safely provisions and initializes the dedicated customer database schema.
     * Idempotent: safe to run multiple times without duplicating or dropping data.
     */
    function provisionCustomerDatabase($customerId) {
        $cid = (int)$customerId;
        if ($cid <= 0) return ['ok' => false, 'message' => 'Invalid Customer ID.'];

        $globalPdo = getDbConnection();
        $dbName = getCustomerDbName($cid, $globalPdo);

        // Check if DB exists on the server
        $dbExists = false;
        try {
            $stmt = $globalPdo->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
            $stmt->execute([':dbname' => $dbName]);
            $dbExists = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Some cloud environments restrict pg_database queries
            $dbExists = false;
        }

        if (!$dbExists) {
            try {
                // Attempt to create database (PostgreSQL CREATE DATABASE cannot run inside a transaction)
                $globalPdo->exec("CREATE DATABASE \"{$dbName}\"");
                $dbExists = true;
            } catch (Throwable $e) {
                error_log("CREATE DATABASE \"{$dbName}\" info/warning: " . $e->getMessage());
            }
        }

        // Connect to customer database (or fallback) to initialize customer schema
        try {
            $custPdo = getCustomerDbConnection($cid);

            // Execute template schema statement by statement (idempotent)
            $templateFile = __DIR__ . '/../database/customer_schema_template.sql';
            if (file_exists($templateFile)) {
                $sql = file_get_contents($templateFile);
                if (!empty($sql)) {
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($statements as $st) {
                        if (empty($st)) continue;
                        try {
                            $custPdo->exec($st);
                        } catch (Throwable $se) {
                            $msg = $se->getMessage();
                            // If table/index already exists or belongs to another user, skip safely
                            if (strpos($msg, 'must be owner of table') === false && 
                                strpos($msg, 'already exists') === false) {
                                error_log("Customer schema DDL notice: " . $msg);
                            }
                        }
                    }
                }
            }

            // Mark initialized in registry
            try {
                $upd = $globalPdo->prepare("
                    UPDATE customer_databases 
                    SET initialized_at = CURRENT_TIMESTAMP, status = 'Active' 
                    WHERE customer_id = :cid
                ");
                $upd->execute([':cid' => $cid]);
            } catch (Throwable $ignore) {}

            return [
                'ok' => true,
                'customer_id' => $cid,
                'database_name' => $dbName,
                'initialized' => true
            ];
        } catch (Throwable $e) {
            error_log("Failed to initialize customer schema for {$dbName}: " . $e->getMessage());
            return [
                'ok' => false,
                'customer_id' => $cid,
                'database_name' => $dbName,
                'error' => $e->getMessage()
            ];
        }
    }
}

