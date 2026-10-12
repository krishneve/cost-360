<?php
$lines = file('config/upload_centre.php');
for ($i = 700; $i <= 820; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
