<?php
$lines = file('config/upload_centre.php');
for ($i = 455; $i <= 560; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
