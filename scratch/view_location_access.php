<?php
$lines = file('config/auth.php');
for ($i = 1775; $i <= 1820; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
echo "\n--- userCanAccessPeriod ---\n";
for ($i = 1965; $i <= 2000; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
