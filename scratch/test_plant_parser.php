<?php
require_once __DIR__ . '/../config/upload_centre.php';

function parsePlantExcelRows(string $filePath, string $sheetName = 'Plant'): array {
    $parsed = parseXlsxSheetRows($filePath, $sheetName);
    $allRows = [];
    if (!empty($parsed['headers'])) {
        $allRows[] = $parsed['headers'];
    }
    if (!empty($parsed['rows'])) {
        foreach ($parsed['rows'] as $r) {
            $allRows[] = $r;
        }
    }

    // Find header row containing ANAME and CLOSING
    $headerRowIdx = -1;
    $colMap = [];
    foreach ($allRows as $idx => $row) {
        $foundAname = false;
        $foundClosing = false;
        foreach ($row as $cIdx => $cell) {
            $str = strtoupper(trim((string)$cell));
            if ($str === 'ANAME' || strpos($str, 'ACCOUNT NAME') !== false) {
                $colMap['aname'] = $cIdx;
                $foundAname = true;
            } elseif ($str === 'ACODE' || strpos($str, 'ACCOUNT CODE') !== false) {
                $colMap['acode'] = $cIdx;
            } elseif ($str === 'P&L' || strpos($str, 'PROFIT') !== false) {
                $colMap['pnl'] = $cIdx;
            } elseif ($str === 'CLOSING' || strpos($str, 'AMOUNT') !== false) {
                $colMap['closing'] = $cIdx;
                $foundClosing = true;
            } elseif ($str === 'COSTING BASIS' || strpos($str, 'COST BASIS') !== false) {
                $colMap['cost_basis'] = $cIdx;
            } elseif ($str === 'NATURE') {
                $colMap['nature'] = $cIdx;
            }
        }
        if ($foundAname && $foundClosing) {
            $headerRowIdx = $idx;
            break;
        }
    }

    if ($headerRowIdx === -1) {
        // Fallback to python openpyxl if simplexml didn't find headers
        $pyScript = __DIR__ . '/extract_plant_rows.py';
        if (file_exists($pyScript)) {
            $out = shell_exec("python " . escapeshellarg($pyScript) . " " . escapeshellarg($filePath));
            $jsonRows = json_decode($out, true);
            if (!empty($jsonRows)) {
                return $jsonRows;
            }
        }
        return [];
    }

    $records = [];
    for ($i = $headerRowIdx + 1; $i < count($allRows); $i++) {
        $row = $allRows[$i];
        $aname = trim((string)($row[$colMap['aname']] ?? ''));
        $closingRaw = trim((string)($row[$colMap['closing']] ?? ''));

        if ($aname === '' || $aname === '0' || strtoupper($aname) === 'TOTAL') {
            continue;
        }
        if ($closingRaw === '' && $aname === '') {
            continue;
        }

        $closing = (float)$closingRaw;
        $acode = trim((string)($row[$colMap['acode'] ?? -1] ?? ''));
        $pnl = trim((string)($row[$colMap['pnl'] ?? -1] ?? 'Rev from Operations')) ?: 'Rev from Operations';
        $basis = trim((string)($row[$colMap['cost_basis'] ?? -1] ?? ''));
        $nature = trim((string)($row[$colMap['nature'] ?? -1] ?? 'Variable')) ?: 'Variable';

        $records[] = [
            'row_idx' => $i + 1,
            'row_ref' => 'ROW-' . ($i + 1),
            'aname'   => $aname,
            'acode'   => $acode,
            'pnl'     => $pnl,
            'closing' => $closing,
            'basis'   => $basis,
            'nature'  => $nature
        ];
    }

    return $records;
}

$testRows = parsePlantExcelRows(__DIR__ . '/../plant.xlsx');
echo "parsePlantExcelRows extracted " . count($testRows) . " records from plant.xlsx\n";
if (!empty($testRows)) {
    echo "First row: " . json_encode($testRows[0]) . "\n";
    echo "Last row: " . json_encode(end($testRows)) . "\n";
}
