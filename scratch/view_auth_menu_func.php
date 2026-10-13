<?php
$lines = file('config/auth.php');
for ($i = 2125; $i <= 2230; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
