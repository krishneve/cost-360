<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

session_start();
$_SESSION['user_id'] = 3;
$user = getAuthUser();

$files = dbQuery("SELECT id, original_name, sheet_name FROM public.upload_files ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "Files from DB:\n";
print_r($files);
