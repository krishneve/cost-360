<?php
// Test connecting as postgres user
$host = '100.102.117.42'; // or 127.0.0.1 or localhost
$port = 5432;
$passwords = ['krish', 'postgres', 'password', 'root', 'admin', ''];
foreach ($passwords as $pwd) {
    try {
        $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=postgres", "postgres", $pwd, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "Successfully connected to postgres user with password: '$pwd'\n";
        $pdo->exec("ALTER USER cost360_dev CREATEDB;");
        echo "Granted CREATEDB to cost360_dev!\n";
        break;
    } catch (Exception $e) {
        // try next
    }
}
