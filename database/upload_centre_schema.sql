-- ===================================================================
-- COST360 UPLOAD CENTRE DATABASE SCHEMA MIGRATION
-- Safely creates Upload Centre tables & target import entities under public schema.
-- Preserves existing auth.* tables and users intact.
-- ===================================================================

-- 1. Master Category Classifications
CREATE TABLE IF NOT EXISTS master_groups (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    code VARCHAR(80) NOT NULL UNIQUE,
    description TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS target_entities (
    id BIGSERIAL PRIMARY KEY,
    entity_name VARCHAR(150) NOT NULL UNIQUE,
    table_name VARCHAR(150) NOT NULL UNIQUE,
    description TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'Active'
);

CREATE TABLE IF NOT EXISTS sub_categories (
    id BIGSERIAL PRIMARY KEY,
    master_group_id BIGINT NOT NULL REFERENCES master_groups(id),
    name VARCHAR(150) NOT NULL,
    code VARCHAR(80) NOT NULL,
    target_entity_id BIGINT REFERENCES target_entities(id),
    description TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(master_group_id, code)
);

CREATE TABLE IF NOT EXISTS target_fields (
    id BIGSERIAL PRIMARY KEY,
    target_entity_id BIGINT NOT NULL REFERENCES target_entities(id) ON DELETE CASCADE,
    field_name VARCHAR(150) NOT NULL,
    data_type VARCHAR(40) NOT NULL DEFAULT 'text',
    required BOOLEAN NOT NULL DEFAULT false,
    validation_rule JSONB,
    UNIQUE(target_entity_id, field_name)
);

-- 2. Mapping Configurations & Versions
CREATE TABLE IF NOT EXISTS mapping_configurations (
    id BIGSERIAL PRIMARY KEY,
    sub_category_id BIGINT NOT NULL REFERENCES sub_categories(id),
    target_entity_id BIGINT NOT NULL REFERENCES target_entities(id),
    active_version_id BIGINT,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS mapping_versions (
    id BIGSERIAL PRIMARY KEY,
    mapping_configuration_id BIGINT NOT NULL REFERENCES mapping_configurations(id) ON DELETE CASCADE,
    version_label VARCHAR(30) NOT NULL,
    confidence NUMERIC(5,2),
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_by BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(mapping_configuration_id, version_label)
);

CREATE TABLE IF NOT EXISTS mapping_fields (
    id BIGSERIAL PRIMARY KEY,
    mapping_version_id BIGINT NOT NULL REFERENCES mapping_versions(id) ON DELETE CASCADE,
    source_column VARCHAR(150) NOT NULL,
    target_field_id BIGINT REFERENCES target_fields(id),
    data_type VARCHAR(40),
    required BOOLEAN DEFAULT false,
    transformation TEXT,
    validation_rule JSONB,
    confidence NUMERIC(5,2)
);

CREATE TABLE IF NOT EXISTS source_column_aliases (
    id BIGSERIAL PRIMARY KEY,
    target_field_id BIGINT NOT NULL REFERENCES target_fields(id) ON DELETE CASCADE,
    alias VARCHAR(150) NOT NULL,
    UNIQUE(target_field_id, alias)
);

-- 3. Upload Runs & File Tracking
CREATE TABLE IF NOT EXISTS uploads (
    id BIGSERIAL PRIMARY KEY,
    import_code VARCHAR(40) NOT NULL UNIQUE,
    uploaded_by BIGINT,
    username VARCHAR(150),
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at TIMESTAMPTZ,
    total_rows BIGINT DEFAULT 0,
    success_rows BIGINT DEFAULT 0,
    warning_rows BIGINT DEFAULT 0,
    error_rows BIGINT DEFAULT 0,
    duplicate_rows BIGINT DEFAULT 0,
    duration_ms BIGINT
);

CREATE TABLE IF NOT EXISTS upload_files (
    id BIGSERIAL PRIMARY KEY,
    upload_id BIGINT NOT NULL REFERENCES uploads(id) ON DELETE CASCADE,
    original_name VARCHAR(255) NOT NULL,
    safe_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(20) NOT NULL,
    size_bytes BIGINT NOT NULL,
    rows_count BIGINT DEFAULT 0,
    columns_count INT DEFAULT 0,
    headers JSONB,
    master_group_id BIGINT REFERENCES master_groups(id),
    sub_category_id BIGINT REFERENCES sub_categories(id),
    mapping_version_id BIGINT REFERENCES mapping_versions(id),
    validation_status VARCHAR(30) DEFAULT 'Pending',
    status VARCHAR(30) DEFAULT 'Pending',
    storage_path TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS import_batches (
    id BIGSERIAL PRIMARY KEY,
    upload_file_id BIGINT NOT NULL REFERENCES upload_files(id) ON DELETE CASCADE,
    batch_no INT NOT NULL,
    batch_size INT NOT NULL,
    rows_processed INT DEFAULT 0,
    success_rows INT DEFAULT 0,
    warning_rows INT DEFAULT 0,
    error_rows INT DEFAULT 0,
    status VARCHAR(30) DEFAULT 'Pending',
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS import_rows (
    id BIGSERIAL PRIMARY KEY,
    upload_file_id BIGINT NOT NULL REFERENCES upload_files(id) ON DELETE CASCADE,
    batch_id BIGINT REFERENCES import_batches(id),
    row_number BIGINT NOT NULL,
    source_data JSONB NOT NULL,
    target_data JSONB,
    status VARCHAR(30) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS import_errors (
    id BIGSERIAL PRIMARY KEY,
    upload_id BIGINT REFERENCES uploads(id) ON DELETE CASCADE,
    upload_file_id BIGINT REFERENCES upload_files(id) ON DELETE CASCADE,
    batch_id BIGINT REFERENCES import_batches(id),
    sheet_name VARCHAR(150),
    row_number BIGINT,
    field VARCHAR(150),
    source_value TEXT,
    target_field VARCHAR(150),
    error_type VARCHAR(80),
    error_message TEXT NOT NULL,
    severity VARCHAR(20) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 4. Audit Log Table
CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id BIGSERIAL PRIMARY KEY,
    user_id BIGINT,
    username VARCHAR(150),
    action VARCHAR(80) NOT NULL,
    module VARCHAR(80) NOT NULL DEFAULT 'Upload Centre',
    entity_type VARCHAR(80),
    entity_id BIGINT,
    file_name VARCHAR(255),
    timestamp TIMESTAMPTZ NOT NULL DEFAULT now(),
    status VARCHAR(30),
    details JSONB,
    ip_address INET
);

CREATE INDEX IF NOT EXISTS idx_upload_files_upload ON upload_files(upload_id);
CREATE INDEX IF NOT EXISTS idx_import_errors_upload ON import_errors(upload_id);
CREATE INDEX IF NOT EXISTS idx_audit_timestamp ON audit_logs(timestamp DESC);

-- 5. Target Entity Master Tables for Import Operations
CREATE TABLE IF NOT EXISTS item_master (
    id BIGSERIAL PRIMARY KEY,
    item_code VARCHAR(100) NOT NULL UNIQUE,
    item_description TEXT,
    uom VARCHAR(30),
    rate NUMERIC(15,4),
    category VARCHAR(100),
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS product_master (
    id BIGSERIAL PRIMARY KEY,
    product_code VARCHAR(100) NOT NULL UNIQUE,
    product_name TEXT,
    uom VARCHAR(30),
    grade VARCHAR(50),
    net_weight_kg NUMERIC(12,4),
    pour_weight_kg NUMERIC(12,4),
    std_yield_pct NUMERIC(8,2),
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS standard_cost (
    id BIGSERIAL PRIMARY KEY,
    item_code VARCHAR(100) NOT NULL,
    standard_rate NUMERIC(15,4) NOT NULL,
    effective_date DATE,
    currency VARCHAR(10) DEFAULT 'INR',
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS bom (
    id BIGSERIAL PRIMARY KEY,
    parent_item_code VARCHAR(100) NOT NULL,
    component_item_code VARCHAR(100) NOT NULL,
    quantity NUMERIC(15,4) NOT NULL,
    uom VARCHAR(30),
    scrap_pct NUMERIC(8,2) DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS routing (
    id BIGSERIAL PRIMARY KEY,
    item_code VARCHAR(100) NOT NULL,
    operation_seq INT NOT NULL,
    work_center_code VARCHAR(100),
    setup_time_mins NUMERIC(10,2),
    run_time_mins NUMERIC(10,2),
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS purchase_transaction (
    id BIGSERIAL PRIMARY KEY,
    po_number VARCHAR(100),
    vendor_code VARCHAR(100),
    item_code VARCHAR(100) NOT NULL,
    qty NUMERIC(15,4) NOT NULL,
    unit_price NUMERIC(15,4) NOT NULL,
    txn_date DATE,
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS consumption_transaction (
    id BIGSERIAL PRIMARY KEY,
    issue_number VARCHAR(100),
    cost_center_code VARCHAR(100),
    item_code VARCHAR(100) NOT NULL,
    qty NUMERIC(15,4) NOT NULL,
    txn_date DATE,
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS production_transaction (
    id BIGSERIAL PRIMARY KEY,
    wo_number VARCHAR(100),
    product_code VARCHAR(100) NOT NULL,
    produced_qty NUMERIC(15,4) NOT NULL,
    rejected_qty NUMERIC(15,4) DEFAULT 0,
    txn_date DATE,
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS machine_master (
    id BIGSERIAL PRIMARY KEY,
    machine_code VARCHAR(100) NOT NULL UNIQUE,
    machine_name VARCHAR(200),
    work_center_code VARCHAR(100),
    hourly_rate NUMERIC(15,4),
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS vendor_master (
    id BIGSERIAL PRIMARY KEY,
    vendor_code VARCHAR(100) NOT NULL UNIQUE,
    vendor_name VARCHAR(200) NOT NULL,
    contact_email VARCHAR(150),
    status VARCHAR(20) DEFAULT 'Active',
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS overhead_ledger (
    id BIGSERIAL PRIMARY KEY,
    account_code VARCHAR(100) NOT NULL,
    account_name VARCHAR(200),
    amount NUMERIC(15,4) NOT NULL,
    period VARCHAR(20),
    created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS cost_center_master (
    id BIGSERIAL PRIMARY KEY,
    cost_center_code VARCHAR(100) NOT NULL UNIQUE,
    cost_center_name VARCHAR(200) NOT NULL,
    department VARCHAR(100),
    created_at TIMESTAMPTZ DEFAULT now()
);

-- 6. Initial Seed Records
INSERT INTO master_groups(name, code, description) VALUES
('Item Master', 'ITEM_MASTER', 'Item-related master data'),
('Item Group Master', 'ITEM_GROUP_MASTER', 'Item grouping'),
('BOM', 'BOM', 'Bill of material and routing'),
('Trial Balance', 'TRIAL_BALANCE', 'Trial balance data'),
('Stock Ledger', 'STOCK_LEDGER', 'Stock transactions'),
('Work Center Master', 'WORK_CENTER_MASTER', 'Work center and machine data'),
('Account Master', 'ACCOUNT_MASTER', 'Account, vendor and overhead data'),
('Account Group Master', 'ACCOUNT_GROUP_MASTER', 'Account grouping'),
('Cost Center Master', 'COST_CENTER_MASTER', 'Cost center data'),
('Machine Log', 'MACHINE_LOG', 'Machine activity')
ON CONFLICT (code) DO NOTHING;

INSERT INTO target_entities(entity_name, table_name) VALUES
('item_master', 'item_master'),
('product_master', 'product_master'),
('standard_cost', 'standard_cost'),
('bom', 'bom'),
('routing', 'routing'),
('purchase_transaction', 'purchase_transaction'),
('consumption_transaction', 'consumption_transaction'),
('production_transaction', 'production_transaction'),
('overhead_ledger', 'overhead_ledger'),
('machine_master', 'machine_master'),
('vendor_master', 'vendor_master'),
('cost_center_master', 'cost_center_master')
ON CONFLICT (table_name) DO NOTHING;

WITH x(m, c, s, t) AS (VALUES 
('ITEM_MASTER', 'ITEM_MASTER', 'Item Master', 'item_master'),
('ITEM_MASTER', 'PRODUCT_MASTER', 'Product Master', 'product_master'),
('ITEM_MASTER', 'STANDARD_COST', 'Standard Cost', 'standard_cost'),
('BOM', 'BOM', 'BOM', 'bom'),
('BOM', 'ROUTING', 'Routing', 'routing'),
('STOCK_LEDGER', 'PURCHASE_TXN', 'Purchase Transaction', 'purchase_transaction'),
('STOCK_LEDGER', 'CONSUMPTION_TXN', 'Consumption Transaction', 'consumption_transaction'),
('STOCK_LEDGER', 'PRODUCTION_TXN', 'Production Transaction', 'production_transaction'),
('WORK_CENTER_MASTER', 'MACHINE_MASTER', 'Machine Master', 'machine_master'),
('ACCOUNT_MASTER', 'VENDOR_MASTER', 'Vendor Master', 'vendor_master'),
('ACCOUNT_MASTER', 'OVERHEAD_LEDGER', 'Overhead Ledger', 'overhead_ledger'),
('COST_CENTER_MASTER', 'COST_CENTER_MASTER', 'Cost Center Master', 'cost_center_master')
)
INSERT INTO sub_categories(master_group_id, name, code, target_entity_id)
SELECT mg.id, x.s, x.c, te.id
FROM x 
JOIN master_groups mg ON mg.code = x.m 
JOIN target_entities te ON te.table_name = x.t 
ON CONFLICT (master_group_id, code) DO NOTHING;

-- 7. Register Navigation Menus in menus for RBAC Sidebar
INSERT INTO menus (menu_name, menu_code, route, icon, parent_menu_id, display_order)
VALUES 
('Upload Centre', 'UPLOAD_CENTRE', 'upload-centre.php', 'calculator', NULL, 20),
('Mapping Centre', 'MAPPING_CENTRE', 'mapping-centre.php', 'database', NULL, 21),
('Import History', 'IMPORT_HISTORY', 'import-history.php', 'assessment', NULL, 22),
('Audit Logs', 'AUDIT_LOGS', 'audit-logs.php', 'settings', NULL, 23),
('Master Groups', 'MASTER_GROUPS', 'master-groups.php', 'business', NULL, 24)
ON CONFLICT (menu_code) DO UPDATE 
SET route = EXCLUDED.route;

-- Grant Menu Access to PLATFORM_ADMIN role
INSERT INTO role_menu (role_id, menu_id, can_view, is_active)
SELECT r.role_id, m.menu_id, TRUE, TRUE
FROM roles r
CROSS JOIN menus m
WHERE r.role_code = 'PLATFORM_ADMIN'
  AND m.menu_code IN ('UPLOAD_CENTRE', 'MAPPING_CENTRE', 'IMPORT_HISTORY', 'AUDIT_LOGS', 'MASTER_GROUPS')
ON CONFLICT (role_id, menu_id) DO NOTHING;
