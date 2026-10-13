<?php
$lines = file(__DIR__ . '/../config/auth.php');
foreach ($lines as $num => $l) {
    if (preg_match('/(INSERT\s+INTO\s+.*periods|UPDATE\s+.*periods|CREATE\s+TABLE.*periods)/i', $l)) {
        echo ($num + 1) . ": " . trim($l) . "\n";
    }
}
