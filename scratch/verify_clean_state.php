<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$stats = getUploadCentreStats();
echo "Upload Centre Stats:\n";
print_r($stats);

$recent = getRecentUploads(null, 10);
echo "\nRecent Uploads Count: " . count($recent) . "\n";
print_r($recent);
