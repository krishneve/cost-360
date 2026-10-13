<?php
$lines = file('admin/customers/api.php');
for ($i = 635; $i <= 685; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
