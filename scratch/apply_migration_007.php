<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$sql = file_get_contents(__DIR__ . '/../database/migrations/007_cost_reco_menu_registration.sql');
$pdo->exec($sql);
echo "Migration 007 applied successfully!\n";

