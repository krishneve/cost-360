<?php
$files = scandir('database');
foreach ($files as $f) {
    if ($f !== '.' && $f !== '..') {
        echo "$f (" . filesize("database/$f") . " bytes)\n";
    }
}
