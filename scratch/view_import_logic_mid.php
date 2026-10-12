<?php
$lines = file('config/upload_centre.php');
for ($i = 770; $i <= 880; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
