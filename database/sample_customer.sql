-- COST360 CUSTOMER DB - SAMPLE INSERT DATA
-- Run after 02_cost360_customer_db.sql
-- These IDs intentionally align with the sample global warehouse IDs.
BEGIN;

-- 1. UOMS
INSERT INTO uoms (uom_id,uom_code,uom_name,description,status)
VALUES
(1,'PCS','Pieces','Individual pieces','Active'),
(2,'KG','Kilogram','Weight in kilograms','Active'),
(3,'LTR','Litre','Volume in litres','Active'),
(4,'MTR','Metre','Length in metres','Active'),
(5,'BOX','Box','Packaging unit; 1 BOX = 10 PCS for this sample','Active');

-- 2. PERIODS
INSERT INTO periods
(period_id,period_name,start_date,end_date,is_locked,status)
VALUES
(1,'July 2026','2026-07-01','2026-07-31',TRUE,'Active'),
(2,'August 2026','2026-08-01','2026-08-31',TRUE,'Active'),
(3,'September 2026','2026-09-01','2026-09-30',FALSE,'Active');

-- 3. ITEMS
INSERT INTO items
(item_id,item_code,item_name,item_type,uom_id,status)
VALUES
(1,'RM-STEEL-001','Steel Sheet 2mm','Raw Material',2,'Active'),
(2,'RM-COPPER-001','Copper Wire','Raw Material',2,'Active'),
(3,'RM-PAINT-001','Industrial Paint','Consumable',3,'Active'),
(4,'RM-BOLT-001','Hex Bolt M8','Raw Material',1,'Active'),
(5,'SFG-BODY-001','Motor Housing Subassembly','Semi-Finished',1,'Active'),
(6,'FG-MOTOR-001','Industrial Motor 5HP','Finished Good',1,'Active'),
(7,'RM-GREASE-001','Industrial Grease','Consumable',2,'Active'),
(8,'FG-PANEL-001','Control Panel Assembly','Finished Good',1,'Active');

-- 4. BOM_HEADERS
INSERT INTO bom_headers
(bom_id,item_id,revision_no,effective_from,effective_to,status)
VALUES
(1,5,'1','2026-01-01',NULL,'Active'),
(2,6,'1','2026-01-01',NULL,'Active'),
(3,8,'1','2026-01-01',NULL,'Active');

-- 5. BOM_DETAILS
INSERT INTO bom_details
(bom_detail_id,bom_id,component_item_id,quantity,uom_id,status)
VALUES
(1,1,1,8.5000,2,'Active'), -- 8.5 kg steel -> housing
(2,1,4,12.0000,1,'Active'), -- 12 bolts -> housing
(3,2,5,1.0000,1,'Active'), -- 1 housing -> motor
(4,2,2,2.5000,2,'Active'), -- 2.5 kg copper -> motor
(5,2,7,0.1500,2,'Active'), -- 0.15 kg grease -> motor
(6,3,1,4.0000,2,'Active'), -- 4 kg steel -> panel
(7,3,3,1.2000,3,'Active'); -- 1.2 litre paint -> panel

