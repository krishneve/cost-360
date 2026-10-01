-- ============================================================
-- Cost360 Migration: User Access Scope Hardening
-- Run this ONCE against your live Neon PostgreSQL database.
-- ============================================================

-- 1. Add customer_id to users for direct tenant FK (strict tenant isolation)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS customer_id BIGINT REFERENCES customers(customer_id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_users_customer_id ON users(customer_id);

-- Backfill existing users from their active user_organization_access records
UPDATE users u
SET customer_id = subq.customer_id
FROM (
    SELECT DISTINCT ON (user_id) user_id, customer_id
    FROM user_organization_access
    WHERE customer_id IS NOT NULL
      AND UPPER(status) = 'ACTIVE'
    ORDER BY user_id, access_id ASC
) subq
WHERE u.user_id = subq.user_id
  AND u.customer_id IS NULL;

-- 2. Add period_id to user_organization_access for explicit period-level access grants
ALTER TABLE user_organization_access
    ADD COLUMN IF NOT EXISTS period_id BIGINT REFERENCES periods(period_id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_uoa_period_id ON user_organization_access(period_id);

-- Done.
