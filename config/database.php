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

                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
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

        $host = getenv('PGHOST') ?: 'ep-super-rain-a5zoqjy7-pooler.us-east-2.aws.neon.tech';
        $port = getenv('PGPORT') ?: '5432';
        $dbname = getenv('PGDATABASE') ?: 'code-360-v1';
        $user = getenv('PGUSER') ?: 'neondb_owner';
        $password = getenv('PGPASSWORD') ?: '';
        $sslmode = getenv('PGSSLMODE') ?: 'require';

        // Extract clean endpoint ID and direct host from domain
        $rawEndpoint = explode('.', $host)[0];
        $cleanEndpointId = preg_replace('/-pooler$/', '', $rawEndpoint);
        $directHost = str_replace('-pooler.', '.', $host);

        // Neon SNI options list with direct endpoint fallback
        $dsnOptions = [
            "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=10;options='endpoint={$cleanEndpointId}'",
            "pgsql:host={$directHost};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=10",
            "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=10"
        ];

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
