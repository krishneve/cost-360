<?php
/**
 * Test XLSX Native Parser
 */
function parseXlsxWorkbook($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("File not found: " . $filePath);
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception("Unable to open XLSX archive: " . $filePath);
    }

    // 1. Load Shared Strings
    $sharedStrings = [];
    $ssContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssContent !== false) {
        $xml = simplexml_load_string($ssContent);
        if ($xml) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $str = '';
                    foreach ($si->r as $r) {
                        $str .= (string)$r->t;
                    }
                    $sharedStrings[] = $str;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    // 2. Load Workbook relationships
    $rels = [];
    $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsContent !== false) {
        $relsXml = simplexml_load_string($relsContent);
        if ($relsXml) {
            foreach ($relsXml->Relationship as $rel) {
                $rels[(string)$rel['Id']] = (string)$rel['Target'];
            }
        }
    }

    // 3. Load Sheets from workbook.xml
    $sheets = [];
    $wbContent = $zip->getFromName('xl/workbook.xml');
    if ($wbContent === false) {
        $zip->close();
        throw new Exception("Invalid XLSX file: missing workbook.xml");
    }

    $wbXml = simplexml_load_string($wbContent);
    if (!$wbXml || !isset($wbXml->sheets->sheet)) {
        $zip->close();
        throw new Exception("No sheets found in XLSX workbook");
    }

    $sheetIndex = 0;
    foreach ($wbXml->sheets->sheet as $s) {
        $sheetName = (string)$s['name'];
        $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $rels[$rId] ?? '';
        if (strpos($target, 'xl/') !== 0 && strpos($target, '/') !== 0) {
            $target = 'xl/' . $target;
        } else {
            $target = ltrim($target, '/');
        }

        $sheetContent = $zip->getFromName($target);
        if ($sheetContent === false) {
            continue;
        }

        $sheetXml = simplexml_load_string($sheetContent);
        if (!$sheetXml || !isset($sheetXml->sheetData->row)) {
            $sheets[] = [
                'sheet_name' => $sheetName,
                'sheet_index' => $sheetIndex++,
                'headers' => [],
                'rows_count' => 0,
                'columns_count' => 0,
                'sample_rows' => []
            ];
            continue;
        }

        // Helper to convert cell coordinate like "AA2" into 0-based column index
        $colIndexFromCoord = function($cellRef) {
            $colLetters = preg_replace('/[0-9]/', '', $cellRef);
            $col = 0;
            for ($i = 0; $i < strlen($colLetters); $i++) {
                $col = $col * 26 + (ord(strtoupper($colLetters[$i])) - 64);
            }
            return $col - 1;
        };

        $headers = [];
        $sampleRows = [];
        $dataRowCount = 0;
        $allRows = [];

        foreach ($sheetXml->sheetData->row as $r) {
            $rowNum = (int)$r['r'];
            $rowVals = [];
            $maxCol = 0;

            foreach ($r->c as $c) {
                $ref = (string)$c['r'];
                $colIdx = $colIndexFromCoord($ref);
                if ($colIdx > $maxCol) $maxCol = $colIdx;
                $t = (string)$c['t'];
                $val = isset($c->v) ? (string)$c->v : '';
                if ($t === 's' && is_numeric($val) && isset($sharedStrings[(int)$val])) {
                    $val = $sharedStrings[(int)$val];
                } elseif ($t === 'inlineStr' && isset($c->is->t)) {
                    $val = (string)$c->is->t;
                }
                $rowVals[$colIdx] = trim($val);
            }

            // Fill empty cols up to maxCol
            $fullRow = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $fullRow[$i] = $rowVals[$i] ?? '';
            }

            if (empty($headers)) {
                // Remove trailing empty headers
                while (!empty($fullRow) && end($fullRow) === '') {
                    array_pop($fullRow);
                }
                $headers = $fullRow;
            } else {
                // Check if row is not purely empty
                $hasData = false;
                foreach ($fullRow as $v) {
                    if ($v !== '') {
                        $hasData = true;
                        break;
                    }
                }
                if ($hasData) {
                    // Check if row is just instruction text in parenthesis, e.g. "(supplier/customer)"
                    $nonEmpty = array_values(array_filter($fullRow, fn($v) => $v !== ''));
                    $isInstructionRow = (count($nonEmpty) === 1 && strpos($nonEmpty[0], '(') === 0 && str_ends_with($nonEmpty[0], ')'));
                    if (!$isInstructionRow) {
                        $dataRowCount++;
                        if (count($sampleRows) < 10) {
                            $sampleRows[] = $fullRow;
                        }
                    }
                }
            }
        }

        $sheets[] = [
            'sheet_name' => $sheetName,
            'sheet_index' => $sheetIndex++,
            'headers' => $headers,
            'rows_count' => $dataRowCount,
            'columns_count' => count($headers),
            'sample_rows' => $sampleRows
        ];
    }

    $zip->close();
    return $sheets;
}

$sheets = parseXlsxWorkbook(__DIR__ . '/../Document/EXCEL For Upload center (1).xlsx');
echo "Parsed " . count($sheets) . " sheets successfully:\n\n";
foreach ($sheets as $s) {
    echo "Sheet: " . $s['sheet_name'] . " | Cols: " . $s['columns_count'] . " | Data Rows: " . $s['rows_count'] . "\n";
    echo "Headers: " . implode(', ', array_slice($s['headers'], 0, 8)) . " ...\n";
    echo "--------------------------------------------------------\n";
}
