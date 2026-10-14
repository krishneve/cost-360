<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getCustomerDbConnection('cost360_customer_cust001');
$allT = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($allT as $t) {
    $cnt = $pdo->query("SELECT count(*) FROM {$t}")->fetchColumn();
    echo "{$t}: {$cnt} rows\n";
}
