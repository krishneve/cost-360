<?php
$lines = file('config/database.php');
for ($i = 445; $i <= 570; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
