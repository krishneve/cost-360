<?php
$lines = file('config/database.php');
for ($i = 135; $i <= 165; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
