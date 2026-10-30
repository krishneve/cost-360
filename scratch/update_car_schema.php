<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$pdo->exec("ALTER TABLE public.cost_allocation_rules ALTER COLUMN target_cost_centre_id DROP NOT NULL");
echo "Altered target_cost_centre_id to DROP NOT NULL successfully!\n";
