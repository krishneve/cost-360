<?php
$lines = file('config/auth.php');
for ($i = 1015; $i <= 1160; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
