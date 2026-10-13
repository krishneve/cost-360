<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$pdo = getDbConnection();

echo "=================================================================\n";
echo "CLEARING ALL UPLOAD CENTRE & IMPORTED TEST RECORDS\n";
echo "=================================================================\n\n";

// 1. Upload Centre Pipeline Tables in Global Neon DB
$uploadCenterTables = [
    'public.import_rows',
    'public.import_errors',
    'public.import_batches',
    'public.upload_file_mappings',
    'public.upload_scopes',
    'public.upload_files',
    'public.uploads'
];

echo "1. Truncating Central Upload Centre Pipeline Tables...\n";
foreach ($uploadCenterTables as $table) {
    try {
        $countBefore = (int)$pdo->query("SELECT count(*) FROM {$table}")->fetchColumn();
        $pdo->exec("TRUNCATE TABLE {$table} RESTART IDENTITY CASCADE");
        echo "   ✔ Truncated {$table} (was: {$countBefore} rows -> now: 0)\n";
    } catch (Throwable $e) {
        echo "   ✖ Error on {$table}: " . $e->getMessage() . "\n";
    }
}

// 2. Remove Upload Centre Audit Log records
echo "\n2. Cleaning Upload Centre audit log entries...\n";
try {
    $delAudit = $pdo->exec("DELETE FROM public.audit_logs WHERE module = 'Upload Centre' OR action LIKE '%UPLOAD%' OR action LIKE '%IMPORT%'");
    echo "   ✔ Removed {$delAudit} Upload Centre audit log entries.\n";
} catch (Throwable $e) {
    echo "   ✖ Error cleaning audit logs: " . $e->getMessage() . "\n";
}

// 3. Clean temporary uploaded files on disk
echo "\n3. Cleaning temporary upload files from disk...\n";
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
echo "   ✔ Removed {$removedFiles} uploaded files from disk (uploads/temp/).\n";

// 4. Reset memory/session cache
echo "\n4. Resetting application cache...\n";
clearUploadCentreCache();
echo "   ✔ Upload Centre cache cleared.\n";

// 5. Customer Database: cost360_customer_cust001 (all 11 worksheet target tables)
echo "\n5. Clearing 11 target worksheet tables in Customer DB (cost360_customer_cust001)...\n";
$worksheetTables = [
    'items',
    'work_centers',
    'party_master',
    'cost_center_master',
    'standard_cost',
    'bom',
    'routing',
    'material_transaction',
    'downtime',
    'finance_transaction',
    'trial_balance'
];

try {
    $custPdo = getCustomerDbConnection('cost360_customer_cust001');
    if ($custPdo) {
        foreach ($worksheetTables as $wt) {
            try {
                $check = $custPdo->query("SELECT to_regclass('public.{$wt}')")->fetchColumn();
                if ($check) {
                    $cBefore = (int)$custPdo->query("SELECT count(*) FROM public.{$wt}")->fetchColumn();
                    $custPdo->exec("TRUNCATE TABLE public.{$wt} RESTART IDENTITY CASCADE");
                    echo "   ✔ Truncated customer table '{$wt}' (was: {$cBefore} rows -> now: 0)\n";
                }
            } catch (Throwable $we) {
                echo "   ✖ Notice for {$wt}: " . $we->getMessage() . "\n";
            }
        }
    }
} catch (Throwable $ce) {
    echo "   ✖ Customer DB connection notice: " . $ce->getMessage() . "\n";
}

// 6. Verification
echo "\n=================================================================\n";
echo "FINAL VERIFICATION REPORT\n";
echo "=================================================================\n";

echo "Global Upload Centre Tables:\n";
$allClean = true;
foreach ($uploadCenterTables as $table) {
    $count = (int)$pdo->query("SELECT count(*) FROM {$table}")->fetchColumn();
    $status = ($count === 0) ? "[EMPTY - PASS]" : "[NOT EMPTY - FAIL]";
    echo sprintf("  %-32s : %d rows  %s\n", $table, $count, $status);
    if ($count > 0) $allClean = false;
}

echo "\nCustomer DB (cost360_customer_cust001) Worksheet Tables:\n";
if (!empty($custPdo)) {
    foreach ($worksheetTables as $wt) {
        try {
            $check = $custPdo->query("SELECT to_regclass('public.{$wt}')")->fetchColumn();
            if ($check) {
                $count = (int)$custPdo->query("SELECT count(*) FROM public.{$wt}")->fetchColumn();
                $status = ($count === 0) ? "[EMPTY - PASS]" : "[NOT EMPTY - FAIL]";
                echo sprintf("  %-32s : %d rows  %s\n", $wt, $count, $status);
                if ($count > 0) $allClean = false;
            }
        } catch (Throwable $e) {}
    }
}

// Check saved_mappings table status
$savedMapCount = (int)$pdo->query("SELECT count(*) FROM public.saved_mappings")->fetchColumn();
echo "\nSaved Mapping Configurations (Retained for automatic reuse on next upload):\n";
echo "  public.saved_mappings            : {$savedMapCount} rule(s) preserved\n";

$stats = getUploadCentreStats(null);
echo "\nDashboard Metrics:\n";
echo "  Files Uploaded   : {$stats['files']}\n";
echo "  Imports Completed: {$stats['imports']}\n";
echo "  Rows Processed   : {$stats['rows']}\n";
echo "  Errors Traceable : {$stats['errors']}\n";

if ($allClean && $stats['files'] === 0) {
    echo "\n>>> SUCCESS: All Upload Centre tables and customer target tables are 100% EMPTY and ready for fresh testing! <<<\n";
} else {
    echo "\n>>> WARNING: Some records could not be cleared. <<<\n";
}
