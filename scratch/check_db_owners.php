<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$dbs = $pdo->query("SELECT d.datname, r.rolname as owner FROM pg_database d JOIN pg_roles r ON d.datdba = r.oid WHERE d.datname IN ('cost360-neon-local', 'cost360-local', 'postgres')")->fetchAll(PDO::FETCH_ASSOC);
print_r($dbs);
