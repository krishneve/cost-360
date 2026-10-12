<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'public.audit_log'::regclass");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
