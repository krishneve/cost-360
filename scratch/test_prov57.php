<?php
require_once __DIR__ . '/../config/database.php';
try {
    $res = provisionCustomerDatabase(57);
    print_r($res);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
