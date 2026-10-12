<?php
$lines = file('config/upload_centre.php');
for ($i = 960; $i <= 1165; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
