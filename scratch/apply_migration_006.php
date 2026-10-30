<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
    echo "Connected to database...\n";
    $sql = file_get_contents(__DIR__ . '/../database/migrations/006_cost_allocation_rules.sql');
    $pdo->exec($sql);
    echo "Migration 006_cost_allocation_rules.sql applied successfully!\n";

    // Verify table exists
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'cost_allocation_rules'")->fetch();
    echo "Table cost_allocation_rules: " . ($chk ? "EXISTS" : "MISSING") . "\n";

    // Verify columns
    $cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'cost_allocation_rules'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  - {$c['column_name']} ({$c['data_type']})\n";
    }

} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
