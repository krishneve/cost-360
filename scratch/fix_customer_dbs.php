<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datname LIKE 'cust%'");
    $dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Found Customer DBs: " . implode(', ', $dbs) . PHP_EOL;

    foreach ($dbs as $db) {
        echo "Processing {$db}... ";
        try {
            $custPdo = getCustomerDbConnection($db);
            $custPdo->exec("
                CREATE TABLE IF NOT EXISTS period (
                    period_id       BIGSERIAL PRIMARY KEY,
                    period_name     VARCHAR(50) NOT NULL,
                    start_date      DATE NOT NULL,
                    end_date        DATE NOT NULL,
                    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
                    CHECK (end_date >= start_date)
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

                CREATE TABLE IF NOT EXISTS uoms (
                    uom_id          BIGSERIAL PRIMARY KEY,
                    uom_code        VARCHAR(20) NOT NULL UNIQUE,
                    uom_name        VARCHAR(50) NOT NULL,
                    description     VARCHAR(255),
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE TABLE IF NOT EXISTS items (
                    item_id         BIGSERIAL PRIMARY KEY,
                    item_code       VARCHAR(50)  NOT NULL UNIQUE,
                    item_name       VARCHAR(150) NOT NULL,
                    item_type       VARCHAR(30) NOT NULL DEFAULT 'Raw Material'
                                        CHECK (item_type IN ('Raw Material','Finished Good','Semi-Finished','Consumable','Service')),
                    uom_id          BIGINT REFERENCES uoms(uom_id),
                    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE INDEX IF NOT EXISTS idx_items_uom ON items(uom_id);

                CREATE TABLE IF NOT EXISTS work_centers (
                    work_center_id   BIGSERIAL PRIMARY KEY,
                    work_center_code VARCHAR(50) NOT NULL UNIQUE,
                    work_center_name VARCHAR(150) NOT NULL,
                    hourly_rate      NUMERIC(14,4) DEFAULT 0.00,
                    status           VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE TABLE IF NOT EXISTS cost_centers (
                    cost_center_id   BIGSERIAL PRIMARY KEY,
                    cost_center_code VARCHAR(50) NOT NULL UNIQUE,
                    cost_center_name VARCHAR(150) NOT NULL,
                    status           VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
                );

                CREATE TABLE IF NOT EXISTS vendors (
                    vendor_id        BIGSERIAL PRIMARY KEY,
                    vendor_code      VARCHAR(50) NOT NULL UNIQUE,
                    vendor_name      VARCHAR(150) NOT NULL,
                    status           VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
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
                    uom_id          BIGINT REFERENCES uoms(uom_id),
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
            ");
            echo "OK!" . PHP_EOL;
        } catch (Throwable $e) {
            echo "Error: " . $e->getMessage() . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    echo "Global DB Exception: " . $e->getMessage() . PHP_EOL;
}
