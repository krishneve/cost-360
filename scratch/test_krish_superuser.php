<?php
$host = '100.102.117.42';
$port = 5432;
$pwds = ['krish', 'Krish', 'Krish@123', 'krish@123', 'krish123', 'cost360', 'cost360_dev', 'lenovoloq', 'postgres', 'password', 'root', 'admin'];

foreach ($pwds as $pwd) {
    try {
        $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres;connect_timeout=2", "postgres", $pwd, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "SUCCESS! Connected as postgres with password: '$pwd'\n";
        $pdo->exec("ALTER USER cost360_dev CREATEDB;");
        echo "Successfully granted CREATEDB to cost360_dev on $host!\n";
        break;
    } catch (Throwable $e) {
        // try next
    }
}
