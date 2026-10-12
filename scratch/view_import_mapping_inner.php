<?php
$lines = file('config/upload_centre.php');
for ($i = 570; $i <= 620; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
