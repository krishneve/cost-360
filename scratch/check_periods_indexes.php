<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
$indexes = $pdo->query("
    SELECT indexname, indexdef 
    FROM pg_indexes 
    WHERE tablename = 'periods'
")->fetchAll(PDO::FETCH_ASSOC);
print_r($indexes);
