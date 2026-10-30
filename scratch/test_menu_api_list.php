<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Simulate admin session
session_start();
$_SESSION['user_id'] = 1;

$_GET['action'] = 'list';
$_GET['search'] = 'Reco';

ob_start();
require __DIR__ . '/../admin/menus/api.php';
$output = ob_get_clean();

echo "API response:\n" . substr($output, 0, 1000) . "\n";

