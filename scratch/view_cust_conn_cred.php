<?php
$lines = file('config/database.php');
for ($i = 265; $i <= 300; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
