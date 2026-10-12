<?php
$lines = file('config/auth.php');
for ($i = 2498; $i <= 2556; $i++) {
    echo $lines[$i];
}
