<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$u = getenv('DB_USER') ?: getenv('PGUSER');
$h = getenv('DB_HOST') ?: getenv('PGHOST');
echo "Resolved env host: $h | user: $u\n";
