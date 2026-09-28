-- COST360 GLOBAL DB - SAMPLE INSERT DATA
-- Run after 01_cost360_global_db.sql
-- Deterministic IDs are used so all sample relationships are easy to verify.

BEGIN;

-- 1. CATEGORIES
INSERT INTO categories (category_id, category_code, category_name, description, status)
VALUES
(1,'PLAT_ADMIN','Platform Administrator','Manages the complete Cost360 platform','Active'),
(2,'CMA_ADMIN','CMA / Cost Administrator','Manages costing, validation, reconciliation and approvals','Active'),
(3,'CUST_MGMT','Customer Management','Management-level customer and costing visibility','Active'),
(4,'CUST_ADMIN','Customer Admin','Manages users, masters and customer data','Active'),
(5,'AUDITOR','Auditor / Reviewer','Read-only review and audit access','Active')
ON CONFLICT (category_code) DO NOTHING;

-- 2. MENUS
-- Parent rows first because menus.parent_menu_id references menus.menu_id.
INSERT INTO menus
(menu_id,parent_menu_id,menu_code,menu_name,route,icon,display_order,menu_type,status)
VALUES
(1,NULL,'DASHBOARD','Dashboard','/dashboard','dashboard',1,'Module','Active'),
(2,NULL,'ADMIN','Administration','/administration','settings',2,'Module','Active'),
(3,2,'USER_MASTER','User Master','/administration/users','people',1,'Menu','Active'),
(4,2,'ROLE_MASTER','Role Master','/administration/roles','admin_panel_settings',2,'Menu','Active'),
(5,2,'MENU_MASTER','Menu Master','/administration/menus','menu',3,'Menu','Active'),
(6,2,'PERMISSION_MASTER','Permission Master','/administration/permissions','security',4,'Menu','Active'),
(7,NULL,'CUSTOMER_MGMT','Customer Management','/customers','business',3,'Module','Active'),
(8,7,'CUSTOMER_MASTER','Customer Master','/customers/master','domain',1,'Menu','Active'),
(9,7,'COMPANY_MASTER','Company Master','/customers/companies','corporate_fare',2,'Menu','Active'),
(10,7,'PLANT_MASTER','Plant Master','/customers/plants','factory',3,'Menu','Active'),
(11,7,'WAREHOUSE_MASTER','Warehouse Master','/customers/warehouses','warehouse',4,'Menu','Active'),
(12,NULL,'DATA_MANAGEMENT','Data Management','/data','cloud_upload',4,'Module','Active'),
(13,12,'UPLOAD_CENTER','Upload Center','/data/upload','upload_file',1,'Menu','Active'),
(14,12,'MAPPING','Mapping','/data/mapping','account_tree',2,'Menu','Active'),
(15,12,'VALIDATION','Validation','/data/validation','fact_check',3,'Menu','Active'),
(16,NULL,'COSTING','Costing','/costing','calculate',5,'Module','Active'),
(17,16,'COSTING_WORKBENCH','Costing Workbench','/costing/workbench','workspaces',1,'Menu','Active'),
(18,16,'INVENTORY_VALUATION','Inventory Valuation','/costing/inventory','inventory_2',2,'Menu','Active'),
(19,16,'BOM','BOM','/costing/bom','account_tree',3,'Menu','Active'),
(20,16,'STANDARD_COST','Standard Cost','/costing/standard-cost','price_check',4,'Menu','Active'),
(21,NULL,'AUDIT','Audit & Reconciliation','/audit','fact_check',6,'Module','Active'),
(22,21,'RECONCILIATION','Reconciliation','/audit/reconciliation','sync_alt',1,'Menu','Active'),
(23,21,'AUDIT_DESK','Audit Desk','/audit/desk','rule',2,'Menu','Active'),
(24,21,'AUDIT_TRAIL','Audit Trail','/audit/trail','history',3,'Menu','Active'),
(25,NULL,'REPORTS','Reports','/reports','assessment',7,'Module','Active'),
(26,NULL,'SETTINGS','Settings','/settings','tune',8,'Module','Active');

-- 3. ROLES
INSERT INTO roles
(role_id,category_id,role_code,role_name,description,status)
VALUES
(1,1,'PLATFORM_ADMIN','Platform Administrator','Full platform administration access','Active'),
(2,2,'CMA_ADMIN','CMA / Cost Administrator','Costing and customer operational administration','Active'),
(3,3,'CUSTOMER_MANAGER','Customer Management User','Management dashboards and reports','Active'),
(4,4,'CUSTOMER_ADMIN','Customer Administrator','Customer-level administration and data management','Active'),
(5,5,'AUDITOR','Auditor / Reviewer','Review and audit access','Active');

