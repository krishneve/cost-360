<?php
$html = file_get_contents(__DIR__ . '/rendered_mapping.html');
$pos1 = strpos($html, 'id="c360MappingsTbody"');
if ($pos1 !== false) {
    $pos2 = strpos($html, '</tbody>', $pos1);
    echo substr($html, $pos1, ($pos2 - $pos1) + 8);
} else {
    echo "c360MappingsTbody not found!";
}
