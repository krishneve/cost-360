<?php
$lines = file('config/auth.php');
for ($i = 1505; $i <= 1530; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
