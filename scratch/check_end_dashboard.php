<?php
$lines = file('dashboard.php');
echo 'Total lines: ' . count($lines) . PHP_EOL;
for ($i = max(0, count($lines) - 40); $i < count($lines); $i++) {
    echo ($i + 1) . ': ' . trim(substr($lines[$i], 0, 100)) . PHP_EOL;
}
