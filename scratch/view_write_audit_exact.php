<?php
$lines = file('admin/users/api.php');
for ($i = 488; $i <= 572; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
