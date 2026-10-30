<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// Set valid user session
$pdo = getDbConnection();
$u = $pdo->query("SELECT user_id, email, full_name, category_id FROM users WHERE is_active = TRUE LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = $u['user_id'];
$_SESSION['role_code'] = 'PLAT_ADMIN';
$_SESSION['user'] = $u;

$_GET = ['action' => 'get_cost_centres', 'plant_id' => 35];
$_REQUEST = $_GET;

ob_start();
require __DIR__ . '/../api/quantity_reco_api.php';
$out = ob_get_clean();
echo "RAW OUTPUT:\n" . $out . "\n";
