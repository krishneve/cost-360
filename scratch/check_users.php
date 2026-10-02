<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
echo "--- USERS ---\n";
$users = $pdo->query("SELECT user_id, username, email, is_active FROM users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    $rStmt = $pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.role_id WHERE ur.user_id = :uid");
    $rStmt->execute([':uid' => $u['user_id']]);
    $roles = $rStmt->fetchAll(PDO::FETCH_COLUMN);
    $isPlatAdmin = isPlatformAdmin($u['user_id']);
    $isCmaAdmin = isCmaAdmin($u['user_id']);
    echo "ID: {$u['user_id']} | User: {$u['username']} | Roles: " . implode(',', $roles) . " | PlatAdmin: " . ($isPlatAdmin?'Yes':'No') . " | CmaAdmin: " . ($isCmaAdmin?'Yes':'No') . "\n";
}

echo "\n--- USER ORGANIZATION ACCESS --- \n";
$uoa = $pdo->query("SELECT * FROM user_organization_access")->fetchAll(PDO::FETCH_ASSOC);
print_r($uoa);
