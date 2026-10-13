<?php
$html = file_get_contents(__DIR__ . '/rendered_mapping.html');
preg_match_all('/.{0,150}color:\s*#fff[^f].{0,150}/i', $html, $matches);
foreach ($matches[0] as $m) {
    echo "Match:\n" . trim($m) . "\n===================================\n";
}
