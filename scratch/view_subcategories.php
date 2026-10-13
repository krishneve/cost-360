<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$subcats = $pdo->query("SELECT sc.id, sc.master_group_id, sc.name, sc.code, sc.target_entity_id, te.entity_name, te.table_name FROM public.sub_categories sc LEFT JOIN public.target_entities te ON te.id = sc.target_entity_id ORDER BY sc.id")->fetchAll(PDO::FETCH_ASSOC);
print_r($subcats);
