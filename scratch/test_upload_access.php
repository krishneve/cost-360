<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

session_start();
$_SESSION['user_id'] = 3;
$user = getAuthUser();
$userId = (int)$user['user_id'];

$pAdmin = isPlatformAdmin($userId);
$cAdmin = isCmaAdmin($userId);
$uCentre = userHasMenuAccess('UPLOAD_CENTRE', $userId);
$uCenter = userHasMenuAccess('UPLOAD_CENTER', $userId);
$dataUp = userHasMenuAccess('/data/upload', $userId);
$dataMgmt = userHasMenuAccess('DATA_MANAGEMENT', $userId);
$canUp = userHasPermission('can_upload', 'UPLOAD_CENTRE', $userId);
$canView = userHasPermission('can_view', 'UPLOAD_CENTRE', $userId);

echo "isPlatformAdmin: " . ($pAdmin?'YES':'NO') . "\n";
echo "isCmaAdmin: " . ($cAdmin?'YES':'NO') . "\n";
echo "UPLOAD_CENTRE: " . ($uCentre?'YES':'NO') . "\n";
echo "UPLOAD_CENTER: " . ($uCenter?'YES':'NO') . "\n";
echo "/data/upload: " . ($dataUp?'YES':'NO') . "\n";
echo "DATA_MANAGEMENT: " . ($dataMgmt?'YES':'NO') . "\n";
echo "can_upload: " . ($canUp?'YES':'NO') . "\n";
echo "can_view: " . ($canView?'YES':'NO') . "\n";

$hasUploadAccess = $pAdmin || $cAdmin || $uCentre || $canUp || $canView;
echo "Overall hasUploadAccess in api/upload_api.php: " . ($hasUploadAccess?'YES':'NO') . "\n";
