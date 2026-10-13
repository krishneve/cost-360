<?php
$lines = file('admin/users/api.php');
for ($i = 1450; $i <= 1550; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
