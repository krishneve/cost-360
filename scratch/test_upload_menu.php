<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
$m = $pdo->query("SELECT * FROM menus WHERE menu_name LIKE '%Upload%' OR menu_code LIKE '%UPLOAD%'")->fetchAll(PDO::FETCH_ASSOC);
echo "--- Upload Menus in DB ---\n";
print_r($m);

echo "\n--- Access Tests for Users ---\n";
$users = [1, 2, 3, 4, 5, 6, 7, 8, 70, 140, 141, 142];
foreach ($users as $uid) {
    $uStmt = $pdo->prepare("SELECT username FROM users WHERE user_id = :uid");
    $uStmt->execute([':uid' => $uid]);
    $uname = $uStmt->fetchColumn() ?: 'Unknown';
    $hasCentre = userHasMenuAccess('UPLOAD_CENTRE', $uid) ? 'YES' : 'NO';
    $hasCenter = userHasMenuAccess('UPLOAD_CENTER', $uid) ? 'YES' : 'NO';
    $hasUpload = userHasMenuAccess('upload', $uid) ? 'YES' : 'NO';
    echo "User {$uid} ({$uname}): UPLOAD_CENTRE={$hasCentre} | UPLOAD_CENTER={$hasCenter} | upload={$hasUpload}\n";
}
