<?php
$lines = file('config/upload_centre.php');
for ($i = 170; $i <= 350; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
