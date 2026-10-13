<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$st = dbQuery("SELECT column_name, character_maximum_length FROM information_schema.columns WHERE table_name = 'upload_files' AND column_name = 'validation_status'");
$r = $st->fetch(PDO::FETCH_ASSOC);
echo "upload_files.validation_status max length: " . var_export($r['character_maximum_length'], true) . "\n";

// Widen validation_status to VARCHAR(100)
dbQuery("ALTER TABLE public.upload_files ALTER COLUMN validation_status TYPE VARCHAR(100)");
echo "Altered upload_files.validation_status to VARCHAR(100)\n";
