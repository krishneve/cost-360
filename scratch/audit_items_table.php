<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

echo "=== items columns in global db ===\n";
print_r($pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'items' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC));

echo "=== item_master columns in global db ===\n";
print_r($pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'item_master' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC));
