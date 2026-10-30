<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
    echo "Connected to database...\n";
    $sql = file_get_contents(__DIR__ . '/../database/migrations/005_quantity_reco_schema.sql');
    $pdo->exec($sql);
    echo "Migration 005_quantity_reco_schema.sql applied successfully!\n";

    // Verify tables exist
    $tables = [
        'cost_basis_mapping',
        'quantity_reco_source_transactions',
        'quantity_reco_run',
        'quantity_reco_detail',
        'quantity_reco_exception'
    ];

    foreach ($tables as $t) {
        $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = '$t'")->fetch();
        echo "Table $t: " . ($chk ? "EXISTS" : "MISSING") . "\n";
    }

    // Verify columns on cost_center_master
    $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'cost_center_master'")->fetchAll(PDO::FETCH_COLUMN);
    echo "cost_center_master columns: " . implode(', ', $cols) . "\n";

} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
