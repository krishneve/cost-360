<?php
$lines = file('config/database.php');
for ($i = 50; $i <= 95; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
