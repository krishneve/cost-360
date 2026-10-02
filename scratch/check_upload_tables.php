<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();
$tables = ['uploads', 'upload_files', 'upload_scopes', 'import_batches'];
foreach ($tables as $t) {
    echo "--- COLUMNS IN {$t} ---\n";
    $cols = $pdo->query("
        SELECT column_name, data_type, is_nullable 
        FROM information_schema.columns 
        WHERE table_name = '{$t}' 
        ORDER BY ordinal_position
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo " - {$c['column_name']} ({$c['data_type']})\n";
    }
    echo "\n";
}
