-- =====================================================================
-- COST360 — DEPARTMENT ALLOCATION RULES MIGRATION (006_cost_allocation_rules.sql)
-- Implements configurable multi-Cost Centre allocation rules for Department/Overhead
-- splits (e.g. Salary A/C, Employer PF, Professional Charges).
-- =====================================================================

CREATE TABLE IF NOT EXISTS public.cost_allocation_rules (
    rule_id BIGSERIAL PRIMARY KEY,
    plant_id BIGINT NOT NULL REFERENCES public.plants(plant_id) ON DELETE CASCADE,
    period_id BIGINT REFERENCES public.periods(period_id) ON DELETE CASCADE,
    account_code VARCHAR(100),
    cost_basis VARCHAR(150),
    target_cost_centre_id BIGINT REFERENCES public.cost_center_master(id) ON DELETE CASCADE,
    allocation_method VARCHAR(50) NOT NULL DEFAULT 'RATIO', -- 'RATIO', 'DRIVER_ACCOUNT', 'PERCENTAGE', 'FIXED_AMOUNT'
    allocation_ratio NUMERIC(14,10) NOT NULL DEFAULT 0.0,
    driver_account_code VARCHAR(100),
    effective_from DATE DEFAULT CURRENT_DATE,
    effective_to DATE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES public.users(user_id),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_car_plant_period ON public.cost_allocation_rules(plant_id, period_id);
CREATE INDEX IF NOT EXISTS idx_car_account ON public.cost_allocation_rules(plant_id, account_code);
CREATE INDEX IF NOT EXISTS idx_car_cost_basis ON public.cost_allocation_rules(plant_id, cost_basis);
CREATE INDEX IF NOT EXISTS idx_car_target_cc ON public.cost_allocation_rules(target_cost_centre_id);

-- Prevent duplicate active rules for same plant, period, account and target cost centre
CREATE UNIQUE INDEX IF NOT EXISTS uq_car_plant_period_acc_cc 
ON public.cost_allocation_rules (plant_id, period_id, account_code, target_cost_centre_id) 
WHERE period_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_car_plant_null_period_acc_cc 
ON public.cost_allocation_rules (plant_id, account_code, target_cost_centre_id) 
WHERE period_id IS NULL AND target_cost_centre_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_car_plant_period_acc_driver 
ON public.cost_allocation_rules (plant_id, period_id, account_code) 
WHERE period_id IS NOT NULL AND target_cost_centre_id IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_car_plant_null_period_acc_driver 
ON public.cost_allocation_rules (plant_id, account_code) 
WHERE period_id IS NULL AND target_cost_centre_id IS NULL;
