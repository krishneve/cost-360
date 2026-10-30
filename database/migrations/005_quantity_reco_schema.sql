-- =====================================================================
-- COST360 — QUANTITY RECO DATABASE MIGRATION (005_quantity_reco_schema.sql)
-- Implements Quantity Reco normalized schema, cost basis mappings,
-- source transactions, reconciliation runs, details, and exceptions.
-- =====================================================================

-- 1. Enhance cost_center_master to support plant_id, cost_basis_code, effective dates
ALTER TABLE public.cost_center_master ADD COLUMN IF NOT EXISTS plant_id BIGINT REFERENCES public.plants(plant_id) ON DELETE CASCADE;
ALTER TABLE public.cost_center_master ADD COLUMN IF NOT EXISTS cost_basis_code VARCHAR(100);
ALTER TABLE public.cost_center_master ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE public.cost_center_master ADD COLUMN IF NOT EXISTS effective_from DATE DEFAULT CURRENT_DATE;
ALTER TABLE public.cost_center_master ADD COLUMN IF NOT EXISTS effective_to DATE;
ALTER TABLE public.cost_center_master ADD COLUMN IF NOT EXISTS display_order INT DEFAULT 0;

-- Drop obsolete global unique constraint on cost_center_code if present
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'cost_center_master_cost_center_code_key'
    ) THEN
        ALTER TABLE public.cost_center_master DROP CONSTRAINT cost_center_master_cost_center_code_key;
    END IF;
END $$;

-- Add plant-scoped uniqueness: each plant has its own unique cost centre codes
CREATE UNIQUE INDEX IF NOT EXISTS idx_cost_center_plant_code 
ON public.cost_center_master (plant_id, cost_center_code);

CREATE INDEX IF NOT EXISTS idx_cost_center_plant 
ON public.cost_center_master (plant_id);

-- 2. Cost Basis Mapping Table
CREATE TABLE IF NOT EXISTS public.cost_basis_mapping (
    mapping_id BIGSERIAL PRIMARY KEY,
    plant_id BIGINT NOT NULL REFERENCES public.plants(plant_id) ON DELETE CASCADE,
    source_cost_basis VARCHAR(150) NOT NULL,
    cost_centre_id BIGINT NOT NULL REFERENCES public.cost_center_master(id) ON DELETE CASCADE,
    priority INT NOT NULL DEFAULT 100,
    effective_from DATE DEFAULT CURRENT_DATE,
    effective_to DATE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES public.users(user_id),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_plant_cost_basis UNIQUE (plant_id, source_cost_basis)
);

CREATE INDEX IF NOT EXISTS idx_cbm_plant ON public.cost_basis_mapping(plant_id);
CREATE INDEX IF NOT EXISTS idx_cbm_cost_centre ON public.cost_basis_mapping(cost_centre_id);

-- 3. Source Transaction Data Table
-- Normalized storage for source records (Trial Balance, Finance Txns, Uploads)
CREATE TABLE IF NOT EXISTS public.quantity_reco_source_transactions (
    source_txn_id BIGSERIAL PRIMARY KEY,
    plant_id BIGINT NOT NULL REFERENCES public.plants(plant_id) ON DELETE CASCADE,
    period_id BIGINT REFERENCES public.periods(period_id) ON DELETE CASCADE,
    period_code VARCHAR(100),
    source VARCHAR(50) NOT NULL DEFAULT 'TB',
    account_code VARCHAR(100),
    item_code VARCHAR(100),
    description TEXT NOT NULL,
    amount NUMERIC(18,4) NOT NULL,
    cost_basis VARCHAR(150) NOT NULL,
    pnl_classification VARCHAR(100) DEFAULT 'Rev from Operations',
    nature VARCHAR(50) DEFAULT 'Variable',
    source_file VARCHAR(255),
    source_row_ref VARCHAR(100),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES public.users(user_id)
);

CREATE INDEX IF NOT EXISTS idx_qr_src_plant_period ON public.quantity_reco_source_transactions(plant_id, period_id);
CREATE INDEX IF NOT EXISTS idx_qr_src_cost_basis ON public.quantity_reco_source_transactions(cost_basis);