-- 4. ROLE_MENU
INSERT INTO role_menu
(role_menu_id,role_id,menu_id,can_view,can_create,can_edit,can_delete,can_upload,can_download,can_approve,can_export,can_print,can_configure,is_active)
VALUES
-- Platform Administrator
(1,1,1,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(2,1,2,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(3,1,3,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(4,1,4,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(5,1,5,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(6,1,6,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(7,1,7,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(8,1,12,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE),
(9,1,16,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE),
(10,1,21,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE),
(11,1,25,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(12,1,26,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),

-- CMA Administrator
(13,2,1,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(14,2,7,TRUE,FALSE,TRUE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(15,2,12,TRUE,TRUE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE),
(16,2,16,TRUE,TRUE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE,TRUE),
(17,2,21,TRUE,FALSE,TRUE,FALSE,FALSE,TRUE,TRUE,TRUE,TRUE,FALSE,TRUE),
(18,2,25,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),

-- Customer Management
(19,3,1,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(20,3,7,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(21,3,16,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(22,3,25,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),

-- Customer Administrator
(23,4,1,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(24,4,3,TRUE,TRUE,TRUE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(25,4,8,TRUE,TRUE,TRUE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(26,4,9,TRUE,TRUE,TRUE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(27,4,10,TRUE,TRUE,TRUE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(28,4,11,TRUE,TRUE,TRUE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(29,4,12,TRUE,TRUE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE,TRUE,TRUE,TRUE),
(30,4,16,TRUE,FALSE,TRUE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(31,4,25,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),

-- Auditor
(32,5,1,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(33,5,16,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(34,5,21,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(35,5,23,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(36,5,24,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE),
(37,5,25,TRUE,FALSE,FALSE,FALSE,FALSE,TRUE,FALSE,TRUE,TRUE,FALSE,TRUE);

-- 5. USERS
-- created_by is populated after all users exist because it is a self-reference.
INSERT INTO users
(user_id,category_id,full_name,email,phone,password_hash,is_active,created_by)
VALUES
(1,1,'System Administrator','admin@cost360.com','9876500001','DEMO_HASH_PLATFORM_ADMIN',TRUE,NULL),
(2,2,'Aarav Sharma','aarav.cma@cost360.com','9876500002','DEMO_HASH_CMA_ADMIN',TRUE,1),
(3,3,'Meera Kulkarni','meera.management@cost360.com','9876500003','DEMO_HASH_MANAGER',TRUE,1),
(4,4,'Rohan Patil','rohan.admin@cost360.com','9876500004','DEMO_HASH_CUSTOMER_ADMIN',TRUE,2),
(5,5,'Sneha Deshmukh','sneha.auditor@cost360.com','9876500005','DEMO_HASH_AUDITOR',TRUE,2),
(6,4,'Neha Joshi','neha.customer@cost360.com','9876500006','DEMO_HASH_CUSTOMER_USER',TRUE,4);

-- 6. LOGIN_HISTORY
INSERT INTO login_history
(login_id,user_id,login_time,ip_address,status,failure_reason)
VALUES
(1,1,'2026-09-24 08:55:00','192.168.1.10','Success',NULL),
(2,2,'2026-09-24 09:10:00','192.168.1.11','Success',NULL),
(3,4,'2026-09-24 09:20:00','192.168.1.12','Success',NULL),
(4,5,'2026-09-24 09:30:00','192.168.1.13','Failed','Invalid password'),
(5,5,'2026-09-24 09:32:00','192.168.1.13','Success',NULL);

-- 7. AUDIT_LOG
INSERT INTO audit_log
(audit_id,user_id,entity_name,entity_id,action,old_value,new_value,timestamp,ip_address)
VALUES
(1,1,'roles',5,'INSERT',NULL,'Role Auditor created','2026-09-24 09:00:00','192.168.1.10'),
(2,1,'users',4,'INSERT',NULL,'Customer Administrator created','2026-09-24 09:05:00','192.168.1.10'),
(3,4,'users',6,'INSERT',NULL,'Customer user created','2026-09-24 09:22:00','192.168.1.12'),
(4,2,'menus',17,'UPDATE','Inactive','Active','2026-09-24 09:25:00','192.168.1.11'),
(5,2,'role_menu',16,'INSERT',NULL,'CMA access granted to Costing Workbench','2026-09-24 09:27:00','192.168.1.11');

-- 8. USER_ROLES
INSERT INTO user_roles
(user_role_id,user_id,role_id,assigned_at,assigned_by,status)
VALUES
(1,1,1,'2026-09-24 08:50:00',1,'Active'),
(2,2,2,'2026-09-24 08:55:00',1,'Active'),
(3,3,3,'2026-09-24 08:56:00',1,'Active'),
(4,4,4,'2026-09-24 09:00:00',2,'Active'),
(5,5,5,'2026-09-24 09:01:00',2,'Active'),
(6,6,4,'2026-09-24 09:23:00',4,'Active');

-- 9. CUSTOMERS
INSERT INTO customers
(customer_id,customer_code,customer_name,address,contact_person,contact_email,contact_phone,status)
VALUES
(1,'CUST001','ABC Manufacturing Pvt Ltd','MIDC Ambad, Nashik, Maharashtra','Rajesh Mehta','rajesh.mehta@abcmfg.example','9876510001','Active'),
(2,'CUST002','Precision Components India Ltd','Chakan Industrial Area, Pune, Maharashtra','Priya Shah','priya.shah@precision.example','9876510002','Active');

-- 10. COMPANIES
INSERT INTO companies
(company_id,customer_id,company_code,company_name,address,status)
VALUES
(1,1,'ABC01','ABC Manufacturing Pvt Ltd','Ambad MIDC, Nashik','Active'),
(2,1,'ABC02','ABC Components Division','Satpur MIDC, Nashik','Active'),
(3,2,'PCI01','Precision Components India Ltd','Chakan MIDC, Pune','Active');

-- 11. DIVISIONS
INSERT INTO divisions
(division_id,company_id,division_code,division_name,status)
VALUES
(1,1,'MFG','Manufacturing Division','Active'),
(2,1,'RND','Research & Development','Active'),
(3,2,'MFG','Components Manufacturing','Active'),
(4,3,'MFG','Precision Manufacturing','Active');

-- 12. PLANTS
INSERT INTO plants
(plant_id,division_id,plant_code,plant_name,location,status)
VALUES
(1,1,'NASH01','Nashik Manufacturing Plant','Ambad MIDC, Nashik','Active'),
(2,3,'NASH02','Nashik Components Plant','Satpur MIDC, Nashik','Active'),
(3,4,'PUNE01','Pune Precision Plant','Chakan MIDC, Pune','Active');

-- 13. WAREHOUSES
INSERT INTO warehouses
(warehouse_id,plant_id,warehouse_code,warehouse_name,status)
VALUES
(1,1,'RM01','Raw Material Warehouse','Active'),
(2,1,'FG01','Finished Goods Warehouse','Active'),
(3,2,'RM02','Components Raw Material Warehouse','Active'),
(4,3,'RM03','Precision Raw Material Warehouse','Active'),
(5,3,'FG02','Precision Finished Goods Warehouse','Active');

-- 14. USER_ORGANIZATION_ACCESS
INSERT INTO user_organization_access
(access_id,user_id,customer_id,company_id,division_id,plant_id,warehouse_id,valid_from,valid_to,status)
VALUES
(1,2,1,NULL,NULL,NULL,NULL,'2026-01-01',NULL,'Active'), -- CMA: customer-wide
(2,3,1,NULL,NULL,NULL,NULL,'2026-01-01',NULL,'Active'), -- Management: customer-wide
(3,4,1,1,1,1,NULL,'2026-01-01',NULL,'Active'),         -- Customer admin: company/plant
(4,6,1,1,1,1,1,'2026-01-01',NULL,'Active'),            -- Customer user: warehouse scope
(5,5,1,1,1,1,NULL,'2026-01-01',NULL,'Active');          -- Auditor: plant scope

-- Keep BIGSERIAL sequences ahead of explicit sample IDs.
SELECT setval(pg_get_serial_sequence('categories','category_id'), COALESCE((SELECT MAX(category_id) FROM categories),1), true);
SELECT setval(pg_get_serial_sequence('menus','menu_id'), COALESCE((SELECT MAX(menu_id) FROM menus),1), true);
SELECT setval(pg_get_serial_sequence('roles','role_id'), COALESCE((SELECT MAX(role_id) FROM roles),1), true);
SELECT setval(pg_get_serial_sequence('role_menu','role_menu_id'), COALESCE((SELECT MAX(role_menu_id) FROM role_menu),1), true);
SELECT setval(pg_get_serial_sequence('users','user_id'), COALESCE((SELECT MAX(user_id) FROM users),1), true);
SELECT setval(pg_get_serial_sequence('login_history','login_id'), COALESCE((SELECT MAX(login_id) FROM login_history),1), true);
SELECT setval(pg_get_serial_sequence('audit_log','audit_id'), COALESCE((SELECT MAX(audit_id) FROM audit_log),1), true);
SELECT setval(pg_get_serial_sequence('user_roles','user_role_id'), COALESCE((SELECT MAX(user_role_id) FROM user_roles),1), true);
SELECT setval(pg_get_serial_sequence('customers','customer_id'), COALESCE((SELECT MAX(customer_id) FROM customers),1), true);
SELECT setval(pg_get_serial_sequence('companies','company_id'), COALESCE((SELECT MAX(company_id) FROM companies),1), true);
SELECT setval(pg_get_serial_sequence('divisions','division_id'), COALESCE((SELECT MAX(division_id) FROM divisions),1), true);
SELECT setval(pg_get_serial_sequence('plants','plant_id'), COALESCE((SELECT MAX(plant_id) FROM plants),1), true);
SELECT setval(pg_get_serial_sequence('warehouses','warehouse_id'), COALESCE((SELECT MAX(warehouse_id) FROM warehouses),1), true);

COMMIT;
