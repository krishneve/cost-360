<?php
echo "=== admin/customers/api.php content ===\n";
if (file_exists('admin/customers/api.php')) {
    echo file_get_contents('admin/customers/api.php');
}

echo "\n=== config/database.php functions ===\n";
$dbPhp = file_get_contents('config/database.php');
preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\((.*?)\)/', $dbPhp, $matches, PREG_SET_ORDER);
foreach ($matches as $m) {
    echo "- " . $m[1] . "(" . $m[2] . ")\n";
}
