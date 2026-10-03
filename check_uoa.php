<?php
require 'c:/xampp/htdocs/cost-360-main/config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT column_name, is_nullable FROM information_schema.columns WHERE table_name = 'user_organization_access'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
