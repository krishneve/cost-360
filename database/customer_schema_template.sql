-- =====================================================================
-- COST360 — DEDICATED CUSTOMER DATABASE SCHEMA TEMPLATE
-- This schema is executed once upon provisioning each customer database.
-- It is completely IDEMPOTENT (safe to execute multiple times).
-- Contains customer-specific business tables isolated from the global DB.
-- =====================================================================

-- 1. UOMS (Units of Measure)
CREATE TABLE IF NOT EXISTS uoms (
    uom_id          BIGSERIAL PRIMARY KEY,
    uom_code        VARCHAR(20) NOT NULL UNIQUE,
    uom_name        VARCHAR(50) NOT NULL,
    description     VARCHAR(255),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
);

-- 2. Accounting Periods
CREATE TABLE IF NOT EXISTS periods (
    period_id       BIGSERIAL PRIMARY KEY,
    period_name     VARCHAR(50) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    CHECK (end_date >= start_date)
);

-- 3. Item Master (Both relational item master and upload-target format)
CREATE TABLE IF NOT EXISTS item_master (
    id              BIGSERIAL PRIMARY KEY,
    item_code       VARCHAR(100) NOT NULL UNIQUE,
    item_description TEXT,
    uom             VARCHAR(30),
    rate            NUMERIC(15,4),
    category        VARCHAR(100),
    created_by      BIGINT,
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_im_code ON item_master(item_code);

CREATE TABLE IF NOT EXISTS items (
    item_id         BIGSERIAL PRIMARY KEY,
    item_code       VARCHAR(50) NOT NULL UNIQUE,
    item_name       VARCHAR(150) NOT NULL,
    item_type       VARCHAR(30) NOT NULL DEFAULT 'Raw Material'
                        CHECK (item_type IN ('Raw Material','Finished Good','Semi-Finished','Consumable','Service')),
    uom_id          BIGINT REFERENCES uoms(uom_id),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
);

CREATE INDEX IF NOT EXISTS idx_items_code ON items(item_code);

-- 4. Product Master
CREATE TABLE IF NOT EXISTS product_master (
    id              BIGSERIAL PRIMARY KEY,
    product_code    VARCHAR(100) NOT NULL UNIQUE,
    product_name    TEXT,
    uom             VARCHAR(30),
    grade           VARCHAR(50),
    net_weight_kg   NUMERIC(12,4),
    pour_weight_kg  NUMERIC(12,4),
    std_yield_pct   NUMERIC(8,2),
    created_by      BIGINT,
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pm_code ON product_master(product_code);

-- 5. Standard Cost
CREATE TABLE IF NOT EXISTS standard_cost (
    id              BIGSERIAL PRIMARY KEY,
    item_code       VARCHAR(100) NOT NULL,
    standard_rate   NUMERIC(15,4) NOT NULL,
    effective_date  DATE,
    currency        VARCHAR(10) DEFAULT 'INR',
    cost_component  VARCHAR(50) DEFAULT 'Material',
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sc_item ON standard_cost(item_code);

-- 6. Bill of Materials (BOM)
CREATE TABLE IF NOT EXISTS bom (
    id                  BIGSERIAL PRIMARY KEY,
    parent_item_code    VARCHAR(100) NOT NULL,
    component_item_code VARCHAR(100) NOT NULL,
    quantity            NUMERIC(15,4) NOT NULL,
    uom                 VARCHAR(30),
    scrap_pct           NUMERIC(8,2) DEFAULT 0,
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_bom_parent ON bom(parent_item_code);
CREATE INDEX IF NOT EXISTS idx_bom_component ON bom(component_item_code);

CREATE TABLE IF NOT EXISTS bom_headers (
    bom_id          BIGSERIAL PRIMARY KEY,
    item_id         BIGINT REFERENCES items(item_id) ON DELETE CASCADE,
    revision_no     VARCHAR(10) NOT NULL DEFAULT '1',
    effective_from  DATE NOT NULL,
    effective_to    DATE,
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    UNIQUE (item_id, revision_no)
);

CREATE TABLE IF NOT EXISTS bom_details (
    bom_detail_id       BIGSERIAL PRIMARY KEY,
    bom_id              BIGINT NOT NULL REFERENCES bom_headers(bom_id) ON DELETE CASCADE,
    component_item_id   BIGINT NOT NULL REFERENCES items(item_id),
    quantity            DECIMAL(18,4) NOT NULL CHECK (quantity > 0),
    uom_id              BIGINT REFERENCES uoms(uom_id),
    status              VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
);

-- 7. Manufacturing Routing
CREATE TABLE IF NOT EXISTS routing (
    id                  BIGSERIAL PRIMARY KEY,
    item_code           VARCHAR(100) NOT NULL,
    operation_seq       INT NOT NULL,
    work_center_code    VARCHAR(100),
    setup_time_mins     NUMERIC(10,2),
    run_time_mins       NUMERIC(10,2),
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_routing_item ON routing(item_code);

-- 8. Purchase Transactions
CREATE TABLE IF NOT EXISTS purchase_transaction (
    id                  BIGSERIAL PRIMARY KEY,
    po_number           VARCHAR(100),
    vendor_code         VARCHAR(100),
    item_code           VARCHAR(100) NOT NULL,
    qty                 NUMERIC(15,4) NOT NULL,
    unit_price          NUMERIC(15,4) NOT NULL,
    txn_date            DATE,
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pt_item ON purchase_transaction(item_code);
CREATE INDEX IF NOT EXISTS idx_pt_date ON purchase_transaction(txn_date);

-- 9. Consumption Transactions
CREATE TABLE IF NOT EXISTS consumption_transaction (
    id                  BIGSERIAL PRIMARY KEY,
    issue_number        VARCHAR(100),
    cost_center_code    VARCHAR(100),
    item_code           VARCHAR(100) NOT NULL,
    qty                 NUMERIC(15,4) NOT NULL,
    txn_date            DATE,
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ct_item ON consumption_transaction(item_code);

-- 10. Production Transactions
CREATE TABLE IF NOT EXISTS production_transaction (
    id                  BIGSERIAL PRIMARY KEY,
    wo_number           VARCHAR(100),
    product_code        VARCHAR(100) NOT NULL,
    produced_qty        NUMERIC(15,4) NOT NULL,
    rejected_qty        NUMERIC(15,4) DEFAULT 0,
    txn_date            DATE,
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pdt_code ON production_transaction(product_code);

-- 11. Machine Master
CREATE TABLE IF NOT EXISTS machine_master (
    id                  BIGSERIAL PRIMARY KEY,
    machine_code        VARCHAR(100) NOT NULL UNIQUE,
    machine_name        VARCHAR(200),
    work_center_code    VARCHAR(100),
    hourly_rate         NUMERIC(15,4),
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_mm_code ON machine_master(machine_code);

-- 12. Vendor Master
CREATE TABLE IF NOT EXISTS vendor_master (
    id                  BIGSERIAL PRIMARY KEY,
    vendor_code         VARCHAR(100) NOT NULL UNIQUE,
    vendor_name         VARCHAR(200) NOT NULL,
    contact_email       VARCHAR(150),
    status              VARCHAR(20) DEFAULT 'Active',
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_vm_code ON vendor_master(vendor_code);

-- 13. Cost Center Master
CREATE TABLE IF NOT EXISTS cost_center_master (
    id                  BIGSERIAL PRIMARY KEY,
    cost_center_code    VARCHAR(100) NOT NULL UNIQUE,
    cost_center_name    VARCHAR(200) NOT NULL,
    department          VARCHAR(100),
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ccm_code ON cost_center_master(cost_center_code);

-- 14. Overhead Ledger
CREATE TABLE IF NOT EXISTS overhead_ledger (
    id                  BIGSERIAL PRIMARY KEY,
    account_code        VARCHAR(100) NOT NULL,
    account_name        VARCHAR(200),
    amount              NUMERIC(15,4) NOT NULL,
    period              VARCHAR(20),
    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ol_account ON overhead_ledger(account_code);

-- 15. Unified Transactions Table
CREATE TABLE IF NOT EXISTS transactions (
    txn_id              BIGSERIAL PRIMARY KEY,
    item_id             BIGINT REFERENCES items(item_id),
    warehouse_id        BIGINT,
    period_id           BIGINT REFERENCES periods(period_id),
    txn_date            DATE NOT NULL DEFAULT CURRENT_DATE,
    txn_type            VARCHAR(20) NOT NULL DEFAULT 'Issue'
                            CHECK (txn_type IN ('Receipt','Issue','Transfer','Adjustment')),
    quantity            DECIMAL(18,4) NOT NULL,
    value               DECIMAL(18,2) NOT NULL,
    source_upload_id    BIGINT,
    status              VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive','Reversed'))
);

CREATE INDEX IF NOT EXISTS idx_txn_item ON transactions(item_id);
CREATE INDEX IF NOT EXISTS idx_txn_date ON transactions(txn_date);
