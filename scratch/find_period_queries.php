<?php
$files = glob(__DIR__ . '/../*.php');
$files = array_merge($files, glob(__DIR__ . '/../config/*.php'));
$files = array_merge($files, glob(__DIR__ . '/../api/*.php'));

foreach ($files as $f) {
    $c = file_get_contents($f);
    if (preg_match('/INSERT\s+INTO\s+.*periods/i', $c) || preg_match('/UPDATE\s+.*periods/i', $c)) {
        echo "Found period insert/update in " . basename($f) . "\n";
    }
}
