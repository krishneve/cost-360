<?php
$lines = file('admin/customers/api.php');
for ($i = 340; $i <= 460; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
