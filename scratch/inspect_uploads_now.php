<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$up = $pdo->query("SELECT id, import_code, status, created_at, uploaded_by FROM uploads")->fetchAll(PDO::FETCH_ASSOC);
echo "=== Uploads table ===\n";
print_r($up);

$uf = $pdo->query("SELECT id, upload_id, original_name, status, created_at FROM upload_files")->fetchAll(PDO::FETCH_ASSOC);
echo "=== Upload_files table ===\n";
print_r($uf);
