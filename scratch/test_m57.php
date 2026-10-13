<?php
require_once __DIR__ . '/../config/database.php';
$m = getCustomerDatabaseMapping(57);
echo "Mapping for Customer 57:\n";
print_r($m);
