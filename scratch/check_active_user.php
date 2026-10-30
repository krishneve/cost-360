<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
$sql = "SELECT u.user_id, u.email, u.full_name, r.role_code 
        FROM users u 
        JOIN user_roles ur ON u.user_id = ur.user_id 
        JOIN roles r ON ur.role_id = r.role_id 
        WHERE u.is_active = TRUE AND ur.status = 'Active' AND r.status = 'Active' 
        LIMIT 1";
$user = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
print_r($user);

