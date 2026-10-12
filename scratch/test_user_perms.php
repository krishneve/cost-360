<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = getDbConnection();
$perms = getUserAuthorizedPermissions(4); // Customer Admin (rohan)
print_r($perms);
