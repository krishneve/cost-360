<?php
$lines = file('admin/users/api.php');
for ($i = 540; $i <= 630; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
