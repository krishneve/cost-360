<?php
$lines = file('config/upload_centre.php');
for ($i = 133; $i <= 147; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
