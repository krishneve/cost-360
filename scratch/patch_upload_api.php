<?php
/**
 * Applies Master Data Upsert and Period-Wise Transaction Management enhancements
 * to config/upload_centre.php and api/upload_api.php.
 */

// 1. Patch api/upload_api.php
$apiFile = __DIR__ . '/../api/upload_api.php';
$apiCode = file_get_contents($apiFile);

// Check if period handling is in upload_api.php
if (strpos($apiCode, '$periodId = !empty($_POST[\'period_id\'])') === false) {
    $searchImport = "\$result = executeDataImport(\$uploadId, \$user, \$selectedFileIds);";
    $replaceImport = "\$periodId = !empty(\$_POST['period_id']) ? (int)\$_POST['period_id'] : null;\n            \$result = executeDataImport(\$uploadId, \$user, \$selectedFileIds, \$periodId);";

    $apiCode = str_replace($searchImport, $replaceImport, $apiCode);

    // Add 'periods' action
    $searchStats = "case 'stats':";
    $periodsCase = "case 'periods':\n            \$periods = getUserAccessiblePeriods();\n            \$activePeriod = getAccountingPeriodContext();\n            if (ob_get_length()) ob_clean();\n            echo json_encode(['ok' => true, 'periods' => \$periods, 'active_period' => \$activePeriod]);\n            break;\n\n        case 'stats':";

    $apiCode = str_replace($searchStats, $periodsCase, $apiCode);
    file_put_contents($apiFile, $apiCode);
    echo "SUCCESS: Updated api/upload_api.php with period_id parameter and 'periods' action.\n";
} else {
    echo "api/upload_api.php already contains period handling.\n";
}
