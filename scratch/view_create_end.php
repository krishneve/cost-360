<?php
$lines = file('admin/users/api.php');
for ($i = 1250; $i <= 1350; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
