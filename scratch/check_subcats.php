<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$rows = $pdo->query("
    SELECT sc.id, sc.code, sc.name, te.table_name, mg.name as group_name
    FROM public.sub_categories sc
    JOIN public.target_entities te ON te.id = sc.target_entity_id
    JOIN public.master_groups mg ON mg.id = sc.master_group_id
    ORDER BY sc.id
")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
