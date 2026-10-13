<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'preview';
$_POST['id'] = 1;

session_start();
$_SESSION['user_id'] = 3;
$_SESSION['username'] = 'aarav.cma@cost360.com';
$_SESSION['active_customer_id'] = 1;
$_SESSION['active_company_id'] = 2;
$_SESSION['active_company_code'] = 'ABC02';

ob_start();
include __DIR__ . '/../api/upload_api.php';
$raw = ob_get_clean();

file_put_contents(__DIR__ . '/api_preview_response.json', $raw);
echo "Written to api_preview_response.json\n";

$json = json_decode($raw, true);
if ($json === null) {
    echo "JSON DECODE FAILED! Error: " . json_last_error_msg() . "\n";
} else {
    echo "JSON ok: " . ($json['ok'] ? 'true' : 'false') . "\n";
    if (!$json['ok']) {
        echo "Error message: " . ($json['message'] ?? 'none') . "\n";
    }
}
