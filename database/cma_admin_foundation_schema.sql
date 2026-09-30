-- =====================================================================
-- COST360 — CMA ADMIN FOUNDATION SCHEMA & CONFIGURATION
-- =====================================================================

-- 1. ENSURE CMA_USER ROLE EXISTS
INSERT INTO roles (role_code, role_name, description, category_id, status, created_at, updated_at)
VALUES ('CMA_USER', 'CMA User', 'Operational cost management user under customer organization', 2, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (role_code) DO UPDATE SET
    role_name = EXCLUDED.role_name,
    description = EXCLUDED.description,
    status = 'Active',
    updated_at = CURRENT_TIMESTAMP;

-- 2. ACCESS LEVEL DETERMINATION
-- Handled relationally via company_id, division_id, plant_id without requiring DDL alterations.

-- 3. CREATE MENUS FOR CMA MANAGEMENT (UNDER ADMINISTRATION)
-- Menu IDs dynamically managed or sequential above 50
INSERT INTO menus (menu_id, parent_menu_id, menu_code, menu_name, route, icon, display_order, menu_type, status)
VALUES
(
    50,
    2, -- Administration
    'CMA_MGMT',
    'CMA Management',
    'administration/cma',
    'users',
    6,
    'Menu',
    'Active'
),
(
    51,
    50, -- CMA Management
    'CMA_ADMINS',
    'CMA Admins',
    'administration/cma/admins',
    'user-check',
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

-- 4. CREATE MENUS FOR CMA ADMIN EXPERIENCE
INSERT INTO menus (menu_id, parent_menu_id, menu_code, menu_name, route, icon, display_order, menu_type, status)
VALUES
(
    60,
    NULL, -- Root
    'CMA_USER_MGMT',
    'User Management',
    'cma/users',
    'users',
    2,
    'Menu',
    'Active'
),
(
    61,
    60, -- User Management
    'CMA_USER_MASTER',
    'User Master',
    'cma/users/',
    'users',
    1,
    'Menu',
    'Active'
),
(
    70,
    NULL, -- Root
    'CMA_COMPANY_MGMT',
    'Company Management',
    'cma/companies',
    'building',
    3,
    'Menu',
    'Active'
),
(
    71,
    70, -- Company Management
    'CMA_COMPANIES',
    'Companies',
    'organization/companies/',
    'building',
    1,
    'Menu',
    'Active'
),
(
    72,
    70, -- Company Management
    'CMA_DIVISIONS',
    'Divisions',
    'organization/divisions/',
    'git-branch',
    2,
    'Menu',
    'Active'
),
(
    73,
    70, -- Company Management
    'CMA_PLANTS',
    'Plants',
    'organization/plants/',
    'tool',
    3,
    'Menu',
    'Active'
),
(
    80,
    NULL, -- Root
    'CMA_ACCESS_MGMT',
    'Access Management',
    'cma/access',
    'shield',
    4,
    'Menu',
    'Active'
),
(
    81,
    80, -- Access Management
    'CMA_USER_ACCESS',
    'User Access',
    'cma/access/',
    'shield',
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

-- 5. ROLE PERMISSIONS FOR PLATFORM_ADMIN (role_id = 1)
INSERT INTO role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
SELECT 1, m.menu_id, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE
FROM menus m
WHERE m.menu_code IN ('CMA_MGMT', 'CMA_ADMINS')
ON CONFLICT (role_id, menu_id) DO UPDATE SET
    can_view = TRUE, can_create = TRUE, can_edit = TRUE, can_delete = TRUE, is_active = TRUE;

-- 6. ROLE PERMISSIONS FOR CMA_ADMIN (role_id = 2)
-- Revoke/disable view on platform admin-only modules for role_id = 2
UPDATE role_menu rm
SET can_view = FALSE, is_active = FALSE
FROM menus m
WHERE rm.menu_id = m.menu_id
  AND rm.role_id = 2
  AND m.menu_code IN (
      'ADMIN', 'USER_MASTER', 'ROLE_MASTER', 'MENU_MASTER', 'PERMISSION_MASTER',
      'CUSTOMER_MGMT', 'CUSTOMER_MASTER', 'SUBSCRIPTION_MGMT', 'SUBSCRIPTION_PLANS', 'CUSTOMER_SUBSCRIPTIONS',
      'ORG_OVERVIEW', 'ORG_WAREHOUSES', 'ORG_PERIODS', 'CMA_MGMT', 'CMA_ADMINS',
      'DATA_MANAGEMENT', 'UPLOAD_CENTER', 'MAPPING', 'VALIDATION',
      'COSTING', 'COSTING_WORKBENCH', 'INVENTORY_VALUATION', 'BOM', 'STANDARD_COST',
      'AUDIT', 'RECONCILIATION', 'AUDIT_DESK', 'AUDIT_TRAIL', 'REPORTS', 'SETTINGS'
  );

-- Grant view/create/edit/delete for CMA_ADMIN on CMA specific menus
INSERT INTO role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
SELECT 2, m.menu_id, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE
FROM menus m
WHERE m.menu_code IN (
    'DASHBOARD',
    'CMA_USER_MGMT', 'CMA_USER_MASTER',
    'CMA_COMPANY_MGMT', 'CMA_COMPANIES', 'CMA_DIVISIONS', 'CMA_PLANTS',
    'CMA_ACCESS_MGMT', 'CMA_USER_ACCESS',
    'ORG_COMPANIES', 'ORG_DIVISIONS', 'ORG_PLANTS'
)
ON CONFLICT (role_id, menu_id) DO UPDATE SET
    can_view = TRUE, can_create = TRUE, can_edit = TRUE, can_delete = TRUE, is_active = TRUE;