-- 6. TRANSACTIONS
-- warehouse_id is a logical reference to GLOBAL DB warehouses.
INSERT INTO transactions
(txn_id,item_id,warehouse_id,period_id,txn_date,txn_type,quantity,value,source_upload_id,status)
VALUES
-- July receipts/issues
(1,1,1,1,'2026-07-02','Receipt',1000.0000,78000.00,1001,'Active'),
(2,1,1,1,'2026-07-08','Receipt',500.0000,41000.00,1001,'Active'),
(3,1,1,1,'2026-07-15','Issue',620.0000,49600.00,1002,'Active'),
(4,2,1,1,'2026-07-03','Receipt',400.0000,36000.00,1001,'Active'),
(5,2,1,1,'2026-07-18','Issue',180.0000,16200.00,1002,'Active'),
(6,4,1,1,'2026-07-05','Receipt',5000.0000,25000.00,1001,'Active'),
(7,4,1,1,'2026-07-20','Issue',1200.0000,6000.00,1002,'Active'),
(8,5,1,1,'2026-07-22','Receipt',80.0000,68000.00,1001,'Active'),
(9,5,1,1,'2026-07-25','Issue',50.0000,42500.00,1002,'Active'),
(10,6,2,1,'2026-07-28','Receipt',40.0000,98000.00,1003,'Active'),
-- August
(11,1,1,2,'2026-08-02','Receipt',700.0000,57400.00,1004,'Active'),
(12,1,1,2,'2026-08-10','Issue',450.0000,36900.00,1005,'Active'),
(13,2,1,2,'2026-08-04','Receipt',300.0000,27600.00,1004,'Active'),
(14,2,1,2,'2026-08-16','Issue',120.0000,11040.00,1005,'Active'),
(15,4,1,2,'2026-08-05','Receipt',3000.0000,15300.00,1004,'Active'),
(16,4,1,2,'2026-08-21','Issue',800.0000,4080.00,1005,'Active'),
(17,5,1,2,'2026-08-22','Receipt',60.0000,52200.00,1004,'Active'),
(18,5,1,2,'2026-08-26','Issue',35.0000,30450.00,1005,'Active'),
(19,6,2,2,'2026-08-29','Receipt',35.0000,87500.00,1006,'Active'),
-- September sample
(20,1,1,3,'2026-09-03','Receipt',800.0000,67200.00,1007,'Active'),
(21,1,1,3,'2026-09-10','Issue',300.0000,25200.00,1008,'Active'),
(22,2,1,3,'2026-09-04','Receipt',250.0000,23500.00,1007,'Active'),
(23,4,1,3,'2026-09-05','Receipt',2500.0000,13000.00,1007,'Active'),
(24,5,1,3,'2026-09-20','Issue',20.0000,17800.00,1008,'Active'),
(25,6,2,3,'2026-09-21','Receipt',25.0000,63750.00,1009,'Active');

-- 7. STANDARD_COST
INSERT INTO standard_cost
(cost_id,item_id,cost_component,cost_value,effective_from,effective_to,status)
VALUES
(1,1,'Material',80.0000,'2026-01-01',NULL,'Active'),
(2,2,'Material',95.0000,'2026-01-01',NULL,'Active'),
(3,3,'Material',210.0000,'2026-01-01',NULL,'Active'),
(4,4,'Material',5.2000,'2026-01-01',NULL,'Active'),
(5,5,'Material',850.0000,'2026-01-01',NULL,'Active'),
(6,5,'Labour',120.0000,'2026-01-01',NULL,'Active'),
(7,5,'Overhead',90.0000,'2026-01-01',NULL,'Active'),
(8,6,'Material',1290.0000,'2026-01-01',NULL,'Active'),
(9,6,'Labour',260.0000,'2026-01-01',NULL,'Active'),
(10,6,'Overhead',180.0000,'2026-01-01',NULL,'Active'),
(11,6,'Process/Machine',140.0000,'2026-01-01',NULL,'Active'),
(12,7,'Material',320.0000,'2026-01-01',NULL,'Active'),
(13,8,'Material',720.0000,'2026-01-01',NULL,'Active'),
(14,8,'Labour',160.0000,'2026-01-01',NULL,'Active'),
(15,8,'Overhead',110.0000,'2026-01-01',NULL,'Active');

-- Keep BIGSERIAL sequences ahead of explicit sample IDs.
SELECT setval(pg_get_serial_sequence('uoms','uom_id'), COALESCE((SELECT MAX(uom_id) FROM uoms),1), true);
SELECT setval(pg_get_serial_sequence('periods','period_id'), COALESCE((SELECT MAX(period_id) FROM periods),1), true);
SELECT setval(pg_get_serial_sequence('items','item_id'), COALESCE((SELECT MAX(item_id) FROM items),1), true);
SELECT setval(pg_get_serial_sequence('bom_headers','bom_id'), COALESCE((SELECT MAX(bom_id) FROM bom_headers),1), true);
SELECT setval(pg_get_serial_sequence('bom_details','bom_detail_id'), COALESCE((SELECT MAX(bom_detail_id) FROM bom_details),1), true);
SELECT setval(pg_get_serial_sequence('transactions','txn_id'), COALESCE((SELECT MAX(txn_id) FROM transactions),1), true);
SELECT setval(pg_get_serial_sequence('standard_cost','cost_id'), COALESCE((SELECT MAX(cost_id) FROM standard_cost),1), true);

COMMIT;
