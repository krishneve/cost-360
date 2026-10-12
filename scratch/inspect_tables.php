<?php
require 'config/database.php';
$pdo = getCustomerDbConnection(1);
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "=== Table: {$t} ===\n";
    $cols = $pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = '{$t}' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['column_name']} ({$c['data_type']})\n";
    }
}
