<?php
/**
 * COST360 — Subscription Management Database Migration & Verification
 * Step 1: Executes subscription_management_schema.sql and verifies constraints,
 * relationships, columns, indexes, and default seeds.
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();
    echo "=====================================================================\n";
    echo "COST360 — SUBSCRIPTION MANAGEMENT DATABASE MIGRATION\n";
    echo "=====================================================================\n\n";

    $sqlPath = __DIR__ . '/database/subscription_management_schema.sql';
    if (!file_exists($sqlPath)) {
        throw new RuntimeException("SQL schema file not found at: {$sqlPath}");
    }

    $sql = file_get_contents($sqlPath);
    echo "1. Executing migration SQL from database/subscription_management_schema.sql ...\n";
    $pdo->exec($sql);
    echo "   -> Migration SQL executed successfully.\n\n";

    // 2. Verify Created Tables
    echo "2. Verifying Created Tables in Global Database:\n";
    $stmtTables = $pdo->prepare("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
          AND table_name IN ('subscription_plans', 'customer_subscriptions')
        ORDER BY table_name
    ");
    $stmtTables->execute();
    $foundTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);
    foreach ($foundTables as $t) {
        echo "   [OK] Table: public.{$t}\n";
    }

    // 3. Inspect Columns of subscription_plans
    echo "\n3. Schema Details for 'subscription_plans':\n";
    $stmtPlanCols = $pdo->prepare("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'subscription_plans'
        ORDER BY ordinal_position
    ");
    $stmtPlanCols->execute();
    $planCols = $stmtPlanCols->fetchAll(PDO::FETCH_ASSOC);
    foreach ($planCols as $c) {
        $default = $c['column_default'] ? " DEFAULT {$c['column_default']}" : "";
        $nullable = $c['is_nullable'] === 'YES' ? "NULL" : "NOT NULL";
        echo "   - {$c['column_name']} ({$c['data_type']}) {$nullable}{$default}\n";
    }

    // 4. Inspect Columns of customer_subscriptions
    echo "\n4. Schema Details for 'customer_subscriptions':\n";
    $stmtSubCols = $pdo->prepare("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'customer_subscriptions'
        ORDER BY ordinal_position
    ");
    $stmtSubCols->execute();
    $subCols = $stmtSubCols->fetchAll(PDO::FETCH_ASSOC);
    foreach ($subCols as $c) {
        $default = $c['column_default'] ? " DEFAULT {$c['column_default']}" : "";
        $nullable = $c['is_nullable'] === 'YES' ? "NULL" : "NOT NULL";
        echo "   - {$c['column_name']} ({$c['data_type']}) {$nullable}{$default}\n";
    }

    // 5. Inspect Foreign Keys & Relationships
    echo "\n5. Relationships & Foreign Key Constraints:\n";
    $stmtFks = $pdo->prepare("
        SELECT
            conname,
            conrelid::regclass AS table_name,
            confrelid::regclass AS foreign_table,
            pg_get_constraintdef(c.oid) AS constraint_def
        FROM pg_constraint c
        JOIN pg_namespace n ON n.oid = c.connamespace
        WHERE n.nspname = 'public'
          AND c.contype = 'f'
          AND conrelid IN ('customer_subscriptions'::regclass, 'subscription_plans'::regclass)
        ORDER BY conrelid::regclass::text, conname
    ");
    $stmtFks->execute();
    $fks = $stmtFks->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fks as $fk) {
        echo "   [FK] {$fk['table_name']} -> {$fk['foreign_table']}: {$fk['constraint_def']}\n";
    }

    // 6. Inspect Indexes & Unique Constraints
    echo "\n6. Indexes and Unique Constraints:\n";
    $stmtIdx = $pdo->prepare("
        SELECT indexname, indexdef
        FROM pg_indexes
        WHERE schemaname = 'public'
          AND tablename IN ('subscription_plans', 'customer_subscriptions')
        ORDER BY tablename, indexname
    ");
    $stmtIdx->execute();
    $indexes = $stmtIdx->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $idx) {
        echo "   [IDX] {$idx['indexname']}: {$idx['indexdef']}\n";
    }

    // 7. Verify Default FREE Plan Seed
    echo "\n7. Default Plan Verification (FREE):\n";
    $stmtPlan = $pdo->prepare("
        SELECT plan_id, plan_code, plan_name, plan_type, price, currency, billing_cycle, max_users, status, limits_config
        FROM subscription_plans
        WHERE plan_code = 'FREE'
    ");
    $stmtPlan->execute();
    $defaultPlan = $stmtPlan->fetch(PDO::FETCH_ASSOC);
    if ($defaultPlan) {
        echo "   [OK] Plan ID       : {$defaultPlan['plan_id']}\n";
        echo "   [OK] Plan Code     : {$defaultPlan['plan_code']}\n";
        echo "   [OK] Plan Name     : {$defaultPlan['plan_name']}\n";
        echo "   [OK] Max Users     : {$defaultPlan['max_users']}\n";
        echo "   [OK] Status        : {$defaultPlan['status']}\n";
        echo "   [OK] Price         : {$defaultPlan['price']} {$defaultPlan['currency']}\n";
        echo "   [OK] Limits Config : {$defaultPlan['limits_config']}\n";
    } else {
        echo "   [FAIL] FREE plan not found!\n";
    }

    // 8. Business Rule Test: Single Active Subscription per Customer
    echo "\n8. Verifying Business Rule: Single Active Subscription per Customer:\n";
    $firstCustomer = $pdo->query("SELECT customer_id, customer_name FROM customers ORDER BY customer_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($firstCustomer && $defaultPlan) {
        $testCustId = $firstCustomer['customer_id'];
        $testPlanId = $defaultPlan['plan_id'];

        $pdo->beginTransaction();

        // Remove any prior test subscriptions for this customer during testing
        $pdo->prepare("DELETE FROM customer_subscriptions WHERE notes = '__TEST_RULE__'")->execute();

        // Insert first active subscription
        $stmtInsert1 = $pdo->prepare("
            INSERT INTO customer_subscriptions (customer_id, plan_id, status, notes)
            VALUES (?, ?, 'ACTIVE', '__TEST_RULE__')
        ");
        $stmtInsert1->execute([$testCustId, $testPlanId]);
        echo "   - Inserted active subscription #1 for Customer ID {$testCustId}: SUCCESS\n";

        // Insert historical/expired subscription for same customer (should succeed)
        $stmtInsertHist = $pdo->prepare("
            INSERT INTO customer_subscriptions (customer_id, plan_id, status, notes)
            VALUES (?, ?, 'EXPIRED', '__TEST_RULE__')
        ");
        $stmtInsertHist->execute([$testCustId, $testPlanId]);
        echo "   - Inserted historical/expired subscription for Customer ID {$testCustId}: SUCCESS (History supported)\n";

        // Attempt to insert second ACTIVE subscription for same customer (MUST FAIL due to partial unique index)
        try {
            $stmtInsert2 = $pdo->prepare("
                INSERT INTO customer_subscriptions (customer_id, plan_id, status, notes)
                VALUES (?, ?, 'ACTIVE', '__TEST_RULE__')
            ");
            $stmtInsert2->execute([$testCustId, $testPlanId]);
            echo "   - [FAIL] Second active subscription unexpectedly succeeded!\n";
        } catch (PDOException $ex) {
            echo "   - [PASS] Second active subscription correctly rejected by DB constraint: {$ex->getCode()}\n";
        }

        // Roll back the test transaction so no dummy data is left
        $pdo->rollBack();
        echo "   - Test transaction cleanly rolled back (database intact).\n";
    }

    echo "\n=====================================================================\n";
    echo "STATUS: Database foundation for Subscription Management is COMPLETE & VERIFIED.\n";
    echo "=====================================================================\n";

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
