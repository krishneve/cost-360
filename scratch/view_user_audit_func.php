<?php
$lines = file('admin/users/api.php');
for ($i = 490; $i <= 545; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
