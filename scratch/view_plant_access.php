<?php
$lines = file('config/auth.php');
for ($i = 1385; $i <= 1435; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
