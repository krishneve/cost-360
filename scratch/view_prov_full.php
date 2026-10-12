<?php
$lines = file('config/database.php');
for ($i = 465; $i <= 630; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
