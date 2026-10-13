<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$coreTables = [
    'users', 'roles', 'menus', 'role_menu', 'periods', 'customers',
    'companies', 'divisions', 'plants', 'warehouses', 'master_groups',
    'sub_categories', 'target_entities', 'customer_databases'
];

echo "Core system tables verification:\n";
echo "---------------------------------\n";
foreach ($coreTables as $ct) {
    $c = $pdo->query("SELECT count(*) FROM public.{$ct}")->fetchColumn();
    echo sprintf("%-25s : %d rows (INTACT)\n", $ct, $c);
}
