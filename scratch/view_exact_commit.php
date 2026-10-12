<?php
$lines = file('admin/customers/api.php');
for ($i = 650; $i <= 675; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
