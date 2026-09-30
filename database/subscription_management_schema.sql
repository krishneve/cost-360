-- =====================================================================
-- COST360 — SUBSCRIPTION MANAGEMENT SCHEMA
-- Global/Central Database Foundation for Subscription Plans & Customer Subscriptions
-- =====================================================================

-- 1. SUBSCRIPTION PLANS TABLE
-- Stores available platform subscription tiers and extensible limits
CREATE TABLE IF NOT EXISTS subscription_plans (
    plan_id             BIGSERIAL PRIMARY KEY,
    plan_code           VARCHAR(50) NOT NULL UNIQUE,
    plan_name           VARCHAR(100) NOT NULL,
    description         TEXT,
    plan_type           VARCHAR(50) NOT NULL DEFAULT 'COMMERCIAL'
                        CHECK (plan_type IN ('FREE', 'TRIAL', 'COMMERCIAL', 'ENTERPRISE', 'CUSTOM')),
    price               DECIMAL(12, 2) NOT NULL DEFAULT 0.00 CHECK (price >= 0),
    currency            VARCHAR(10) NOT NULL DEFAULT 'INR',
    billing_cycle       VARCHAR(30) NOT NULL DEFAULT 'MONTHLY'
                        CHECK (billing_cycle IN ('MONTHLY', 'QUARTERLY', 'HALF_YEARLY', 'ANNUAL', 'LIFETIME', 'CUSTOM')),
    max_users           INT NOT NULL DEFAULT 0 CHECK (max_users >= 0),
    limits_config       JSONB NOT NULL DEFAULT '{}'::jsonb,
    status              VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
                        CHECK (status IN ('ACTIVE', 'INACTIVE', 'Active', 'Inactive')),
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by          BIGINT REFERENCES users(user_id) ON DELETE SET NULL
);

COMMENT ON TABLE subscription_plans IS 'Master list of platform subscription plans with user thresholds and extensible JSONB limits.';
COMMENT ON COLUMN subscription_plans.plan_code IS 'Unique business identifier for the plan (e.g. FREE, STANDARD, ENTERPRISE).';
COMMENT ON COLUMN subscription_plans.max_users IS 'Maximum users permitted at the plan level (0 indicates free/starter or custom definition).';
COMMENT ON COLUMN subscription_plans.limits_config IS 'Extensible JSONB object storing additional limits (e.g. max_companies, max_plants, storage_mb, features) without DDL alteration.';

-- Indexes on subscription_plans
CREATE INDEX IF NOT EXISTS idx_subscription_plans_code ON subscription_plans(plan_code);
CREATE INDEX IF NOT EXISTS idx_subscription_plans_status ON subscription_plans(status);
CREATE INDEX IF NOT EXISTS idx_subscription_plans_type ON subscription_plans(plan_type);


-- 2. CUSTOMER SUBSCRIPTIONS TABLE
-- Tracks historical and active subscriptions assigned to each customer
CREATE TABLE IF NOT EXISTS customer_subscriptions (
    subscription_id     BIGSERIAL PRIMARY KEY,
    customer_id         BIGINT NOT NULL REFERENCES customers(customer_id) ON DELETE RESTRICT,
    plan_id             BIGINT NOT NULL REFERENCES subscription_plans(plan_id) ON DELETE RESTRICT,
    start_date          DATE NOT NULL DEFAULT CURRENT_DATE,
    end_date            DATE,
    status              VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
                        CHECK (status IN ('ACTIVE', 'EXPIRED', 'CANCELLED', 'SUSPENDED', 'Active', 'Expired', 'Cancelled', 'Suspended')),
    assigned_by         BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    assigned_date       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes               TEXT,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by          BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_customer_sub_dates CHECK (end_date IS NULL OR end_date >= start_date)
);

COMMENT ON TABLE customer_subscriptions IS 'Customer subscription assignments and history. Enforces one active subscription per customer.';
COMMENT ON COLUMN customer_subscriptions.customer_id IS 'FK to customers table (restricted on delete to preserve audit history).';
COMMENT ON COLUMN customer_subscriptions.plan_id IS 'FK to subscription_plans table (restricted on delete).';

-- Indexes on customer_subscriptions
CREATE INDEX IF NOT EXISTS idx_customer_subscriptions_customer ON customer_subscriptions(customer_id);
CREATE INDEX IF NOT EXISTS idx_customer_subscriptions_plan ON customer_subscriptions(plan_id);
CREATE INDEX IF NOT EXISTS idx_customer_subscriptions_status ON customer_subscriptions(status);
CREATE INDEX IF NOT EXISTS idx_customer_subscriptions_dates ON customer_subscriptions(start_date, end_date);

