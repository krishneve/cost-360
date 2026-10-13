<?php
$lines = file('config/upload_centre.php');
for ($i = 1040; $i <= 1160; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
