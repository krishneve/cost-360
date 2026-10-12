<?php
require_once __DIR__ . '/../config/database.php';
$host = getenv('DB_HOST') ?: getenv('PGHOST');
$port = getenv('DB_PORT') ?: getenv('PGPORT') ?: '5432';
$user = getenv('DB_USER') ?: getenv('PGUSER');
$pass = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD');

echo "Trying connection to cust_57...\n";
$dsn = "pgsql:host={$host};port={$port};dbname=cust_57";
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "SUCCESS! Connected directly to cust_57.\n";
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in cust_57:\n";
    print_r($tables);
} catch (Throwable $e) {
    echo "Error connecting to cust_57: " . $e->getMessage() . "\n";
}
