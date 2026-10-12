<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$te = $pdo->query("SELECT id, entity_name, table_name, schema_definition FROM public.target_entities ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
print_r($te);

$sc = $pdo->query("SELECT sc.id, sc.master_group_id, mg.group_name, sc.name, sc.code, te.table_name FROM public.sub_categories sc LEFT JOIN public.master_groups mg ON mg.id = sc.master_group_id LEFT JOIN public.target_entities te ON te.id = sc.target_entity_id ORDER BY sc.id")->fetchAll(PDO::FETCH_ASSOC);
print_r($sc);
