<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$errors = $pdo->query("SELECT * FROM public.import_errors ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
print_r($errors);

$latestUpload = $pdo->query("SELECT * FROM public.uploads ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($latestUpload);

$latestFiles = $pdo->query("SELECT * FROM public.upload_files ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($latestFiles);
