<?php
$lines = file('config/database.php');
for ($i = 450; $i <= 510; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
