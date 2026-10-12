-- =====================================================================
-- COST360 UPLOAD CENTRE MIGRATION: 002_upload_centre_finalized_excel.sql
-- Registers all 11 finalized data categories from EXCEL For Upload center (1).xlsx
-- Safely adds sheet tracking and file hash to uploads / upload_files.
-- =====================================================================

-- 1. Add sheet_name, sheet_index to upload_files if missing
ALTER TABLE public.upload_files ADD COLUMN IF NOT EXISTS sheet_name VARCHAR(150);
ALTER TABLE public.upload_files ADD COLUMN IF NOT EXISTS sheet_index INT DEFAULT 0;

-- 2. Add file_hash to uploads for deduplication tracking
ALTER TABLE public.uploads ADD COLUMN IF NOT EXISTS file_hash VARCHAR(64);

-- 3. Register target_entities for all 11 finalized categories
INSERT INTO public.target_entities(entity_name, table_name, description) VALUES
('item_master_product_master', 'items', 'Item Master and Product Master combined'),
('work_center_master', 'work_centers', 'Work Center and Machine Master'),
('party_master', 'party_master', 'Supplier and Customer Party Master'),
('cost_center_master', 'cost_center_master', 'Cost Center Master'),
('standard_cost', 'standard_cost', 'Standard Costing Master'),
('bom', 'bom', 'Bill of Materials (BOM)'),
('routing', 'routing', 'Manufacturing Routing and Operations'),
('material_transaction', 'material_transaction', 'Material and Inventory Transactions'),
('downtime', 'downtime', 'Machine Downtime and Breakdown Logs'),
('finance_transaction', 'finance_transaction', 'Finance and Overhead Transactions'),
('trial_balance', 'trial_balance', 'Trial Balance General Ledger Balances')
ON CONFLICT (table_name) DO UPDATE SET
    entity_name = EXCLUDED.entity_name,
    description = EXCLUDED.description;

-- 4. Register master_groups for all 11 finalized categories
INSERT INTO public.master_groups(name, code, description) VALUES
('Item Master & Product Master', 'ITEM_PRODUCT_MASTER', 'Item and product master definitions'),
('Work Center Master', 'WORK_CENTER_MASTER', 'Work center and machine master definitions'),
('Party Master', 'PARTY_MASTER', 'Supplier and customer party accounts'),
('Cost Center Master', 'COST_CENTER_MASTER', 'Cost centers and production shift capacity'),
('Standard Cost', 'STANDARD_COST', 'Standard costing rates and revision data'),
('BOM', 'BOM', 'Bill of materials and component quantities'),
('Routing', 'ROUTING', 'Standard machine hours, labour hours and conversion rates'),
('Material Transaction', 'MATERIAL_TRANSACTION', 'Material issue, receipt and inventory transactions'),
('Downtime', 'DOWNTIME', 'Machine breakdown, run hours and downtime logs'),
('Finance Transaction', 'FINANCE_TRANSACTION', 'Finance overhead expenses and ledger entries'),
('Trial Balance', 'TRIAL_BALANCE', 'Trial balance account codes and debit/credit balances')
ON CONFLICT (code) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description;

-- 5. Link sub_categories to target_entities
WITH mapping(mg_code, sc_name, sc_code, target_table) AS (VALUES
('ITEM_PRODUCT_MASTER', 'Item Master Product Master', '01_ITEM_PRODUCT_MASTER', 'items'),
('WORK_CENTER_MASTER', 'Work Center Master', '02_WORK_CENTER_MASTER', 'work_centers'),
('PARTY_MASTER', 'Party Master', '03_PARTY_MASTER', 'party_master'),
('COST_CENTER_MASTER', 'Cost Center Master', '04_COST_CENTER_MASTER', 'cost_center_master'),
('STANDARD_COST', 'Standard Cost', '05_STANDARD_COST', 'standard_cost'),
('BOM', 'Bill of Materials', '05A_BOM', 'bom'),
('ROUTING', 'Routing', '05B_ROUTING', 'routing'),
('MATERIAL_TRANSACTION', 'Material Transaction', '06_MATERIAL_TRANSACTION', 'material_transaction'),
('DOWNTIME', 'Downtime', '07_DOWNTIME', 'downtime'),
('FINANCE_TRANSACTION', 'Finance Transaction', '08_FINANCE_TRANSACTION', 'finance_transaction'),
('TRIAL_BALANCE', 'Trial Balance', '09_TRIAL_BALANCE', 'trial_balance')
)
INSERT INTO public.sub_categories(master_group_id, name, code, target_entity_id, status)
SELECT mg.id, m.sc_name, m.sc_code, te.id, 'Active'
FROM mapping m
JOIN public.master_groups mg ON mg.code = m.mg_code
JOIN public.target_entities te ON te.table_name = m.target_table
ON CONFLICT (master_group_id, code) DO UPDATE SET
    name = EXCLUDED.name,
    target_entity_id = EXCLUDED.target_entity_id;
