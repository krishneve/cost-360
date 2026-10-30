<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$queries = [
    "ALTER TABLE public.quantity_reco_source_transactions ADD COLUMN IF NOT EXISTS pnl_classification VARCHAR(100) DEFAULT 'Rev from Operations'",
    "ALTER TABLE public.quantity_reco_source_transactions ADD COLUMN IF NOT EXISTS nature VARCHAR(50) DEFAULT 'Variable'",
    "ALTER TABLE public.quantity_reco_detail ADD COLUMN IF NOT EXISTS account_code VARCHAR(100)",
    "ALTER TABLE public.quantity_reco_detail ADD COLUMN IF NOT EXISTS pnl_classification VARCHAR(100) DEFAULT 'Rev from Operations'",
    "ALTER TABLE public.quantity_reco_detail ADD COLUMN IF NOT EXISTS nature VARCHAR(50) DEFAULT 'Variable'"
];

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "Executed: $q\n";
    } catch (Exception $e) {
        echo "Error on: $q -> " . $e->getMessage() . "\n";
    }
}

echo "Database columns updated successfully.\n";
