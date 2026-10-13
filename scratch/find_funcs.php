<?php
$lines = file('config/upload_centre.php');
foreach ($lines as $i => $line) {
    if (preg_match('/function\s+([a-zA-Z0-9_]+)/', $line, $m)) {
        echo ($i + 1) . ': ' . $m[1] . PHP_EOL;
    }
}
