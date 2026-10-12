<?php
$lines = file('config/upload_centre.php');
for ($i = 175; $i <= 200; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
