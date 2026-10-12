<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$roles = $pdo->query("SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolcanlogin, rolconnlimit FROM pg_roles WHERE rolcanlogin = true")->fetchAll(PDO::FETCH_ASSOC);
print_r($roles);
