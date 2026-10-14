<?php
$lines = file('assets/js/upload_centre.js');
foreach ($lines as $i => $line) {
    if (stripos($line, 'masterGroup') !== false || stripos($line, 'subSelect') !== false || stripos($line, 'onSubCategoryChange') !== false || stripos($line, 'onMasterGroupChange') !== false) {
        echo ($i + 1) . ': ' . trim(substr($line, 0, 100)) . PHP_EOL;
    }
}
