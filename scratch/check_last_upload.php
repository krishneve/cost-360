<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$row = $pdo->query("SELECT * FROM uploads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
print_r($row);
