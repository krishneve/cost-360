<?php
$lines = file('dashboard.php');
foreach ($lines as $i => $line) {
    if (stripos($line, 'upload-centre') !== false || stripos($line, 'Upload Centre') !== false || stripos($line, 'id="upload') !== false) {
        echo ($i + 1) . ': ' . trim(substr($line, 0, 100)) . PHP_EOL;
    }
}
