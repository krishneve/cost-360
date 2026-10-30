<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$users = $pdo->query("SELECT u.user_id, u.email, u.full_name, r.role_code, r.role_name 
                      FROM users u 
                      LEFT JOIN user_roles ur ON u.user_id = ur.user_id 
                      LEFT JOIN roles r ON ur.role_id = r.role_id 
                      LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "ID: {$u['user_id']} | Email: {$u['email']} | Role: {$u['role_code']} ({$u['role_name']})\n";
}
