<?php
foreach (['config/database.php', 'config/upload_centre.php'] as $file) {
    $lines = file(__DIR__ . '/../' . $file);
    echo "=== $file ===\n";
    foreach ($lines as $num => $line) {
        if (preg_match('/(std_rev|effective_date|effective_from|revision|standard_cost|routing|bom)/i', $line)) {
            if (preg_match('/(revision|effective|std_rev)/i', $line)) {
                echo ($num + 1) . ": " . trim($line) . "\n";
            }
        }
    }
}
