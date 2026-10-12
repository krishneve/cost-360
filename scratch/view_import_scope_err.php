<?php
$lines = file('config/upload_centre.php');
for ($i = 470; $i <= 515; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
