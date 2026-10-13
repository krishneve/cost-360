<?php
$lines = file('admin/customers/api.php');
for ($i = 540; $i <= 600; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
