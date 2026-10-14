<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';
$files = dbQuery('SELECT id, upload_id, original_name, sheet_name, sub_category_id, file_type, storage_path, headers FROM upload_files ORDER BY id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($files) . "\n";
foreach ($files as $f) {
    echo "ID: {$f['id']} | Upload: {$f['upload_id']} | Name: {$f['original_name']} | Sheet: {$f['sheet_name']} | Path: {$f['storage_path']}\n";
    echo "  File exists: " . (file_exists($f['storage_path']) ? 'YES' : 'NO') . "\n";
}
