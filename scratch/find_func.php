<?php
require_once __DIR__ . '/../config/upload_centre.php';
$refl = new ReflectionFunction('isMasterDataset');
echo "isMasterDataset: " . $refl->getFileName() . " : lines " . $refl->getStartLine() . " - " . $refl->getEndLine() . "\n";
