<?php
$lines = file('config/upload_centre.php');
for ($i = 505; $i <= 575; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
