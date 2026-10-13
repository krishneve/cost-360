<?php
$html = file_get_contents(__DIR__ . '/rendered_mapping.html');
preg_match_all('/color:\s*#[a-fA-F0-9]{3,6}/i', $html, $matches);
$counts = array_count_values(array_map('strtolower', $matches[0]));
print_r($counts);
