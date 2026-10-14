<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$st = dbQuery("SELECT to_regclass('public.saved_mappings')");
echo "saved_mappings: " . var_export($st->fetchColumn(), true) . "\n";

$st2 = dbQuery("SELECT column_name FROM information_schema.columns WHERE table_name = 'upload_files' AND column_name = 'column_mappings'");
echo "column_mappings in upload_files: " . var_export($st2->fetchColumn(), true) . "\n";
