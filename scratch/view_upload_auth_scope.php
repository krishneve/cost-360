<?php
$lines = file('config/upload_centre.php');
for ($i = 150; $i <= 240; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
