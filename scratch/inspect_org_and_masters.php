<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();

echo "=== COMPANIES ===\n";
$stmt = $pdo->query("SELECT company_id, customer_id, company_code, company_name, status FROM companies ORDER BY company_id LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== PLANTS ===\n";
$stmt = $pdo->query("SELECT plant_id, division_id, plant_code, plant_name, location, status FROM plants ORDER BY plant_id LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== DIVISIONS ===\n";
$stmt = $pdo->query("SELECT division_id, company_id, division_code, division_name, status FROM divisions ORDER BY division_id LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== PERIODS ===\n";
$stmt = $pdo->query("SELECT period_id, period_name, start_date, end_date, is_locked, status FROM periods ORDER BY period_id LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== GLOBAL COST_CENTER_MASTER ===\n";
$stmt = $pdo->query("SELECT * FROM cost_center_master LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// Check customer DB for cust001 or cust1
echo "=== CUSTOMER DB INSPECTION ===\n";
try {
    $custPdo = getCustomerDbConnection(1);
    echo "Connected to customer DB 1 successfully!\n";
    
    $stmt = $custPdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($custPdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'cost_center_master'")->fetch()) {
        echo "Customer DB cost_center_master cols:\n";
        $cols = $custPdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'cost_center_master'")->fetchAll(PDO::FETCH_ASSOC);
        print_r($cols);
        echo "Customer DB cost_center_master data:\n";
        print_r($custPdo->query("SELECT * FROM cost_center_master LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($custPdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'trial_balance'")->fetch()) {
        echo "Customer DB trial_balance cols:\n";
        $cols = $custPdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'trial_balance'")->fetchAll(PDO::FETCH_ASSOC);
        print_r($cols);
        echo "Customer DB trial_balance sample rows:\n";
        print_r($custPdo->query("SELECT * FROM trial_balance LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    echo "Customer DB 1 error: " . $e->getMessage() . "\n";
}

