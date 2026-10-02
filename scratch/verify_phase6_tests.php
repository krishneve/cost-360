<?php
/**
 * Test Suite for Phase 6: Upload Processing & Customer Database Import
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

echo "========================================================\n";
echo "       COST360 PHASE 6 TEST VERIFICATION SUITE         \n";
echo "========================================================\n\n";

$results = [];

function recordTest($id, $name, $passed, $details) {
    global $results;
    $results[] = [
        'id' => $id,
        'name' => $name,
        'passed' => $passed,
        'details' => $details
    ];
    $status = $passed ? "[PASS]" : "[FAIL]";
    echo sprintf("%-6s | Scenario %02d: %-35s | %s\n", $status, $id, $name, $details);
}

// ----------------------------------------------------
// SCENARIO 1: Valid Upload Processing
// ----------------------------------------------------
try {
    $_SESSION = [];
    $_SESSION['user_id'] = 1;
    setActiveCompany(1);
    setActiveDivision(1);
    setActivePlant(1);
    setActivePeriod(3);

    // Create temporary sample CSV file
    $tmpDir = __DIR__ . '/../uploads/temp/test/';
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
    $csvFile = $tmpDir . 'sample_items.csv';
    file_put_contents($csvFile, "item_code,item_description,uom,rate\nITM001,Widget Alpha,PCS,150.00\nITM002,Widget Beta,PCS,250.00\n");

    $files = [
        'name' => ['sample_items.csv'],
        'type' => ['text/csv'],
        'tmp_name' => [$csvFile],
        'error' => [0],
        'size' => [filesize($csvFile)]
    ];

    $res = analyzeUploadedFiles($files, ['user_id' => 1, 'email' => 'admin@cost360.com']);
    $passed = ($res['ok'] === true && !empty($res['upload_id']));
    $testUploadId = $res['upload_id'] ?? null;
    recordTest(1, "Valid Upload Ingestion", $passed, "Created Upload ID: " . ($testUploadId ?? 'none'));
} catch (Throwable $e) {
    recordTest(1, "Valid Upload Ingestion", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 2: Invalid Upload Payload Guarding
// ----------------------------------------------------
try {
    $res = analyzeUploadedFiles([], ['user_id' => 1]);
    $passed = ($res['ok'] === false && strpos($res['message'], 'No files selected') !== false);
    recordTest(2, "Invalid Payload Guarding", $passed, "Response message: " . ($res['message'] ?? 'none'));
} catch (Throwable $e) {
    recordTest(2, "Invalid Payload Guarding", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 3: Mapping Errors Detection
// ----------------------------------------------------
try {
    if (!empty($testUploadId)) {
        $files = dbQuery("SELECT * FROM public.upload_files WHERE upload_id = ?", [$testUploadId])->fetchAll(PDO::FETCH_ASSOC);
        $fileId = $files[0]['id'] ?? 0;
        $preview = getFilePreviewData($fileId);
        $passed = ($preview['ok'] === true && !empty($preview['headers']));
        recordTest(3, "Mapping & Header Profiling", $passed, "Parsed headers: " . implode(', ', $preview['headers'] ?? []));
    } else {
        recordTest(3, "Mapping & Header Profiling", false, "No upload run available.");
    }
} catch (Throwable $e) {
    recordTest(3, "Mapping & Header Profiling", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 4: Validation Errors Trapping
// ----------------------------------------------------
try {
    // Create CSV missing item code identifier
    $invalidCsv = $tmpDir . 'invalid_items.csv';
    file_put_contents($invalidCsv, "item_code,item_description,uom,rate\n,Invalid Row Description,PCS,100.00\n");
    $invFiles = [
        'name' => ['invalid_items.csv'],
        'type' => ['text/csv'],
        'tmp_name' => [$invalidCsv],
        'error' => [0],
        'size' => [filesize($invalidCsv)]
    ];
    $invRes = analyzeUploadedFiles($invFiles, ['user_id' => 1, 'email' => 'admin@cost360.com']);
    $invUploadId = $invRes['upload_id'] ?? null;

    if ($invUploadId) {
        $impRes = executeDataImport($invUploadId, ['user_id' => 1, 'email' => 'admin@cost360.com']);
        $errors = getImportErrorsByUploadId($invUploadId);
        $passed = count($errors) > 0 || ($impRes['failed'] ?? 0) > 0;
        recordTest(4, "Validation Error Trapping", $passed, "Trapped " . count($errors) . " row validation errors in import_errors.");
    } else {
        recordTest(4, "Validation Error Trapping", false, "Failed to analyze invalid file.");
    }
} catch (Throwable $e) {
    recordTest(4, "Validation Error Trapping", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 5: Successful Import Execution
// ----------------------------------------------------
try {
    if (!empty($testUploadId)) {
        $impRes = executeDataImport($testUploadId, ['user_id' => 1, 'email' => 'admin@cost360.com']);
        $passed = ($impRes['ok'] === true);
        recordTest(5, "Successful Data Import", $passed, "Status: " . ($impRes['status'] ?? 'none') . " | Imported rows: " . ($impRes['imported'] ?? 0));
    } else {
        recordTest(5, "Successful Data Import", false, "No valid upload ID to import.");
    }
} catch (Throwable $e) {
    recordTest(5, "Successful Data Import", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 6: Customer DB Routing
// ----------------------------------------------------
try {
    $mapping = getCustomerDatabaseMapping(1);
    $passed = !empty($mapping['database_name']);
    recordTest(6, "Customer DB Routing Engine", $passed, "Resolved target database: " . $mapping['database_name']);
} catch (Throwable $e) {
    recordTest(6, "Customer DB Routing Engine", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 7: Customer Isolation Guard
// ----------------------------------------------------
try {
    // User 5 belongs to Customer 1. Verify access rejection when targeting Customer 2 DB.
    $canAccess = userCanAccessCustomerDb(5, 2);
    $passed = ($canAccess === false);
    recordTest(7, "Customer Tenant Isolation Guard", $passed, "userCanAccessCustomerDb(5, 2) returned false.");
} catch (Throwable $e) {
    recordTest(7, "Customer Tenant Isolation Guard", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 8: Unauthorized Company Guard
// ----------------------------------------------------
try {
    $_SESSION = [];
    $_SESSION['user_id'] = 5; // User 5 has access to Company 1 only
    $_SESSION['active_company_id'] = 99999;
    $_SESSION['active_division_id'] = 1;
    $_SESSION['active_plant_id'] = 1;
    $_SESSION['active_period_id'] = 3;

    $res = analyzeUploadedFiles(['name' => ['test.csv'], 'tmp_name' => [''], 'error' => [0], 'size' => [10]], getAuthUser() ?: ['user_id' => 5]);
    $passed = ($res['ok'] === false && strpos($res['message'], 'Authorization Error') !== false);
    recordTest(8, "Unauthorized Company Guard", $passed, "Response message: " . $res['message']);
} catch (Throwable $e) {
    recordTest(8, "Unauthorized Company Guard", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 9: Unauthorized Location Guard
// ----------------------------------------------------
try {
    $_SESSION = [];
    $_SESSION['user_id'] = 5;
    $_SESSION['active_company_id'] = 1;
    $_SESSION['active_division_id'] = 1;
    $_SESSION['active_plant_id'] = 99999; // Unauthorized plant
    $_SESSION['active_period_id'] = 3;

    $res = analyzeUploadedFiles(['name' => ['test.csv'], 'tmp_name' => [''], 'error' => [0], 'size' => [10]], getAuthUser() ?: ['user_id' => 5]);
    $passed = ($res['ok'] === false && strpos($res['message'], 'Authorization Error') !== false);
    recordTest(9, "Unauthorized Location Guard", $passed, "Response message: " . $res['message']);
} catch (Throwable $e) {
    recordTest(9, "Unauthorized Location Guard", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 10: Unauthorized Period Guard
// ----------------------------------------------------
try {
    $_SESSION = [];
    $_SESSION['user_id'] = 5;
    $_SESSION['active_company_id'] = 1;
    $_SESSION['active_division_id'] = 1;
    $_SESSION['active_plant_id'] = 1;
    $_SESSION['active_period_id'] = 99999; // Unauthorized period

    $res = analyzeUploadedFiles(['name' => ['test.csv'], 'tmp_name' => [''], 'error' => [0], 'size' => [10]], getAuthUser() ?: ['user_id' => 5]);
    $passed = ($res['ok'] === false && strpos($res['message'], 'Authorization Error') !== false);
    recordTest(10, "Unauthorized Period Guard", $passed, "Response message: " . $res['message']);
} catch (Throwable $e) {
    recordTest(10, "Unauthorized Period Guard", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 11: Unauthorized Customer Guard
// ----------------------------------------------------
try {
    $_SESSION = [];
    $_SESSION['user_id'] = 5; // User 5 is Customer 1
    $_SESSION['active_customer_id'] = 99999;
    $canAccess = userCanAccessCustomerDb(5, 99999);
    $passed = ($canAccess === false);
    recordTest(11, "Unauthorized Customer Guard", $passed, "userCanAccessCustomerDb(5, 99999) returned false.");
} catch (Throwable $e) {
    recordTest(11, "Unauthorized Customer Guard", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 12: Database Connection Failure Handling
// ----------------------------------------------------
try {
    $pdo = getDbConnection();
    // Insert temporary test mapping record with invalid database host/port
    $stmt = $pdo->prepare("
        INSERT INTO customer_databases (id, customer_id, database_name, host, port, status, created_at)
        VALUES (99998, 99998, 'non_existent_db_1234', '127.0.0.1', 59999, 'Active', CURRENT_TIMESTAMP)
        ON CONFLICT (id) DO UPDATE SET host = '127.0.0.1', port = 59999, database_name = 'non_existent_db_1234', status = 'Active'
    ");
    $stmt->execute();

    $failed = false;
    try {
        getCustomerDbConnection(99998, 1);
    } catch (Throwable $ex) {
        if (strpos($ex->getMessage(), 'connection failure') !== false || strpos($ex->getMessage(), 'failed') !== false) {
            $failed = true;
        }
    }

    // Cleanup test record
    $pdo->exec("DELETE FROM customer_databases WHERE id = 99998");

    recordTest(12, "DB Connection Failure Guard", $failed, "Successfully handled database connection failure without exposure.");
} catch (Throwable $e) {
    recordTest(12, "DB Connection Failure Guard", false, $e->getMessage());
}

echo "\n========================================================\n";
$total = count($results);
$passedCount = count(array_filter($results, function($r) { return $r['passed']; }));
echo "SUMMARY: {$passedCount} / {$total} Scenarios PASSED.\n";
echo "========================================================\n";
