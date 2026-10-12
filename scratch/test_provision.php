<?php
require_once __DIR__ . '/../config/database.php';

function testProvision($cid) {
    echo "Testing provision for Customer ID: $cid\n";
    $centralPdo = getDbConnection();
    
    $cid = (int)$cid;
    if ($cid <= 0) {
        throw new Exception("Invalid Customer ID.");
    }
    $dbName = "cust_" . $cid;

    // Check if DB exists
    $stmt = $centralPdo->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
    $stmt->execute([':dbname' => $dbName]);
    $exists = (bool)$stmt->fetchColumn();

    if (!$exists) {
        echo "Database $dbName does not exist. Creating...\n";
        $centralPdo->exec("CREATE DATABASE \"$dbName\"");
        echo "Database $dbName created successfully.\n";
    } else {
        echo "Database $dbName already exists.\n";
    }

    // Save/update mapping in customer_databases table
    $mStmt = $centralPdo->prepare("
        INSERT INTO public.customer_databases (customer_id, database_name, status, created_at, initialized_at)
        VALUES (:cid, :dbname, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT (customer_id) 
        DO UPDATE SET database_name = EXCLUDED.database_name, status = 'Active', initialized_at = CURRENT_TIMESTAMP
    ");
    $mStmt->execute([':cid' => $cid, ':dbname' => $dbName]);
    echo "customer_databases table updated.\n";

    // Connect to customer DB and initialize schema
    $custPdo = getCustomerDbConnection($cid);
    
    $ddl = "
    CREATE TABLE IF NOT EXISTS uoms (
        uom_id          BIGSERIAL PRIMARY KEY,
        uom_code        VARCHAR(20) NOT NULL UNIQUE,
        uom_name        VARCHAR(50) NOT NULL,
        description     VARCHAR(255),
        status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
    );

    CREATE TABLE IF NOT EXISTS periods (
        period_id       BIGSERIAL PRIMARY KEY,
        period_name     VARCHAR(50) NOT NULL,
        start_date      DATE NOT NULL,
        end_date        DATE NOT NULL,
        is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
        status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
        CHECK (end_date >= start_date)
    );

    CREATE TABLE IF NOT EXISTS items (
        item_id         BIGSERIAL PRIMARY KEY,
        item_code       VARCHAR(50)  NOT NULL UNIQUE,
        item_name       VARCHAR(150) NOT NULL,
        item_type       VARCHAR(30) NOT NULL DEFAULT 'Raw Material'
                            CHECK (item_type IN ('Raw Material','Finished Good','Semi-Finished','Consumable','Service')),
        uom_id          BIGINT NOT NULL REFERENCES uoms(uom_id),
        status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
    );

    CREATE TABLE IF NOT EXISTS bom_headers (
        bom_id          BIGSERIAL PRIMARY KEY,
        item_id         BIGINT NOT NULL REFERENCES items(item_id),
        revision_no     VARCHAR(20) NOT NULL DEFAULT '1',
        effective_from  DATE NOT NULL DEFAULT CURRENT_DATE,
        effective_to    DATE,
        status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
    );

    CREATE TABLE IF NOT EXISTS bom_details (
        bom_detail_id   BIGSERIAL PRIMARY KEY,
        bom_id          BIGINT NOT NULL REFERENCES bom_headers(bom_id),
        component_item_id BIGINT NOT NULL REFERENCES items(item_id),
        quantity        NUMERIC(14,4) NOT NULL,
        uom_id          BIGINT NOT NULL REFERENCES uoms(uom_id),
        status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
    );

    CREATE TABLE IF NOT EXISTS transactions (
        txn_id          BIGSERIAL PRIMARY KEY,
        item_id         BIGINT NOT NULL REFERENCES items(item_id),
        warehouse_id    BIGINT,
        period_id       BIGINT REFERENCES periods(period_id),
        txn_date        DATE NOT NULL DEFAULT CURRENT_DATE,
        txn_type        VARCHAR(30) NOT NULL CHECK (txn_type IN ('Receipt','Issue','Transfer','Adjustment')),
        quantity        NUMERIC(14,4) NOT NULL,
        value           NUMERIC(14,2) NOT NULL DEFAULT 0.00,
        source_upload_id BIGINT,
        status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
    );

    CREATE TABLE IF NOT EXISTS standard_cost (
        cost_id         BIGSERIAL PRIMARY KEY,
        item_id         BIGINT NOT NULL REFERENCES items(item_id),
        cost_component  VARCHAR(50) NOT NULL,
        cost_value      NUMERIC(14,4) NOT NULL,
        effective_from  DATE NOT NULL DEFAULT CURRENT_DATE,
        effective_to    DATE,
        status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
    );
    ";

    $custPdo->exec($ddl);
    echo "Customer schema initialized for $dbName.\n";

    // Verify empty tables
    foreach (['periods', 'uoms', 'items'] as $t) {
        $cnt = (int)$custPdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo "Table $t row count: $cnt (EMPTY: " . ($cnt === 0 ? 'YES' : 'NO') . ")\n";
    }

    return ['success' => true, 'database_name' => $dbName];
}

$res1 = testProvision(1);
$res2 = testProvision(2);
print_r($res1);
print_r($res2);
