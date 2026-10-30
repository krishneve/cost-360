<?php
$c = file_get_contents(__DIR__ . '/../test_quantity_reco_phase2.php');
$c = preg_replace('/\bassertTest\(/', 'assertTestPhase2(', $c);
file_put_contents(__DIR__ . '/../test_quantity_reco_phase2.php', $c);
echo "Replaced successfully\n";
