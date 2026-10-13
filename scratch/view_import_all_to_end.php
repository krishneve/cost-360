<?php
$lines = file('config/upload_centre.php');
for ($i = 933; $i < count($lines); $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
