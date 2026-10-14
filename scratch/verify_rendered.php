<?php
$html = file_get_contents(__DIR__ . '/rendered_mapping.html');
echo 'Has BOM: ' . (strpos($html, 'Bill of Materials') !== false ? 'YES' : 'NO') . "\n";
echo 'Has standard_cost: ' . (strpos($html, 'Standard Cost') !== false ? 'YES' : 'NO') . "\n";
echo 'Has Modal: ' . (strpos($html, 'c360EditMappingModal') !== false ? 'YES' : 'NO') . "\n";
echo 'Has Approve btn: ' . (strpos($html, 'Approve &amp; Save Mapping') !== false ? 'YES' : 'NO') . "\n";
echo 'Has c360-preview-dialog: ' . (strpos($html, 'c360-preview-dialog') !== false ? 'YES' : 'NO') . "\n";
echo 'Has c360-badge-green: ' . (strpos($html, 'c360-badge-green') !== false ? 'YES' : 'NO') . "\n";
echo 'Has color: #fff on dataset titles: ' . (strpos($html, 'color: #fff') !== false ? 'FOUND' : 'NONE') . "\n";
echo 'Has 0F172A: ' . (strpos($html, '#0F172A') !== false ? 'YES' : 'NO') . "\n";
