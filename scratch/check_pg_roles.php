<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
echo "Current user: " . $pdo->query("SELECT current_user")->fetchColumn() . "\n";
echo "Roles in PostgreSQL:\n";
print_r($pdo->query("SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolcanlogin FROM pg_roles")->fetchAll(PDO::FETCH_ASSOC));
