<?php
$lines = file('config/database.php');
for ($i = 225; $i <= 345; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
