<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

echo "=== TARGET ENTITIES ===\n";
$entities = $pdo->query("SELECT * FROM target_entities")->fetchAll(PDO::FETCH_ASSOC);
print_r($entities);

echo "\n=== SUB CATEGORIES ===\n";
$subCats = $pdo->query("SELECT sc.id, sc.master_group_id, sc.code, sc.name, te.table_name FROM sub_categories sc LEFT JOIN target_entities te ON te.id = sc.target_entity_id")->fetchAll(PDO::FETCH_ASSOC);
print_r($subCats);
