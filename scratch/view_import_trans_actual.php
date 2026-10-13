<?php
$lines = file('config/upload_centre.php');
for ($i = 930; $i <= 1060; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
