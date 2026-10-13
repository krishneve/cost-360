<?php
$content = file_get_contents('admin/customers/api.php');
if (preg_match('/case\s+\'create_customer\'[\s\S]*?break;/i', $content, $matches)) {
    echo $matches[0];
} else if (preg_match('/case\s+\'create\'[\s\S]*?break;/i', $content, $matches)) {
    echo $matches[0];
} else if (preg_match('/case\s+\'save_customer\'[\s\S]*?break;/i', $content, $matches)) {
    echo $matches[0];
} else {
    echo "Actions in admin/customers/api.php:\n";
    preg_match_all('/case\s+\'([a-zA-Z0-9_]+)\'/', $content, $actions);
    print_r($actions[1]);
}
