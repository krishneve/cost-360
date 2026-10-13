<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$pdo = getDbConnection();
$custDbs = $pdo->query("SELECT * FROM customer_databases")->fetchAll(PDO::FETCH_ASSOC);
echo "Customer databases registered in DB:\n";
print_r($custDbs);

foreach ($custDbs as $cd) {
    try {
        $dsn = "pgsql:host={$cd['db_host']};port={$cd['db_port']};dbname={$cd['db_name']}";
        $cpdo = new PDO($dsn, $cd['db_user'], $cd['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "\nConnected to {$cd['db_name']}:\n";
        $tables = $cpdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $cnt = $cpdo->query("SELECT count(*) FROM {$t}")->fetchColumn();
            if ($cnt > 0) {
                echo "  {$t}: {$cnt} rows\n";
            }
        }
    } catch (Exception $e) {
        echo "  Failed to connect to {$cd['db_name']}: " . $e->getMessage() . "\n";
    }
}
