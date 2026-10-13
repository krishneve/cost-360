<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'target_entities'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$te = $pdo->query("SELECT * FROM public.target_entities ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
print_r($te);
