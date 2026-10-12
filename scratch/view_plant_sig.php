<?php
$lines = file('config/auth.php');
for ($i = 1300; $i <= 1335; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
