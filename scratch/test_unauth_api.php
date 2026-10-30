<?php
$_REQUEST = ['action' => 'get_reco', 'plant_id' => 39, 'period_id' => 1];
ob_start();
include __DIR__ . '/../api/quantity_reco_api.php';
echo ob_get_clean();
