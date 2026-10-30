<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

echo "quantity_reco_source_transactions count: " . $pdo->query("SELECT COUNT(*) FROM quantity_reco_source_transactions")->fetchColumn() . "\n";
echo "quantity_reco_run count: " . $pdo->query("SELECT COUNT(*) FROM quantity_reco_run")->fetchColumn() . "\n";
echo "quantity_reco_detail count: " . $pdo->query("SELECT COUNT(*) FROM quantity_reco_detail")->fetchColumn() . "\n";
echo "quantity_reco_exception count: " . $pdo->query("SELECT COUNT(*) FROM quantity_reco_exception")->fetchColumn() . "\n";
echo "cost_basis_mapping count: " . $pdo->query("SELECT COUNT(*) FROM cost_basis_mapping")->fetchColumn() . "\n";
echo "cost_center_master count: " . $pdo->query("SELECT COUNT(*) FROM cost_center_master")->fetchColumn() . "\n";

$runs = $pdo->query("SELECT * FROM quantity_reco_run ORDER BY run_id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($runs);

