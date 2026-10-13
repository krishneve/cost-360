<?php
$lines = file('config/auth.php');
for ($i = 2557; $i <= 2630; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
