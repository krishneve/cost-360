<?php
/**
 * Check and Clear Upload Centre Tables (Only Upload Centre)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$pdo = getDbConnection();

$uploadCenterTables = [
    'public.import_rows',
    'public.import_errors',
    'public.import_batches',
    'public.upload_file_mappings',
    'public.upload_scopes',
    'public.upload_files',
    'public.uploads'
];

echo "=================================================================\n";
echo "STEP 1: CHECKING UPLOAD CENTRE TABLES STATUS BEFORE CLEARING\n";
echo "=================================================================\n";
$needsClearing = false;
foreach ($uploadCenterTables as $table) {
    $count = (int)$pdo->query("SELECT count(*) FROM {$table}")->fetchColumn();
    echo sprintf("%-30s : %d rows\n", $table, $count);
    if ($count > 0) {
        $needsClearing = true;
    }
}

if ($needsClearing) {
    echo "\n=================================================================\n";
    echo "STEP 2: EXECUTING TARGETED TRUNCATE (ONLY UPLOAD CENTRE TABLES)\n";
    echo "=================================================================\n";

    $tableList = implode(', ', $uploadCenterTables);
    $pdo->exec("TRUNCATE TABLE {$tableList} RESTART IDENTITY CASCADE");
    echo "✔ Executed TRUNCATE RESTART IDENTITY CASCADE on all Upload Centre tables.\n";

    // Clean Upload Centre specific audit log entries
    $delAudit = $pdo->exec("DELETE FROM public.audit_logs WHERE module = 'Upload Centre' OR action LIKE '%UPLOAD%' OR action LIKE '%IMPORT%'");
    echo "✔ Removed {$delAudit} Upload Centre audit log entries.\n";

    // Clear temporary upload files from uploads/temp/
    $tempDir = __DIR__ . '/../uploads/temp';
    $removedFiles = 0;
    if (is_dir($tempDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
                $removedFiles++;
            }
        }
    }
    echo "✔ Removed {$removedFiles} uploaded files from disk (uploads/temp/).\n";

    // Clear session cache
    clearUploadCentreCache();
    echo "✔ Cleared Upload Centre stats and recent upload cache.\n";
} else {
    echo "\nAll Upload Centre tables are already completely empty.\n";
}

echo "\n=================================================================\n";
echo "STEP 3: FINAL VERIFICATION ACROSS ALL UPLOAD CENTRE TABLES\n";
echo "=================================================================\n";
$allEmpty = true;
foreach ($uploadCenterTables as $table) {
    $finalCount = (int)$pdo->query("SELECT count(*) FROM {$table}")->fetchColumn();
    $status = ($finalCount === 0) ? "[EMPTY - PASS]" : "[NOT EMPTY - FAIL]";
    echo sprintf("%-30s : %d rows  %s\n", $table, $finalCount, $status);
    if ($finalCount > 0) {
        $allEmpty = false;
    }
}

// Verify application stats
$stats = getUploadCentreStats(null);
$recent = getRecentUploads(null, 10);

echo "\nApplication Level Verification:\n";
echo "-------------------------------\n";
echo "Files Uploaded   : {$stats['files']}\n";
echo "Imports Completed: {$stats['imports']}\n";
echo "Rows Processed   : {$stats['rows']}\n";
echo "Errors Traceable : {$stats['errors']}\n";
echo "Recent Runs List : " . count($recent) . " records\n";

if ($allEmpty && $stats['files'] === 0 && count($recent) === 0) {
    echo "\nSUCCESS: All Upload Centre tables are verified completely EMPTY!\n";
} else {
    echo "\nWARNING: Some tables or stats still contain records.\n";
    exit(1);
}
