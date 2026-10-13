<?php
$lines = file('config/upload_centre.php');
for ($i = 880; $i <= 990; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
