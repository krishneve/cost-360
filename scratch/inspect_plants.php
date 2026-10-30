<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

echo "=== CUSTOMERS ===\n";
print_r($pdo->query("SELECT customer_id, customer_code, customer_name, status FROM customers")->fetchAll(PDO::FETCH_ASSOC));

echo "=== COMPANIES ===\n";
print_r($pdo->query("SELECT company_id, customer_id, company_code, company_name, status FROM companies")->fetchAll(PDO::FETCH_ASSOC));

echo "=== PLANTS ===\n";
print_r($pdo->query("SELECT p.plant_id, p.division_id, d.company_id, c.customer_id, p.plant_code, p.plant_name, p.status 
                     FROM plants p 
                     JOIN divisions d ON p.division_id = d.division_id 
                     JOIN companies c ON d.company_id = c.company_id")->fetchAll(PDO::FETCH_ASSOC));

echo "=== TRANSACTIONS (sample) ===\n";
print_r($pdo->query("SELECT * FROM transactions LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));
