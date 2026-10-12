-- ==============================================================================
-- MIGRATION 004: Upload Centre Master Data Upsert & Period-Wise Management
-- ==============================================================================

-- 1. Ensure Global DB periods table has October 2026 open period and period_code
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = 'public' AND table_name = 'periods' AND column_name = 'period_code'
    ) THEN
        ALTER TABLE public.periods ADD COLUMN period_code VARCHAR(50);
    END IF;
END $$;

UPDATE public.periods 
SET period_code = TO_CHAR(start_date, 'YYYY-MM') 
WHERE period_code IS NULL OR period_code = '';

-- Ensure October 2026 active/open period exists in Global DB
INSERT INTO public.periods (period_name, period_code, start_date, end_date, is_locked, status)
SELECT 'October 2026', '2026-10', '2026-10-01', '2026-10-31', FALSE, 'Active'
WHERE NOT EXISTS (
    SELECT 1 FROM public.periods WHERE period_code = '2026-10' OR period_name = 'October 2026'
);

-- Ensure September 2026 is unlocked if user wants to test current uploads
UPDATE public.periods SET is_locked = FALSE WHERE period_code = '2026-10';

-- 2. Ensure Uploads table tracks detailed breakdown
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'uploads' AND column_name = 'inserted_rows') THEN
        ALTER TABLE public.uploads ADD COLUMN inserted_rows BIGINT DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'uploads' AND column_name = 'updated_rows') THEN
        ALTER TABLE public.uploads ADD COLUMN updated_rows BIGINT DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'uploads' AND column_name = 'unchanged_rows') THEN
        ALTER TABLE public.uploads ADD COLUMN unchanged_rows BIGINT DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'uploads' AND column_name = 'period_id') THEN
        ALTER TABLE public.uploads ADD COLUMN period_id BIGINT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'uploads' AND column_name = 'period_code') THEN
        ALTER TABLE public.uploads ADD COLUMN period_code VARCHAR(50);
    END IF;
END $$;
