<?php
$lines = file('admin/customers/api.php');
for ($i = 190; $i <= 265; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
