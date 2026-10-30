<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/quantity_reco.php';

$res = parsePlantExcelFile(__DIR__ . '/../plant.xlsx', 'Plant');
echo "Extracted: " . count($res) . "\n";
$imp = importPlantExcelToQuantityReco(getDbConnection(), 38, 1, __DIR__ . '/../plant.xlsx', ['replace_existing' => true]);
print_r($imp);

