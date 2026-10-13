<?php
$lines = file('admin/users/api.php');
for ($i = 1550; $i <= 1640; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
