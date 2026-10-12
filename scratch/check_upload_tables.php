<?php
require 'config/database.php';
$pdo = getDbConnection();
$tables = ['uploads', 'upload_files', 'import_errors', 'upload_scopes', 'mapping_configurations', 'mapping_versions', 'master_groups', 'sub_categories', 'target_entities'];
foreach ($tables as $t) {
    echo "=== Table: {$t} ===\n";
    $cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = '{$t}' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['column_name']} ({$c['data_type']})\n";
    }
}
