<?php
$lines = file('config/auth.php');
for ($i = 1770; $i <= 1790; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
