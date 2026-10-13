<?php
$lines = file('admin/users/api.php');
for ($i = 480; $i <= 573; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
