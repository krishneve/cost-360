<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

// Drop the restrictive check constraint on audit_log
$pdo->exec("ALTER TABLE public.audit_log DROP CONSTRAINT IF EXISTS audit_log_action_check");
$pdo->exec("ALTER TABLE public.audit_log ALTER COLUMN action TYPE VARCHAR(60)");

echo "audit_log constraint updated successfully.\n";
