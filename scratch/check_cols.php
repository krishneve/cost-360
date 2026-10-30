<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$cols1 = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'quantity_reco_source_transactions'")->fetchAll(PDO::FETCH_ASSOC);
echo "quantity_reco_source_transactions columns:\n";
foreach ($cols1 as $c) {
    echo "  - {$c['column_name']} ({$c['data_type']})\n";
}

$cols2 = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'quantity_reco_detail'")->fetchAll(PDO::FETCH_ASSOC);
echo "quantity_reco_detail columns:\n";
foreach ($cols2 as $c) {
    echo "  - {$c['column_name']} ({$c['data_type']})\n";
}
