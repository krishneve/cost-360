<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$defs = getFinalizedCategoryDefinitions();
echo "=== 11 CATEGORY DEFINITIONS ===\n";
foreach ($defs as $k => $d) {
    echo sprintf(
        "%-25s | Sheet: %-32s | Type: %-12s | Table: %-20s | BizKey: %s\n",
        $k,
        $d['sheet_name'],
        $d['dataset_type'] ?? '?',
        $d['target_table'],
        implode(',', $d['business_key'] ?? [])
    );
}

// Check how customer databases are resolved
$pdo = getDbConnection();
$custDbs = $pdo->query("SELECT * FROM customer_databases LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== CUSTOMER DATABASES ===\n";
print_r($custDbs);
