-- =====================================================================
-- COST360 — GLOBAL DATABASE (Central DB)
-- =====================================================================

CREATE TABLE categories (
    category_id     BIGSERIAL PRIMARY KEY,
    category_code   VARCHAR(20)  NOT NULL UNIQUE,
    category_name   VARCHAR(100) NOT NULL,
    description     VARCHAR(255),
    status           VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO categories (category_code, category_name) VALUES
    ('PLAT_ADMIN', 'Platform Administrator'),
    ('CMA_ADMIN',  'CMA / Cost Administrator'),
    ('CUST_MGMT',  'Customer Management'),
    ('CUST_ADMIN', 'Customer Admin'),
    ('AUDITOR',    'Auditor / Reviewer');

CREATE TABLE menus (
    menu_id         BIGSERIAL PRIMARY KEY,
    parent_menu_id  BIGINT REFERENCES menus(menu_id) ON DELETE SET NULL,
    menu_code       VARCHAR(30)  NOT NULL UNIQUE,
    menu_name       VARCHAR(100) NOT NULL,
    route           VARCHAR(255),
    icon            VARCHAR(100),
    display_order   INT NOT NULL DEFAULT 0,
    menu_type       VARCHAR(20) NOT NULL DEFAULT 'Menu'
                        CHECK (menu_type IN ('Module','Menu','Submenu','Action')),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_menus_parent ON menus(parent_menu_id);

CREATE TABLE roles (
    role_id         BIGSERIAL PRIMARY KEY,
    category_id     BIGINT NOT NULL REFERENCES categories(category_id) ON DELETE RESTRICT,
    role_code       VARCHAR(30)  NOT NULL UNIQUE,
    role_name       VARCHAR(100) NOT NULL,
    description     VARCHAR(255),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_roles_category ON roles(category_id);

CREATE TABLE role_menu (
    role_menu_id    BIGSERIAL PRIMARY KEY,
    role_id         BIGINT NOT NULL REFERENCES roles(role_id) ON DELETE CASCADE,
    menu_id         BIGINT NOT NULL REFERENCES menus(menu_id) ON DELETE CASCADE,
    can_view        BOOLEAN NOT NULL DEFAULT FALSE,
    can_create      BOOLEAN NOT NULL DEFAULT FALSE,
    can_edit        BOOLEAN NOT NULL DEFAULT FALSE,
    can_delete      BOOLEAN NOT NULL DEFAULT FALSE,
    can_upload      BOOLEAN NOT NULL DEFAULT FALSE,
    can_download    BOOLEAN NOT NULL DEFAULT FALSE,
    can_approve     BOOLEAN NOT NULL DEFAULT FALSE,
    can_export      BOOLEAN NOT NULL DEFAULT FALSE,
    can_print       BOOLEAN NOT NULL DEFAULT FALSE,
    can_configure   BOOLEAN NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (role_id, menu_id)
);

CREATE INDEX idx_role_menu_role ON role_menu(role_id);
CREATE INDEX idx_role_menu_menu ON role_menu(menu_id);

CREATE TABLE users (
    user_id         BIGSERIAL PRIMARY KEY,
    category_id     BIGINT NOT NULL REFERENCES categories(category_id) ON DELETE RESTRICT,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    phone           VARCHAR(20),
    password_hash   VARCHAR(255) NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      BIGINT REFERENCES users(user_id),
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_category ON users(category_id);

CREATE TABLE login_history (
    login_id        BIGSERIAL PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    login_time      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      VARCHAR(45),
    status          VARCHAR(20) NOT NULL CHECK (status IN ('Success','Failed')),
    failure_reason  VARCHAR(255)
);

CREATE INDEX idx_login_history_user ON login_history(user_id);

CREATE TABLE audit_log (
    audit_id        BIGSERIAL PRIMARY KEY,
    user_id         BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    entity_name     VARCHAR(100) NOT NULL,
    entity_id       BIGINT NOT NULL,
    action          VARCHAR(20) NOT NULL CHECK (action IN ('INSERT','UPDATE','DELETE')),
    old_value       TEXT,
    new_value       TEXT,
    timestamp       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      VARCHAR(45)
);

CREATE INDEX idx_audit_log_user ON audit_log(user_id);
CREATE INDEX idx_audit_log_entity ON audit_log(entity_name, entity_id);

CREATE TABLE user_roles (
    user_role_id    BIGSERIAL PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    role_id         BIGINT NOT NULL REFERENCES roles(role_id) ON DELETE CASCADE,
    assigned_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by     BIGINT REFERENCES users(user_id),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    UNIQUE (user_id, role_id)
);

CREATE INDEX idx_user_roles_user ON user_roles(user_id);
CREATE INDEX idx_user_roles_role ON user_roles(role_id);

CREATE TABLE customers (
    customer_id     BIGSERIAL PRIMARY KEY,
    customer_code   VARCHAR(30)  NOT NULL UNIQUE,
    customer_name   VARCHAR(150) NOT NULL,
    address         VARCHAR(255),
    contact_person  VARCHAR(100),
    contact_email   VARCHAR(150),
    contact_phone   VARCHAR(20),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE companies (
    company_id      BIGSERIAL PRIMARY KEY,
    customer_id     BIGINT NOT NULL REFERENCES customers(customer_id) ON DELETE CASCADE,
    company_code    VARCHAR(30)  NOT NULL,
    company_name    VARCHAR(150) NOT NULL,
    address         VARCHAR(255),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    UNIQUE (customer_id, company_code)
);

CREATE INDEX idx_companies_customer ON companies(customer_id);

CREATE TABLE divisions (
    division_id     BIGSERIAL PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(company_id) ON DELETE CASCADE,
    division_code   VARCHAR(30)  NOT NULL,
    division_name   VARCHAR(150) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    UNIQUE (company_id, division_code)
);

CREATE INDEX idx_divisions_company ON divisions(company_id);

CREATE TABLE plants (
    plant_id        BIGSERIAL PRIMARY KEY,
    division_id     BIGINT NOT NULL REFERENCES divisions(division_id) ON DELETE CASCADE,
    plant_code      VARCHAR(30)  NOT NULL,
    plant_name      VARCHAR(150) NOT NULL,
    location        VARCHAR(255),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    UNIQUE (division_id, plant_code)
);

CREATE INDEX idx_plants_division ON plants(division_id);

CREATE TABLE warehouses (
    warehouse_id    BIGSERIAL PRIMARY KEY,
    plant_id        BIGINT NOT NULL REFERENCES plants(plant_id) ON DELETE CASCADE,
    warehouse_code  VARCHAR(30)  NOT NULL,
    warehouse_name  VARCHAR(150) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    UNIQUE (plant_id, warehouse_code)
);

CREATE INDEX idx_warehouses_plant ON warehouses(plant_id);

CREATE TABLE user_organization_access (
    access_id       BIGSERIAL PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    customer_id     BIGINT REFERENCES customers(customer_id) ON DELETE CASCADE,
    company_id      BIGINT REFERENCES companies(company_id) ON DELETE CASCADE,
    division_id     BIGINT REFERENCES divisions(division_id) ON DELETE CASCADE,
    plant_id        BIGINT REFERENCES plants(plant_id) ON DELETE CASCADE,
    warehouse_id    BIGINT REFERENCES warehouses(warehouse_id) ON DELETE CASCADE,
    valid_from      DATE NOT NULL DEFAULT CURRENT_DATE,
    valid_to        DATE,
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    CHECK (valid_to IS NULL OR valid_to >= valid_from)
);

CREATE INDEX idx_uoa_user ON user_organization_access(user_id);
CREATE INDEX idx_uoa_customer ON user_organization_access(customer_id);
CREATE INDEX idx_uoa_company ON user_organization_access(company_id);
CREATE INDEX idx_uoa_plant ON user_organization_access(plant_id);
CREATE INDEX idx_uoa_warehouse ON user_organization_access(warehouse_id);

-- =====================================================================
-- COST360 — CUSTOMER DATABASE 
-- =====================================================================

CREATE TABLE uoms (
    uom_id          BIGSERIAL PRIMARY KEY,
    uom_code        VARCHAR(20) NOT NULL UNIQUE,
    uom_name        VARCHAR(50) NOT NULL,
    description     VARCHAR(255),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
);

CREATE TABLE periods (
    period_id       BIGSERIAL PRIMARY KEY,
    period_name     VARCHAR(50) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    CHECK (end_date >= start_date)
);

CREATE TABLE items (
    item_id         BIGSERIAL PRIMARY KEY,
    item_code       VARCHAR(50)  NOT NULL UNIQUE,
    item_name       VARCHAR(150) NOT NULL,
    item_type       VARCHAR(30) NOT NULL DEFAULT 'Raw Material'
                        CHECK (item_type IN ('Raw Material','Finished Good','Semi-Finished','Consumable','Service')),
    uom_id          BIGINT NOT NULL REFERENCES uoms(uom_id),
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
);

CREATE INDEX idx_items_uom ON items(uom_id);

CREATE TABLE bom_headers (
    bom_id          BIGSERIAL PRIMARY KEY,
    item_id         BIGINT NOT NULL REFERENCES items(item_id) ON DELETE CASCADE,
    revision_no     VARCHAR(10) NOT NULL DEFAULT '1',
    effective_from  DATE NOT NULL,
    effective_to    DATE,
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    UNIQUE (item_id, revision_no),
    CHECK (effective_to IS NULL OR effective_to >= effective_from)
);

CREATE INDEX idx_bom_headers_item ON bom_headers(item_id);

CREATE TABLE bom_details (
    bom_detail_id       BIGSERIAL PRIMARY KEY,
    bom_id              BIGINT NOT NULL REFERENCES bom_headers(bom_id) ON DELETE CASCADE,
    component_item_id   BIGINT NOT NULL REFERENCES items(item_id),
    quantity            DECIMAL(18,4) NOT NULL CHECK (quantity > 0),
    uom_id              BIGINT NOT NULL REFERENCES uoms(uom_id),
    status              VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive'))
);

CREATE INDEX idx_bom_details_bom ON bom_details(bom_id);
CREATE INDEX idx_bom_details_component ON bom_details(component_item_id);

CREATE TABLE transactions (
    txn_id              BIGSERIAL PRIMARY KEY,
    item_id             BIGINT NOT NULL REFERENCES items(item_id),
    warehouse_id        BIGINT NOT NULL,
    period_id           BIGINT NOT NULL REFERENCES periods(period_id),
    txn_date            DATE NOT NULL DEFAULT CURRENT_DATE,
    txn_type            VARCHAR(20) NOT NULL DEFAULT 'Issue'
                            CHECK (txn_type IN ('Receipt','Issue','Transfer','Adjustment')),
    quantity            DECIMAL(18,4) NOT NULL,
    value               DECIMAL(18,2) NOT NULL,
    source_upload_id    BIGINT,
    status              VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive','Reversed'))
);

CREATE INDEX idx_transactions_item ON transactions(item_id);
CREATE INDEX idx_transactions_warehouse ON transactions(warehouse_id);
CREATE INDEX idx_transactions_period ON transactions(period_id);

CREATE TABLE standard_cost (
    cost_id         BIGSERIAL PRIMARY KEY,
    item_id         BIGINT NOT NULL REFERENCES items(item_id) ON DELETE CASCADE,
    cost_component  VARCHAR(50) NOT NULL,
    cost_value      DECIMAL(18,4) NOT NULL,
    effective_from  DATE NOT NULL,
    effective_to    DATE,
    status          VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Inactive')),
    CHECK (effective_to IS NULL OR effective_to >= effective_from)
);

CREATE INDEX idx_standard_cost_item ON standard_cost(item_id);
