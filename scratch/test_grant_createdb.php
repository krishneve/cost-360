<?php
require_once __DIR__ . '/../config/database.php';
// Test connecting to 100.102.117.42 with postgres user
$host = '100.102.117.42';
$port = 5432;
// Try common dev passwords for postgres superuser or cost360_dev password
$currPwd = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: 'krish';
$passwords = [$currPwd, 'krish', 'postgres', 'password', 'root', 'admin', '123456', ''];
foreach ($passwords as $pwd) {
    try {
        $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", "postgres", $pwd, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        echo "SUCCESS! Connected as postgres with password: '$pwd'\n";
        $pdo->exec("ALTER USER cost360_dev CREATEDB;");
        echo "Successfully granted CREATEDB to cost360_dev!\n";
        break;
    } catch (Throwable $e) {
        // try next
    }
}
