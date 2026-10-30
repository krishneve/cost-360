<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$stmt = $pdo->prepare("SELECT * FROM quantity_reco_exception WHERE run_id = 365");
$stmt->execute();
$exceptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Count: " . count($exceptions) . "\n";
foreach ($exceptions as $e) {
    echo "ID: {$e['exception_id']} | Ref: {$e['source_reference']} | Desc: {$e['source_description']} | Basis: {$e['cost_basis']} | Amount: {$e['amount']} | Reason: {$e['reason']}\n";
}

