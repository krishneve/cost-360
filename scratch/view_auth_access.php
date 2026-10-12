<?php
$lines = file('config/auth.php');
for ($i = 2496; $i <= 2565; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
