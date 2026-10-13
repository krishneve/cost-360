<?php
$lines = file('config/upload_centre.php');
for ($i = 548; $i <= 690; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
