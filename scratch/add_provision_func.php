<?php
$content = file_get_contents('config/database.php');

$provisionCode = <<<'CODE'

if (!function_exists('provisionCustomerDatabase')) {
    /**
     * Provisions a dedicated PostgreSQL database for a customer (cust_<customer_id>).
     * Creates the database if missing, initializes empty periods/uoms/items schemas,
     * and updates the customer_databases mapping in the Global DB.
     * Idempotent and safe to run multiple times.
     *
     * @param int $customerId
     * @return array
     * @throws Exception
     */
    function provisionCustomerDatabase($customerId) {
        $cid = (int)$customerId;
        if ($cid <= 0) {
            throw new Exception("Invalid Customer ID {$customerId} provided for database provisioning.");
        }

        $centralPdo = getDbConnection();
        $dbName = "cust_" . $cid;

        // 1. Check if the database already exists on the PostgreSQL server
        $stmt = $centralPdo->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
        $stmt->execute([':dbname' => $dbName]);
        $dbExists = (bool)$stmt->fetchColumn();

        if (!$dbExists) {
            try {
                // CREATE DATABASE cannot run in a multi-statement transaction block in PostgreSQL
                $centralPdo->exec("CREATE DATABASE \"{$dbName}\"");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false) {
                    if (strpos($e->getMessage(), 'permission denied') !== false || strpos($e->getMessage(), 'Insufficient privilege') !== false) {
                        throw new Exception("Customer database provisioning failed for '{$dbName}': PostgreSQL user requires CREATEDB privilege on server. (Error: " . $e->getMessage() . ")");
                    }
                    throw new Exception("Failed to create database '{$dbName}': " . $e->getMessage());
                }
            }
        }

        // 2. Insert or update the mapping record in Central DB customer_databases table
        $mappingStmt = $centralPdo->prepare("
            INSERT INTO public.customer_databases (customer_id, database_name, status, created_at, initialized_at)
            VALUES (:cid, :dbname, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (customer_id) 
            DO UPDATE SET 
                database_name = EXCLUDED.database_name, 
                status = 'Active', 
                initialized_at = CURRENT_TIMESTAMP
        ");
        $mappingStmt->execute([':cid' => $cid, ':dbname' => $dbName]);

        // 3. Connect to the customer's dedicated database
        $custPdo = getCustomerDbConnection($cid);

        // 4. Initialize customer schema: empty periods, uoms, items, bom, transactions, standard_cost
        $customerSchemaSql = "
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

        $custPdo->exec($customerSchemaSql);

        return [
            'success'       => true,
            'customer_id'   => $cid,
            'database_name' => $dbName,
            'status'        => 'Active',
            'tables'        => ['uoms', 'periods', 'items', 'bom_headers', 'bom_details', 'transactions', 'standard_cost']
        ];
    }
}
CODE;

if (strpos($content, 'function provisionCustomerDatabase') === false) {
    $content .= "\n" . $provisionCode . "\n";
    file_put_contents('config/database.php', $content);
    echo "Added provisionCustomerDatabase to config/database.php successfully.\n";
} else {
    echo "provisionCustomerDatabase already exists in config/database.php.\n";
}