-- CRITICAL BUSINESS RULE CONSTRAINT:
-- Enforce that a customer can have ONLY ONE active subscription at any time.
CREATE UNIQUE INDEX IF NOT EXISTS idx_customer_single_active_sub 
ON customer_subscriptions (customer_id) 
WHERE UPPER(status) = 'ACTIVE';


-- 3. SEED DEFAULT PREDEFINED PLANS
-- Predefined FREE plan: max_users = 0, status = ACTIVE
INSERT INTO subscription_plans (
    plan_code,
    plan_name,
    description,
    plan_type,
    price,
    currency,
    billing_cycle,
    max_users,
    limits_config,
    status
) VALUES 
(
    'FREE',
    'Free Starter Plan',
    'Default free platform subscription with baseline access and 0 configured users',
    'FREE',
    0.00,
    'INR',
    'LIFETIME',
    0,
    '{"max_companies": 1, "max_plants": 1, "max_warehouses": 1, "storage_mb": 500, "features": ["basic_costing", "user_management"]}'::jsonb,
    'ACTIVE'
),
(
    'STANDARD',
    'Standard Plan',
    'Standard tier for growing manufacturing businesses up to 15 users',
    'COMMERCIAL',
    4999.00,
    'INR',
    'MONTHLY',
    15,
    '{"max_companies": 3, "max_plants": 5, "max_warehouses": 10, "storage_mb": 5000, "features": ["advanced_costing", "bom_management", "analytics"]}'::jsonb,
    'ACTIVE'
),
(
    'PROFESSIONAL',
    'Professional Plan',
    'Professional tier for multi-plant manufacturing operations up to 50 users',
    'COMMERCIAL',
    12999.00,
    'INR',
    'MONTHLY',
    50,
    '{"max_companies": 10, "max_plants": 20, "max_warehouses": 50, "storage_mb": 25000, "features": ["all_modules", "priority_support", "audit_trail"]}'::jsonb,
    'ACTIVE'
),
(
    'ENTERPRISE',
    'Enterprise Plan',
    'Comprehensive enterprise tier with extensive limits up to 200 users',
    'ENTERPRISE',
    39999.00,
    'INR',
    'ANNUAL',
    200,
    '{"max_companies": 50, "max_plants": 100, "max_warehouses": 250, "storage_mb": 100000, "features": ["unlimited_modules", "dedicated_support", "custom_integrations"]}'::jsonb,
    'ACTIVE'
)
ON CONFLICT (plan_code) DO UPDATE SET
    plan_name = EXCLUDED.plan_name,
    description = EXCLUDED.description,
    plan_type = EXCLUDED.plan_type,
    max_users = EXCLUDED.max_users,
    status = EXCLUDED.status,
    limits_config = EXCLUDED.limits_config,
    updated_at = CURRENT_TIMESTAMP;


-- 4. SUBSCRIPTION MANAGEMENT NAVIGATION MENUS & PERMISSIONS
-- Hierarchy: Administration (menu_id=2) -> Subscription Management -> Subscription Plans
INSERT INTO menus (menu_id, parent_menu_id, menu_code, menu_name, route, icon, display_order, menu_type, status)
VALUES
(
    39,
    2,
    'SUBSCRIPTION_MGMT',
    'Subscription Management',
    'administration/subscriptions',
    'inventory',
    5,
    'Menu',
    'Active'
),
(
    40,
    39,
    'SUBSCRIPTION_PLANS',
    'Subscription Plans',
    'administration/subscriptions/plans',
    'calculator',
    1,
    'Menu',
    'Active'
)
ON CONFLICT (menu_code) DO UPDATE SET
    parent_menu_id = EXCLUDED.parent_menu_id,
    menu_name = EXCLUDED.menu_name,
    route = EXCLUDED.route,
    icon = EXCLUDED.icon,
    status = EXCLUDED.status;

-- Grant permissions for PLATFORM_ADMIN (role_id=1) and CMA_ADMIN (role_id=2)
INSERT INTO role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
SELECT r.role_id, m.menu_id, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE
FROM roles r
CROSS JOIN menus m
WHERE r.role_code IN ('PLATFORM_ADMIN', 'CMA_ADMIN')
  AND m.menu_code IN ('SUBSCRIPTION_MGMT', 'SUBSCRIPTION_PLANS')
ON CONFLICT (role_id, menu_id) DO UPDATE SET
    can_view = TRUE,
    can_create = TRUE,
    can_edit = TRUE,
    can_delete = TRUE,
    is_active = TRUE;

