-- ============================================================================
-- Migration 003: Map All 5 Material Transaction Types to 06.MATERIAL TRANSACTION
-- (Purchase, Consumption, Production, Issue, Scrap Sales) -> public.material_transaction
-- ============================================================================

-- 1. Ensure target_entity for material_transaction exists (id 32)
INSERT INTO public.target_entities(id, entity_name, table_name, description, status)
VALUES(32, 'material_transaction', 'material_transaction', 'Material and Inventory Transactions', 'Active')
ON CONFLICT (id) DO UPDATE SET table_name = 'material_transaction', status = 'Active';

-- 2. Ensure Master Group for Material Transaction exists
INSERT INTO public.master_groups (id, name, code, description, status)
VALUES (28, 'Material Transaction', 'MATERIAL_TRANSACTION', 'Material and Stock Ledger Transactions', 'Active')
ON CONFLICT (id) DO UPDATE SET name = 'Material Transaction', code = 'MATERIAL_TRANSACTION', status = 'Active';

-- 4. Map existing transaction sub-categories to material_transaction
UPDATE public.sub_categories
SET target_entity_id = 32
WHERE code IN ('PURCHASE_TXN', 'CONSUMPTION_TXN', 'PRODUCTION_TXN', '06_MATERIAL_TRANSACTION');

-- 5. Register all 5 distinct transaction sub-categories under Master Group 28 (Material Transaction)
INSERT INTO public.sub_categories (id, master_group_id, name, code, target_entity_id, status)
VALUES (32, 28, 'Material Transaction', '06_MATERIAL_TRANSACTION', 32, 'Active')
ON CONFLICT (id) DO UPDATE SET master_group_id = 28, target_entity_id = 32, status = 'Active';

INSERT INTO public.sub_categories (master_group_id, name, code, target_entity_id, status)
SELECT 28, 'Purchase Transaction', '06_PURCHASE_TXN', 32, 'Active'
WHERE NOT EXISTS (SELECT 1 FROM public.sub_categories WHERE code = '06_PURCHASE_TXN');

INSERT INTO public.sub_categories (master_group_id, name, code, target_entity_id, status)
SELECT 28, 'Consumption Transaction', '06_CONSUMPTION_TXN', 32, 'Active'
WHERE NOT EXISTS (SELECT 1 FROM public.sub_categories WHERE code = '06_CONSUMPTION_TXN');

INSERT INTO public.sub_categories (master_group_id, name, code, target_entity_id, status)
SELECT 28, 'Production Transaction', '06_PRODUCTION_TXN', 32, 'Active'
WHERE NOT EXISTS (SELECT 1 FROM public.sub_categories WHERE code = '06_PRODUCTION_TXN');

INSERT INTO public.sub_categories (master_group_id, name, code, target_entity_id, status)
SELECT 28, 'Issue Transaction', '06_ISSUE_TXN', 32, 'Active'
WHERE NOT EXISTS (SELECT 1 FROM public.sub_categories WHERE code = '06_ISSUE_TXN');

INSERT INTO public.sub_categories (master_group_id, name, code, target_entity_id, status)
SELECT 28, 'Scrap Sales Transaction', '06_SCRAP_SALES_TXN', 32, 'Active'
WHERE NOT EXISTS (SELECT 1 FROM public.sub_categories WHERE code = '06_SCRAP_SALES_TXN');
