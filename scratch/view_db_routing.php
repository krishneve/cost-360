<?php
$lines = file('config/database.php');
for ($i = 115; $i <= 180; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
