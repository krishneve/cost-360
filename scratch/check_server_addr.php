<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$info = $pdo->query("SELECT inet_server_addr(), inet_server_port(), current_database(), current_user")->fetch(PDO::FETCH_ASSOC);
print_r($info);
