<?php
$lines = file('config/upload_centre.php');
for ($i = 490; $i <= 620; $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
