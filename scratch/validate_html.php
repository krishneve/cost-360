<?php
$html = file_get_contents(__DIR__ . '/rendered_preview_modal.html');
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$loaded = $doc->loadHTML($html);
$errors = libxml_get_errors();
echo "DOM loaded: " . ($loaded ? 'YES' : 'NO') . "\n";
echo "Errors count: " . count($errors) . "\n";
foreach (array_slice($errors, 0, 5) as $e) {
    echo "  Line {$e->line}: {$e->message}\n";
}