-- 4. Quantity Reco Run Header
CREATE TABLE IF NOT EXISTS public.quantity_reco_run (
    run_id BIGSERIAL PRIMARY KEY,
    plant_id BIGINT NOT NULL REFERENCES public.plants(plant_id) ON DELETE CASCADE,
    period_id BIGINT REFERENCES public.periods(period_id) ON DELETE CASCADE,
    period_code VARCHAR(100),
    status VARCHAR(30) NOT NULL DEFAULT 'COMPLETED',
    source_version VARCHAR(100),
    total_source_records INT NOT NULL DEFAULT 0,
    mapped_records_count INT NOT NULL DEFAULT 0,
    unmapped_records_count INT NOT NULL DEFAULT 0,
    total_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    mapped_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    unmapped_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    generated_by BIGINT REFERENCES public.users(user_id),
    generated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    remarks TEXT
);

CREATE INDEX IF NOT EXISTS idx_qr_run_plant_period ON public.quantity_reco_run(plant_id, period_id);
CREATE INDEX IF NOT EXISTS idx_qr_run_status ON public.quantity_reco_run(status);

-- 5. Quantity Reco Details (Normalized Relational Rows)
-- NEVER store as static columns (sales_amount, rmc_amount, etc.)
-- Relationships stored as: source_row -> cost_centre -> amount
CREATE TABLE IF NOT EXISTS public.quantity_reco_detail (
    detail_id BIGSERIAL PRIMARY KEY,
    run_id BIGINT NOT NULL REFERENCES public.quantity_reco_run(run_id) ON DELETE CASCADE,
    source_transaction_id BIGINT REFERENCES public.quantity_reco_source_transactions(source_txn_id) ON DELETE SET NULL,
    source_row_ref VARCHAR(100),
    source_description TEXT,
    source_cost_basis VARCHAR(150),
    cost_centre_id BIGINT NOT NULL REFERENCES public.cost_center_master(id) ON DELETE CASCADE,
    amount NUMERIC(18,4) NOT NULL,
    mapping_id BIGINT REFERENCES public.cost_basis_mapping(mapping_id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_qrd_run ON public.quantity_reco_detail(run_id);
CREATE INDEX IF NOT EXISTS idx_qrd_cost_centre ON public.quantity_reco_detail(cost_centre_id);
CREATE INDEX IF NOT EXISTS idx_qrd_source_txn ON public.quantity_reco_detail(source_transaction_id);

-- 6. Quantity Reco Exceptions / Unmapped Records
CREATE TABLE IF NOT EXISTS public.quantity_reco_exception (
    exception_id BIGSERIAL PRIMARY KEY,
    run_id BIGINT NOT NULL REFERENCES public.quantity_reco_run(run_id) ON DELETE CASCADE,
    source_transaction_id BIGINT REFERENCES public.quantity_reco_source_transactions(source_txn_id) ON DELETE SET NULL,
    plant_id BIGINT NOT NULL REFERENCES public.plants(plant_id) ON DELETE CASCADE,
    period_id BIGINT REFERENCES public.periods(period_id) ON DELETE CASCADE,
    source_reference VARCHAR(100),
    source_description TEXT,
    cost_basis VARCHAR(150),
    amount NUMERIC(18,4) NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'UNMAPPED',
    resolved_mapping_id BIGINT REFERENCES public.cost_basis_mapping(mapping_id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_qre_run ON public.quantity_reco_exception(run_id);
CREATE INDEX IF NOT EXISTS idx_qre_plant_period ON public.quantity_reco_exception(plant_id, period_id);
CREATE INDEX IF NOT EXISTS idx_qre_status ON public.quantity_reco_exception(status);

-- 7. Add Menu Entry for Quantity Reco in menus table if not exists
INSERT INTO public.menus (menu_code, menu_name, route, icon, display_order, menu_type, status)
VALUES ('QUANTITY_RECO', 'Quantity Reco', 'quantity-reco.php', 'calculator', 22, 'Menu', 'Active')
ON CONFLICT (menu_code) DO UPDATE SET
    menu_name = EXCLUDED.menu_name,
    route = EXCLUDED.route,
    icon = EXCLUDED.icon,
    status = 'Active';
