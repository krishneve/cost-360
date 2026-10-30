<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/quantity_reco.php';

$pdo = getDbConnection();
$cols = $pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'cost_allocation_rules'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['column_name']} ({$c['data_type']}) - Nullable: {$c['is_nullable']}\n";
}

