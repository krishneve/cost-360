<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
    echo "Central DB connection OK.\n";

    // Query databases on the PostgreSQL server if permission allows, or test connecting to cost360_customer_cust001
    $rows = $pdo->query("SELECT * FROM customer_databases")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "Customer ID: {$r['customer_id']} -> DB Name: {$r['database_name']} (Status: {$r['status']})\n";
        $dbName = $r['database_name'];
        $host = !empty($r['host']) ? $r['host'] : (getenv('DB_HOST') ?: getenv('PGHOST') ?: '100.102.117.42');
        $port = !empty($r['port']) ? $r['port'] : (getenv('DB_PORT') ?: getenv('PGPORT') ?: '5432');
        $user = !empty($r['db_user']) ? $r['db_user'] : (getenv('DB_USER') ?: getenv('PGUSER') ?: 'cost360_dev');
        $password = !empty($r['db_password']) ? $r['db_password'] : (getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: 'krish');
        
        $dsnOptions = [
            "pgsql:host={$host};port={$port};dbname={$dbName};sslmode=require;connect_timeout=5",
            "pgsql:host={$host};port={$port};dbname={$dbName};sslmode=prefer;connect_timeout=5",
            "pgsql:host={$host};port={$port};dbname={$dbName};sslmode=disable;connect_timeout=5",
            "pgsql:host={$host};port={$port};dbname={$dbName};connect_timeout=5"
        ];

        $custPdo = null;
        $lastErr = '';
        foreach ($dsnOptions as $dsn) {
            try {
                $custPdo = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
                echo "  -> SUCCESS with DSN: {$dsn}\n";
                break;
            } catch (PDOException $ex) {
                echo "  -> Failed DSN: {$dsn} | Error: " . $ex->getMessage() . "\n";
                $lastErr = $ex->getMessage();
            }
        }
        if ($custPdo) {
            echo "  -> Connected successfully to {$dbName}!\n";
        } else {
            echo "  -> Connection failed to {$dbName}: " . $lastErr . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
