-- COST360 — MIGRATION 007: Dynamic Cost Reco Menu Registration
-- Registers Cost Reco in menus table and grants role_menu access.
-- Fully dynamically adjustable via Menu Master and Role Master.

DO $$
BEGIN
    -- 1. Upsert into menus table
    IF EXISTS (SELECT 1 FROM public.menus WHERE menu_id = 47 OR menu_code = 'QUANTITY_RECO') THEN
        UPDATE public.menus
        SET menu_name = 'Cost Reco',
            route = 'quantity-reco.php',
            icon = 'calculator',
            status = 'Active',
            updated_at = CURRENT_TIMESTAMP
        WHERE menu_id = 47 OR menu_code = 'QUANTITY_RECO';
    ELSE
        INSERT INTO public.menus (
            menu_id, parent_menu_id, menu_code, menu_name, route, icon, display_order, menu_type, status, created_at, updated_at
        ) VALUES (
            47, NULL, 'QUANTITY_RECO', 'Cost Reco', 'quantity-reco.php', 'calculator', 22, 'Menu', 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        );
    END IF;

    -- Advance sequence if sequence exists
    PERFORM setval('menus_menu_id_seq', (SELECT COALESCE(MAX(menu_id), 47) FROM public.menus));

    -- 2. Grant permissions across active system roles
    -- Platform Admin (Role 1)
    INSERT INTO public.role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
    VALUES (1, 47, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE)
    ON CONFLICT (role_id, menu_id) DO UPDATE SET can_view = TRUE, is_active = TRUE;

    -- CMA Admin (Role 2)
    INSERT INTO public.role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
    VALUES (2, 47, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE)
    ON CONFLICT (role_id, menu_id) DO UPDATE SET can_view = TRUE, is_active = TRUE;

    -- Customer Manager (Role 3)
    INSERT INTO public.role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
    VALUES (3, 47, TRUE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE, TRUE)
    ON CONFLICT (role_id, menu_id) DO UPDATE SET can_view = TRUE, is_active = TRUE;

    -- Customer Admin (Role 4)
    INSERT INTO public.role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
    VALUES (4, 47, TRUE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE, TRUE)
    ON CONFLICT (role_id, menu_id) DO UPDATE SET can_view = TRUE, is_active = TRUE;

    -- Auditor / Reviewer (Role 5)
    INSERT INTO public.role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
    VALUES (5, 47, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, FALSE, TRUE, TRUE, FALSE, TRUE)
    ON CONFLICT (role_id, menu_id) DO UPDATE SET can_view = TRUE, is_active = TRUE;

    -- CMA User (Role 7)
    INSERT INTO public.role_menu (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_upload, can_download, can_approve, can_export, can_print, can_configure, is_active)
    VALUES (7, 47, TRUE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE, TRUE)
    ON CONFLICT (role_id, menu_id) DO UPDATE SET can_view = TRUE, is_active = TRUE;

END $$;

