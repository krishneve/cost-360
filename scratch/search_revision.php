<?php
$files = glob(__DIR__ . '/../*.php');
$files = array_merge($files, glob(__DIR__ . '/../config/*.php'));
$files = array_merge($files, glob(__DIR__ . '/../api/*.php'));

foreach ($files as $f) {
    $content = file_get_contents($f);
    if (stripos($content, 'std_rev_date') !== false || stripos($content, 'effective_date') !== false || stripos($content, 'revision') !== false) {
        echo "Found in " . basename($f) . "\n";
    }
}
