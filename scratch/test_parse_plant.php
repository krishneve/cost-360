<?php
require_once __DIR__ . '/../config/upload_centre.php';

$rows = parseXlsxSheetRows(__DIR__ . '/../plant.xlsx', 'Plant', 20);
echo "Parsed " . count($rows) . " rows from plant.xlsx sheet 'Plant':\n";
$i = 1;
foreach ($rows as $r) {
    $filtered = array_values(array_filter($r, function($v) { return $v !== null && $v !== ''; }));
    if (!empty($filtered)) {
        echo "Row " . $i . ": " . json_encode($filtered) . "\n";
        $i++;
        if ($i > 10) break;
    }
}

