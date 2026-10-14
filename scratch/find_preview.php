<?php
require_once __DIR__ . '/../config/upload_centre.php';
$refl = new ReflectionFunction('getFilePreviewData');
echo "getFilePreviewData: " . $refl->getFileName() . " : lines " . $refl->getStartLine() . " - " . $refl->getEndLine() . "\n";
