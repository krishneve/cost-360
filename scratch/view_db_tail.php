<?php
$lines = file('config/database.php');
for ($i = 210; $i < count($lines); $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
