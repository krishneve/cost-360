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
