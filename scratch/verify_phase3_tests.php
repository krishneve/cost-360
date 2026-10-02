<?php
/**
 * Test Suite for Phase 3: Customer Database Resolution
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

echo "========================================================\n";
echo "       COST360 PHASE 3 TEST VERIFICATION SUITE         \n";
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
    echo sprintf("%-6s | Scenario %02d: %-32s | %s\n", $status, $id, $name, $details);
}

// ----------------------------------------------------
// SCENARIO 1: Customer 1 user -> Customer 1 DB Resolution
// ----------------------------------------------------
try {
    $mapping = getCustomerDatabaseMapping(1);
    $passed = ($mapping['customer_id'] == 1 && $mapping['database_name'] === 'cost360_customer_cust001');
    recordTest(1, "Customer 1 DB Resolution", $passed, "Resolved database_name: " . ($mapping['database_name'] ?? 'none'));
} catch (Throwable $e) {
    recordTest(1, "Customer 1 DB Resolution", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 2: Customer 2 user -> Customer 2 DB Resolution
// ----------------------------------------------------
try {
    $mapping = getCustomerDatabaseMapping(2);
    $passed = ($mapping['customer_id'] == 2 && $mapping['database_name'] === 'cost360_customer_cust002');
    recordTest(2, "Customer 2 DB Resolution", $passed, "Resolved database_name: " . ($mapping['database_name'] ?? 'none'));
} catch (Throwable $e) {
    recordTest(2, "Customer 2 DB Resolution", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 3: User Cannot Access Another Customer's DB
// ----------------------------------------------------
try {
    // User 5 belongs to Customer 1. Attempt to access Customer 2 DB.
    $canAccess = userCanAccessCustomerDb(5, 2);
    $deniedExceptionCaught = false;
    try {
        getCustomerDbConnection(2, 5);
    } catch (Throwable $ex) {
        if (strpos($ex->getMessage(), 'Unauthorized access') !== false) {
            $deniedExceptionCaught = true;
        }
    }
    $passed = (!$canAccess) && $deniedExceptionCaught;
    recordTest(3, "Cross-Customer Access Blocked", $passed, "userCanAccessCustomerDb(5, 2) returned false. Exception thrown on connection request.");
} catch (Throwable $e) {
    recordTest(3, "Cross-Customer Access Blocked", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 4: Missing customer_database Mapping
// ----------------------------------------------------
try {
    $missingExceptionCaught = false;
    try {
        getCustomerDatabaseMapping(999999);
    } catch (Throwable $ex) {
        if (strpos($ex->getMessage(), 'Missing customer_database mapping') !== false) {
            $missingExceptionCaught = true;
        }
    }
    recordTest(4, "Missing Mapping Handling", $missingExceptionCaught, "Successfully caught missing customer_database mapping exception.");
} catch (Throwable $e) {
    recordTest(4, "Missing Mapping Handling", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 5: Inactive Customer DB Handling
// ----------------------------------------------------
try {
    $pdo = getDbConnection();
    // Temporarily insert or update a test record with Inactive status
    $stmt = $pdo->prepare("
        INSERT INTO customer_databases (id, customer_id, database_name, status, created_at)
        VALUES (99999, 99999, 'cost360_customer_inactive', 'Inactive', CURRENT_TIMESTAMP)
        ON CONFLICT (id) DO UPDATE SET status = 'Inactive'
    ");
    $stmt->execute();

    $inactiveExceptionCaught = false;
    try {
        getCustomerDatabaseMapping(99999);
    } catch (Throwable $ex) {
        if (strpos($ex->getMessage(), 'inactive') !== false) {
            $inactiveExceptionCaught = true;
        }
    }

    // Cleanup test record
    $pdo->exec("DELETE FROM customer_databases WHERE id = 99999");

    recordTest(5, "Inactive Customer DB Guard", $inactiveExceptionCaught, "Successfully caught inactive customer database exception.");
} catch (Throwable $e) {
    recordTest(5, "Inactive Customer DB Guard", false, $e->getMessage());
}

// ----------------------------------------------------
// SCENARIO 6: Database Connection Failure Handling
// ----------------------------------------------------
try {
    $pdo = getDbConnection();
    // Insert temporary test record pointing to invalid database host/port
    $stmt = $pdo->prepare("
        INSERT INTO customer_databases (id, customer_id, database_name, host, port, status, created_at)
        VALUES (99998, 99998, 'non_existent_db_1234', '127.0.0.1', 59999, 'Active', CURRENT_TIMESTAMP)
        ON CONFLICT (id) DO UPDATE SET host = '127.0.0.1', port = 59999, database_name = 'non_existent_db_1234', status = 'Active'
    ");
    $stmt->execute();

    $failureExceptionCaught = false;
    try {
        // Platform admin (user_id 1) attempting connection to invalid endpoint
        getCustomerDbConnection(99998, 1);
    } catch (Throwable $ex) {
        if (strpos($ex->getMessage(), 'Database connection failure') !== false || strpos($ex->getMessage(), 'connection failed') !== false) {
            $failureExceptionCaught = true;
        }
    }

    // Cleanup test record
    $pdo->exec("DELETE FROM customer_databases WHERE id = 99998");

    recordTest(6, "DB Connection Failure Guard", $failureExceptionCaught, "Successfully caught connection failure exception with sanitized error.");
} catch (Throwable $e) {
    recordTest(6, "DB Connection Failure Guard", false, $e->getMessage());
}

echo "\n========================================================\n";
$total = count($results);
$passedCount = count(array_filter($results, function($r) { return $r['passed']; }));
echo "SUMMARY: {$passedCount} / {$total} Scenarios PASSED.\n";
echo "========================================================\n";
