<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT id, cost_center_code, cost_center_name FROM cost_center_master WHERE plant_id = 38 ORDER BY id');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "ID: {$r['id']} | Code: '{$r['cost_center_code']}' | Name: '{$r['cost_center_name']}'\n";
}
