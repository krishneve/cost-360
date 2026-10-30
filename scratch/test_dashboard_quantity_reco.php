<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
$u = $pdo->query("SELECT * FROM users WHERE user_id = 1")->fetch(PDO::FETCH_ASSOC);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role_code'] = 'PLATFORM_ADMIN';
$_SESSION['user'] = $u;
$_GET['page'] = 'quantity-reco';

ob_start();
require __DIR__ . '/../quantity-reco.php';
$html = ob_get_clean();

echo "HTML Length: " . strlen($html) . " bytes\n";
echo "Contains 'Quantity Reco': " . (strpos($html, 'Quantity Reco') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrPlantSelect': " . (strpos($html, 'id="qrPlantSelect"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrPeriodSelect': " . (strpos($html, 'id="qrPeriodSelect"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrGenerateBtn': " . (strpos($html, 'id="qrGenerateBtn"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrReconcileBtn': " . (strpos($html, 'id="qrReconcileBtn"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrSyncTbBtn': " . (strpos($html, 'id="qrSyncTbBtn"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrExceptionsBtn': " . (strpos($html, 'id="qrExceptionsBtn"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrMappingsBtn': " . (strpos($html, 'id="qrMappingsBtn"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrTraceModal': " . (strpos($html, 'id="qrTraceModal"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrExceptionsModal': " . (strpos($html, 'id="qrExceptionsModal"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrMappingsModal': " . (strpos($html, 'id="qrMappingsModal"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'qrReconcileModal': " . (strpos($html, 'id="qrReconcileModal"') !== false ? 'YES' : 'NO') . "\n";
echo "Contains 'quantity_reco.js': " . (strpos($html, 'quantity_reco.js') !== false ? 'YES' : 'NO') . "\n";
