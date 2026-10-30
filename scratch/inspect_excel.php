<?php
$zip = new ZipArchive();
if ($zip->open(__DIR__ . '/../Document/EXCEL For Upload center (1).xlsx') === TRUE) {
    $xml = $zip->getFromName('xl/workbook.xml');
    echo "Workbook XML:\n" . $xml . "\n";
    $zip->close();
} else {
    echo "Could not open excel\n";
}
