<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();

    echo "\n=== CUSTOMERS COLUMNS ===\n";
    $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'customers'");
    foreach ($cols as $c) {
        echo $c['column_name'] . ", ";
    }
    echo "\n";

    echo "\n=== CUSTOMERS & DATABASES ===\n";
    $custs = $pdo->query("SELECT c.*, cd.database_name, cd.status as db_status 
                          FROM customers c 
                          LEFT JOIN customer_databases cd ON cd.customer_id = c.customer_id");
    foreach ($custs as $c) {
        echo "Customer ID: {$c['customer_id']} | Code: {$c['customer_code']} | Name: " . ($c['customer_name'] ?? $c['name'] ?? '') . " | DB: {$c['database_name']} | Status: {$c['db_status']}\n";
    }

    echo "\n=== MASTER GROUPS & SUB CATEGORIES ===\n";
    $mg = $pdo->query("SELECT mg.name as mg_name, sc.name as sc_name, sc.code as sc_code, te.table_name 
                       FROM master_groups mg 
                       LEFT JOIN sub_categories sc ON sc.master_group_id = mg.id 
                       LEFT JOIN target_entities te ON te.id = sc.target_entity_id 
                       ORDER BY mg.id, sc.id");
    foreach ($mg as $m) {
        echo "{$m['mg_name']} -> {$m['sc_name']} ({$m['sc_code']}) => Table: {$m['table_name']}\n";
    }

    echo "\n=== CUSTOMER DB 1 TABLES ===\n";
    try {
        $cid = 1;
        $custPdo = getCustomerDbConnection($cid);
        $cTables = $custPdo->query("SELECT table_schema, table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
        foreach ($cTables as $t) {
            echo "Customer DB {$cid}: " . $t['table_name'] . "\n";
        }
    } catch (Throwable $e) {
        echo "Cust DB Error: " . $e->getMessage() . "\n";
    }

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
