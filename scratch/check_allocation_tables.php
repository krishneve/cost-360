<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

echo "=== CHECKING GLOBAL DB TABLES FOR ALLOCATION / OVERHEAD / RATIO ===\n";
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$matched = [];
foreach ($tables as $t) {
    if (preg_match('/alloc|ratio|driver|overhead|basis|distrib|split/i', $t)) {
        $matched[] = $t;
    }
}
print_r($matched);

foreach ($matched as $t) {
    echo "--- Cols of {$t} ---\n";
    $cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = '{$t}'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['column_name']} ({$c['data_type']})\n";
    }
}

echo "=== CHECKING CUSTOMER DB (cust001) TABLES ===\n";
try {
    $cpdo = getCustomerDbConnection('cost360_customer_cust001');
    $ctables = $cpdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    $cmatched = [];
    foreach ($ctables as $t) {
        if (preg_match('/alloc|ratio|driver|overhead|basis|distrib|split/i', $t)) {
            $cmatched[] = $t;
        }
    }
    print_r($cmatched);
    foreach ($cmatched as $t) {
        echo "--- Cust Cols of {$t} ---\n";
        $cols = $cpdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = '{$t}'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  {$c['column_name']} ({$c['data_type']})\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

