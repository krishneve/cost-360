<?php
$lines = file('config/auth.php');
for ($i = 2500; $i <= 2595; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
