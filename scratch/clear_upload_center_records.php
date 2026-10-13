<?php
/**
 * Clear All Upload Centre Records in Database
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$pdo = getDbConnection();

echo "=======================================================\n";
echo "CLEARING ALL UPLOAD CENTRE RECORDS IN DATABASE\n";
echo "=======================================================\n\n";

// 1. Truncate all Upload Centre tables in Neon PostgreSQL Global DB
$uploadTables = [
    'public.import_rows',
    'public.import_errors',
    'public.import_batches',
    'public.upload_file_mappings',
    'public.upload_scopes',
    'public.upload_files',
    'public.uploads'
];

echo "1. Truncating Upload Centre tables in Global DB...\n";
foreach ($uploadTables as $tbl) {
    try {
        $pdo->exec("TRUNCATE TABLE {$tbl} RESTART IDENTITY CASCADE");
        echo "   ✔ Truncated {$tbl}\n";
    } catch (Exception $e) {
        echo "   ✖ Error truncating {$tbl}: " . $e->getMessage() . "\n";
    }
}

// 2. Clear Upload Centre audit log entries
echo "\n2. Cleaning Upload Centre audit log entries...\n";
try {
    $delAudit = $pdo->exec("DELETE FROM public.audit_logs WHERE module = 'Upload Centre' OR action LIKE '%UPLOAD%' OR action LIKE '%IMPORT%'");
    echo "   ✔ Removed {$delAudit} Upload Centre audit log records.\n";
} catch (Exception $e) {
    echo "   ✖ Error clearing audit logs: " . $e->getMessage() . "\n";
}

// 3. Clear session and memory cache for upload stats
echo "\n3. Resetting Upload Centre cache...\n";
clearUploadCentreCache();
echo "   ✔ Upload Centre session cache cleared.\n";

// 4. Remove temporary uploaded files from disk
echo "\n4. Cleaning temporary upload files from disk...\n";
$tempDir = __DIR__ . '/../uploads/temp';
$deletedFilesCount = 0;
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
            $deletedFilesCount++;
        }
    }
}
echo "   ✔ Removed {$deletedFilesCount} temporary upload file(s) from uploads/temp/.\n";

// 5. Clean test import transactions from Customer Database
echo "\n5. Cleaning test import records from Customer DB...\n";
try {
    $custPdo = getCustomerDbConnection('cost360_customer_cust001');
    if ($custPdo) {
        // Material transactions created by upload tests
        $delMat = $custPdo->exec("DELETE FROM material_transaction WHERE doc_no LIKE 'DOC-%' OR doc_no LIKE 'TX-%'");
        echo "   ✔ Deleted {$delMat} test material transaction row(s)\n";

        // Standard cost created by upload tests
        $delSc = $custPdo->exec("DELETE FROM standard_cost WHERE product_code LIKE 'ITEM-COST-%'");
        echo "   ✔ Deleted {$delSc} test standard cost row(s)\n";

        // Test items created by upload tests
        $delItems = $custPdo->exec("DELETE FROM items WHERE item_code LIKE 'ITEM-MSTR-%' OR item_code LIKE 'MSTR-ITEM-%' OR item_code LIKE 'ITEM-OTHER-%' OR item_code LIKE 'ITEM-CRIT-%'");
        echo "   ✔ Deleted {$delItems} test item row(s)\n";
    }
} catch (Exception $e) {
    echo "   ✖ Notice: " . $e->getMessage() . "\n";
}

// 6. Verify final counts
echo "\n=======================================================\n";
echo "FINAL VERIFICATION OF UPLOAD CENTRE RECORD COUNTS:\n";
echo "=======================================================\n";
foreach ($uploadTables as $tbl) {
    try {
        $c = $pdo->query("SELECT count(*) FROM {$tbl}")->fetchColumn();
        echo " {$tbl}: {$c} records\n";
    } catch (Exception $e) {
        echo " {$tbl}: " . $e->getMessage() . "\n";
    }
}

$recentUploads = getRecentUploads(null, 10);
echo "\n Recent Upload Runs in Upload Centre: " . count($recentUploads) . "\n";

$stats = getUploadCentreStats(null);
echo " Stats: Files={$stats['files']}, Imports={$stats['imports']}, Rows={$stats['rows']}, Errors={$stats['errors']}\n";

echo "\nAll Upload Centre records successfully cleared!\n";
