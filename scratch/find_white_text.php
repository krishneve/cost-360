<?php
$html = file_get_contents(__DIR__ . '/rendered_mapping.html');
preg_match_all('/.{0,60}color:\s*#(?:fff|ffffff).{0,60}/i', $html, $matches);
foreach ($matches[0] as $m) {
    echo "Match: " . trim($m) . "\n---\n";
}
