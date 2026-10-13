<?php
$lines = file('config/upload_centre.php');
for ($i = 520; $i <= 720; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
