<?php
$lines = file('config/auth.php');
for ($i = 1380; $i <= 1400; $i++) {
    echo $lines[$i];
}
echo "--- Warehouse ---\n";
for ($i = 1580; $i <= 1605; $i++) {
    echo $lines[$i];
}
