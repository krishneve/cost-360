<?php
echo "=== database/schema.sql snippet for items/uoms/periods ===\n";
$schema = file_get_contents('database/schema.sql');
foreach (['CREATE TABLE.*periods', 'CREATE TABLE.*uoms', 'CREATE TABLE.*items', 'CREATE TABLE.*item_master'] as $pattern) {
    if (preg_match('/(' . $pattern . '[\s\S]*?\);)/i', $schema, $matches)) {
        echo "\n" . $matches[1] . "\n";
    }
}

echo "\n=== database/sample_customer.sql snippet ===\n";
if (file_exists('database/sample_customer.sql')) {
    $custSql = file_get_contents('database/sample_customer.sql');
    foreach (['CREATE TABLE.*periods', 'CREATE TABLE.*uoms', 'CREATE TABLE.*items', 'CREATE TABLE.*item_master'] as $pattern) {
        if (preg_match('/(' . $pattern . '[\s\S]*?\);)/i', $custSql, $matches)) {
            echo "\n" . $matches[1] . "\n";
        }
    }
}
