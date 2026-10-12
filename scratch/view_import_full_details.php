<?php
$lines = file('config/upload_centre.php');
for ($i = 933; $i <= 1070; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
