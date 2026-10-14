<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$tables = $pdo->query("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
    ORDER BY table_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

echo "All public tables in Global DB and their row counts:\n";
echo "----------------------------------------------------\n";
foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT count(*) FROM public.{$t}")->fetchColumn();
        echo sprintf("%-30s : %d rows\n", $t, $c);
    } catch (Exception $e) {
        echo sprintf("%-30s : ERROR (%s)\n", $t, $e->getMessage());
    }
}
