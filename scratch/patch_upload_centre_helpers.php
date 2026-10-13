<?php
$content = file_get_contents('config/upload_centre.php');

// Define the helper functions to insert
$schemaHelpers = <<<'SCHEMACODE'

/**
 * Returns the predefined SQL CREATE TABLE DDL for a supported business table.
 * Strictly uses backend-controlled schemas matching Cost360 architecture specifications.
 */
function getPredefinedTableSchema(string $tableName): ?string {
    $clean = strtolower(trim($tableName));
    switch ($clean) {
        case 'items':
            return "
                CREATE TABLE IF NOT EXISTS public.items (
                    item_id         BIGSERIAL PRIMARY KEY,
                    item_code       VARCHAR(100) NOT NULL UNIQUE,
                    item_name       VARCHAR(255),
                    item_description TEXT,
                    item_type       VARCHAR(50) DEFAULT 'Raw Material',
                    category        VARCHAR(100),
                    uom             VARCHAR(50),
                    uom_id          BIGINT,
                    rate            NUMERIC(15,4) DEFAULT 0.0000,
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_by      BIGINT,
                    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_items_code ON public.items(item_code);
            ";

        case 'item_master':
            return "
                CREATE TABLE IF NOT EXISTS public.item_master (
                    id              BIGSERIAL PRIMARY KEY,
                    item_code       VARCHAR(100) NOT NULL UNIQUE,
                    item_description TEXT,
                    item_name       VARCHAR(255),
                    uom             VARCHAR(50),
                    rate            NUMERIC(15,4),
                    category        VARCHAR(100),
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_by      BIGINT,
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
                CREATE INDEX IF NOT EXISTS idx_item_master_code ON public.item_master(item_code);
            ";

        case 'product_master':
            return "
                CREATE TABLE IF NOT EXISTS public.product_master (
                    id              BIGSERIAL PRIMARY KEY,
                    product_code    VARCHAR(100) NOT NULL UNIQUE,
                    product_name    TEXT,
                    uom             VARCHAR(50),
                    grade           VARCHAR(50),
                    net_weight_kg   NUMERIC(12,4),
                    pour_weight_kg  NUMERIC(12,4),
                    std_yield_pct   NUMERIC(8,2),
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_by      BIGINT,
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'standard_cost':
            return "
                CREATE TABLE IF NOT EXISTS public.standard_cost (
                    id              BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT,
                    item_code       VARCHAR(100) NOT NULL,
                    cost_component  VARCHAR(50) DEFAULT 'Total',
                    cost_value      NUMERIC(15,4),
                    standard_rate   NUMERIC(15,4),
                    effective_date  DATE DEFAULT CURRENT_DATE,
                    effective_from  DATE DEFAULT CURRENT_DATE,
                    effective_to    DATE,
                    currency        VARCHAR(10) DEFAULT 'INR',
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'bom':
            return "
                CREATE TABLE IF NOT EXISTS public.bom (
                    id                  BIGSERIAL PRIMARY KEY,
                    parent_item_code    VARCHAR(100) NOT NULL,
                    component_item_code VARCHAR(100) NOT NULL,
                    quantity            NUMERIC(15,4) NOT NULL,
                    uom                 VARCHAR(50),
                    scrap_pct           NUMERIC(8,2) DEFAULT 0,
                    status              VARCHAR(20) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'bom_headers':
            return "
                CREATE TABLE IF NOT EXISTS public.bom_headers (
                    bom_id          BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT,
                    parent_item_code VARCHAR(100),
                    revision_no     VARCHAR(20) DEFAULT '1',
                    effective_from  DATE DEFAULT CURRENT_DATE,
                    effective_to    DATE,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'bom_details':
            return "
                CREATE TABLE IF NOT EXISTS public.bom_details (
                    bom_detail_id       BIGSERIAL PRIMARY KEY,
                    bom_id              BIGINT,
                    component_item_id   BIGINT,
                    component_item_code VARCHAR(100),
                    quantity            NUMERIC(14,4) NOT NULL,
                    uom                 VARCHAR(50),
                    uom_id              BIGINT,
                    status              VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'routing':
            return "
                CREATE TABLE IF NOT EXISTS public.routing (
                    id                  BIGSERIAL PRIMARY KEY,
                    item_code           VARCHAR(100) NOT NULL,
                    operation_seq       INT NOT NULL,
                    work_center_code    VARCHAR(100),
                    setup_time_mins     NUMERIC(10,2),
                    run_time_mins       NUMERIC(10,2),
                    status              VARCHAR(20) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'purchase_transaction':
            return "
                CREATE TABLE IF NOT EXISTS public.purchase_transaction (
                    id              BIGSERIAL PRIMARY KEY,
                    po_number       VARCHAR(100),
                    vendor_code     VARCHAR(100),
                    item_code       VARCHAR(100) NOT NULL,
                    qty             NUMERIC(15,4) NOT NULL,
                    unit_price      NUMERIC(15,4) NOT NULL,
                    txn_date        DATE DEFAULT CURRENT_DATE,
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'consumption_transaction':
            return "
                CREATE TABLE IF NOT EXISTS public.consumption_transaction (
                    id              BIGSERIAL PRIMARY KEY,
                    issue_number    VARCHAR(100),
                    cost_center_code VARCHAR(100),
                    item_code       VARCHAR(100) NOT NULL,
                    qty             NUMERIC(15,4) NOT NULL,
                    txn_date        DATE DEFAULT CURRENT_DATE,
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'production_transaction':
            return "
                CREATE TABLE IF NOT EXISTS public.production_transaction (
                    id              BIGSERIAL PRIMARY KEY,
                    wo_number       VARCHAR(100),
                    product_code    VARCHAR(100) NOT NULL,
                    produced_qty    NUMERIC(15,4) NOT NULL,
                    rejected_qty    NUMERIC(15,4) DEFAULT 0,
                    txn_date        DATE DEFAULT CURRENT_DATE,
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'machine_master':
        case 'work_centers':
            return "
                CREATE TABLE IF NOT EXISTS public.machine_master (
                    id                  BIGSERIAL PRIMARY KEY,
                    machine_code        VARCHAR(100) NOT NULL UNIQUE,
                    machine_name        VARCHAR(200),
                    work_center_code    VARCHAR(100),
                    hourly_rate         NUMERIC(15,4),
                    status              VARCHAR(20) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT now()
                );
                CREATE TABLE IF NOT EXISTS public.work_centers (
                    work_center_id      BIGSERIAL PRIMARY KEY,
                    work_center_code    VARCHAR(100) NOT NULL UNIQUE,
                    work_center_name    VARCHAR(200),
                    hourly_rate         NUMERIC(15,4) DEFAULT 0.00,
                    status              VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'vendor_master':
        case 'vendors':
            return "
                CREATE TABLE IF NOT EXISTS public.vendor_master (
                    id              BIGSERIAL PRIMARY KEY,
                    vendor_code     VARCHAR(100) NOT NULL UNIQUE,
                    vendor_name     VARCHAR(200) NOT NULL,
                    contact_email   VARCHAR(150),
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
                CREATE TABLE IF NOT EXISTS public.vendors (
                    vendor_id       BIGSERIAL PRIMARY KEY,
                    vendor_code     VARCHAR(100) NOT NULL UNIQUE,
                    vendor_name     VARCHAR(200) NOT NULL,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'overhead_ledger':
            return "
                CREATE TABLE IF NOT EXISTS public.overhead_ledger (
                    id              BIGSERIAL PRIMARY KEY,
                    account_code    VARCHAR(100) NOT NULL,
                    account_name    VARCHAR(200),
                    amount          NUMERIC(15,4) NOT NULL,
                    period          VARCHAR(50),
                    status          VARCHAR(20) DEFAULT 'Active',
                    created_at      TIMESTAMPTZ DEFAULT now()
                );
            ";

        case 'cost_center_master':
        case 'cost_centers':
            return "
                CREATE TABLE IF NOT EXISTS public.cost_center_master (
                    id                  BIGSERIAL PRIMARY KEY,
                    cost_center_code    VARCHAR(100) NOT NULL UNIQUE,
                    cost_center_name    VARCHAR(200) NOT NULL,
                    department          VARCHAR(100),
                    status              VARCHAR(20) DEFAULT 'Active',
                    created_at          TIMESTAMPTZ DEFAULT now()
                );
                CREATE TABLE IF NOT EXISTS public.cost_centers (
                    cost_center_id      BIGSERIAL PRIMARY KEY,
                    cost_center_code    VARCHAR(100) NOT NULL UNIQUE,
                    cost_center_name    VARCHAR(200) NOT NULL,
                    status              VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'uoms':
            return "
                CREATE TABLE IF NOT EXISTS public.uoms (
                    uom_id          BIGSERIAL PRIMARY KEY,
                    uom_code        VARCHAR(50) NOT NULL UNIQUE,
                    uom_name        VARCHAR(100) NOT NULL,
                    description     VARCHAR(255),
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'period':
        case 'periods':
            return "
                CREATE TABLE IF NOT EXISTS public.period (
                    period_id       BIGSERIAL PRIMARY KEY,
                    period_name     VARCHAR(50) NOT NULL,
                    start_date      DATE NOT NULL,
                    end_date        DATE NOT NULL,
                    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
                CREATE TABLE IF NOT EXISTS public.periods (
                    period_id       BIGSERIAL PRIMARY KEY,
                    period_name     VARCHAR(50) NOT NULL,
                    start_date      DATE NOT NULL,
                    end_date        DATE NOT NULL,
                    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        case 'transactions':
            return "
                CREATE TABLE IF NOT EXISTS public.transactions (
                    txn_id          BIGSERIAL PRIMARY KEY,
                    item_id         BIGINT,
                    item_code       VARCHAR(100),
                    warehouse_id    BIGINT,
                    period_id       BIGINT,
                    txn_date        DATE NOT NULL DEFAULT CURRENT_DATE,
                    txn_type        VARCHAR(30) NOT NULL,
                    quantity        NUMERIC(14,4) NOT NULL,
                    value           NUMERIC(14,2) NOT NULL DEFAULT 0.00,
                    source_upload_id BIGINT,
                    status          VARCHAR(20) DEFAULT 'Active'
                );
            ";

        default:
            return null;
    }
}

/**
 * Dynamically creates ONLY the required target table inside the customer's dedicated database on-demand.
 * Does not create unnecessary tables or schemas.
 */
function ensureTargetTableExistsInCustomerDb(PDO $custPdo, string $tableName): bool {
    $clean = strtolower(trim($tableName));
    if (empty($clean)) {
        return false;
    }

    try {
        $chk = $custPdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = :tname 
            LIMIT 1
        ");
        $chk->execute([':tname' => $clean]);
        if ($chk->fetch()) {
            return true;
        }

        $ddl = getPredefinedTableSchema($clean);
        if ($ddl) {
            $custPdo->exec($ddl);
            return true;
        }

        // Generic fallback for any other registered target entity
        $custPdo->exec("
            CREATE TABLE IF NOT EXISTS public.{$clean} (
                id          BIGSERIAL PRIMARY KEY,
                code        VARCHAR(100),
                name        VARCHAR(255),
                description TEXT,
                status      VARCHAR(20) DEFAULT 'Active',
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");
        return true;
    } catch (Throwable $e) {
        error_log("Dynamic Table Creation Error for table '{$clean}': " . $e->getMessage());
        return false;
    }
}

SCHEMACODE;

// Insert schema helpers before getTargetTableColumns if not present
if (strpos($content, 'function getPredefinedTableSchema') === false) {
    $content = str_replace(
        "// Dynamically discover columns and data types",
        $schemaHelpers . "\n// Dynamically discover columns and data types",
        $content
    );
}

file_put_contents('config/upload_centre.php', $content);
echo "Added schema helpers to upload_centre.php.\n";
