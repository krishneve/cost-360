<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'periods'")->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);
$constraints = $pdo->query("SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'public.periods'::regclass")->fetchAll(PDO::FETCH_ASSOC);
print_r($constraints);
