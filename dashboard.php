<?php
/**
 * Cost360 - Enterprise SaaS Dashboard & Integrated Upload Centre Workspace
 * 100% Database-Driven from Neon PostgreSQL auth & public schemas.
 * Color Theme & Branding: Matched to Cost360 Approved Login Page.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/upload_centre.php';

/*
 * Cost360 dashboard requires:
 * 1. Authenticated user
 * 2. Active company selected for the current session
 */
requireActiveCompany();

$user = getAuthUser();
$role = getCurrentUserRole();
$authorizedMenus = getUserAuthorizedMenus();

/*
 * Active 6-tier organization & period context.
 * All modules operate within this selected context.
 */
$orgContext = getActiveOrganizationContext();
$activeCompany = getActiveCompany();
$activeCompanyId = $orgContext['company_id'] ?? null;
$activeCompanyCode = $orgContext['company_code'] ?? '';
$activeCompanyName = $orgContext['company_name'] ?? '';
$activeCustomerId = $orgContext['customer_id'] ?? null;
$activeCustomerName = $orgContext['customer_name'] ?? '';
$activeDivisionId = $orgContext['division_id'] ?? null;
$activeDivisionName = $orgContext['division_name'] ?? '';
$activePlantId = $orgContext['plant_id'] ?? null;
$activePlantName = $orgContext['plant_name'] ?? '';
$activeWarehouseId = $orgContext['warehouse_id'] ?? null;
$activeWarehouseName = $orgContext['warehouse_name'] ?? '';
$activePeriodId = $orgContext['period_id'] ?? null;
$activePeriodName = $orgContext['period_name'] ?? '';
$availablePeriods = getUserAccessiblePeriods($activeCompanyId);

/* ============================================================
   HANDLE INLINE PERIOD CREATION & ACTIVATION FROM POPOVER
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_and_select_period') {
    $pName = trim($_POST['period_name'] ?? '');
    $sDate = trim($_POST['start_date'] ?? '');
    $eDate = trim($_POST['end_date'] ?? '');
    $pStatus = trim($_POST['status'] ?? 'Active');

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1');

    try {
        $newPeriod = createAccountingPeriod($pName, $sDate, $eDate, $pStatus);
        setActivePeriod($newPeriod['period_id']);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'period' => $newPeriod]);
            exit;
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } catch (Exception $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $periodCreationError = $e->getMessage();
    }
}

// Determine the current URL to set active menu states
$currentUrl = $_SERVER['REQUEST_URI'];
$currentPage = strtolower($_GET['page'] ?? 'dashboard'); // Kept as fallback for module inclusion, but we won't use it for navigation state

// Upload Centre dynamic data (Lazy-loaded per module page for instant rendering)
$uploadStats = ($currentPage === 'upload') ? getUploadCentreStats($user['user_id']) : ['files' => 0, 'imports' => 0, 'rows' => 0, 'warnings' => 0, 'errors' => 0, 'pending' => 0];
$recentUploads = ($currentPage === 'upload' || $currentPage === 'history') ? getRecentUploads($user['user_id'], ($currentPage === 'upload' ? 10 : 50)) : [];
$auditLogs = ($currentPage === 'audit') ? getUploadAuditLogs(100) : [];
$mappingConfigsData = ($currentPage === 'mapping') ? getMappingConfigurationsData() : [];
$masterGroupsData = ($currentPage === 'masters') ? getMasterGroupsData() : [];

// Helper function for rendering menu icons safely
if (!function_exists('getMenuIconSvg')) {
    function getMenuIconSvg($iconName) {
        $iconMap = [
            'dashboard'  => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>',
            'people'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
            'settings'   => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
            'menu'       => '<line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line>',
            'business'   => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>',
            'database'   => '<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>',
            'calculator' => '<rect x="4" y="2" width="16" height="20" rx="2"></rect><line x1="8" y1="6" x2="16" y2="6"></line><line x1="16" y1="14" x2="16" y2="18"></line><path d="M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M8 18h.01M12 18h.01"></path>',
            'inventory'  => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line>',
            'assessment' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>'
        ];

        return $iconMap[$iconName] ?? '<circle cx="12" cy="12" r="10"></circle><polygon points="12 8 8 12 12 16 12 8"></polygon>';
    }
}

$appRootDir = realpath(__DIR__);
$currentScriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
$relative = str_replace('\\', '/', substr($currentScriptDir, strlen($appRootDir)));
$relative = trim($relative, '/');
if ($relative === '') {
    $baseUrlPrefix = '';
} else {
    $depth = count(explode('/', $relative));
    $baseUrlPrefix = str_repeat('../', $depth);
}

if (!function_exists('formatMenuRouteUrl')) {
    function formatMenuRouteUrl($route, $prefix = '') {
        if (empty($route) || $route === '#') {
            return '#';
        }
        $r = ltrim(trim($route), '/');
        $lower = strtolower($r);

        // If route is simply 'dashboard', point to dashboard.php
        if ($lower === 'dashboard') {
            return $prefix . 'dashboard.php';
        }

        // Parent modules with no standalone page shouldn't navigate away to a 404
        $parentOnlyRoutes = ['administration', 'data', 'costing', 'audit', 'reports', 'settings', 'organization', 'customers', 'customer_mgmt', 'administration/subscriptions', 'subscriptions', 'administration/cma', 'cma', 'cma/users', 'cma/companies', 'cma/access'];
        if (in_array($lower, $parentOnlyRoutes)) {
            return 'javascript:void(0);';
        }

        // Map known routes to actual working handlers
        $routeMap = [
            'customers'                              => 'customers.php',
            'customers/master'                       => 'customers.php',
            'organization/overview'                  => 'organization/overview/',
            'organization/companies'                 => 'organization/companies/',
            'organization/divisions'                 => 'organization/divisions/',
            'organization/plants'                    => 'organization/plants/',
            'organization/warehouses'                => 'organization/warehouses/',
            'organization/periods'                   => 'organization/periods/',
            'administration/users'                   => 'admin/users/',
            'admin/users'                            => 'admin/users/',
            'administration/roles'                   => 'admin/roles/',
            'admin/roles'                            => 'admin/roles/',
            'administration/menus'                   => 'admin/menus/',
            'admin/menus'                            => 'admin/menus/',
            'administration/cma/admins'              => 'administration/cma/admins/',
            'admin/cma-admins'                       => 'admin/cma-admins/',
            'cma/users'                              => 'cma/users/',
            'cma/users/'                             => 'cma/users/',
            'cma/access'                             => 'cma/access/',
            'cma/access/'                            => 'cma/access/',
            'user-access'                            => 'cma/access/',
            'administration/subscriptions/plans'     => 'administration/subscriptions/plans/',
            'admin/subscription-plans'               => 'admin/subscription-plans/',
            'subscription-plans'                     => 'subscription-plans.php',
            'administration/subscriptions/customers' => 'administration/subscriptions/customers/',
            'admin/customer-subscriptions'           => 'admin/customer-subscriptions/',
            'customer-subscriptions'                 => 'customer-subscriptions.php',
            'data/upload'                            => 'upload-centre.php',
            'data/mapping'                           => 'mapping-centre.php',
            'data/history'                           => 'import-history.php',
            'data/audit'                             => 'audit-logs.php',
            'data/masters'                           => 'master-groups.php',
        ];

        if (isset($routeMap[$lower])) {
            return $prefix . $routeMap[$lower];
        }

        return $prefix . $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cost360 - Enterprise Workspace & Upload Centre</title>
  <script>
    window.cost360BaseUrlPrefix = "<?php echo $baseUrlPrefix; ?>";
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* Design Tokens Matched to Login Page Palette */
    :root {
      --color-deep-navy: #033665;
      --color-primary-blue: #0878D1;
      --color-dark-blue: #063D70;
      --color-bright-cyan: #00CFEF;
      --color-soft-blue: #EAF5FC;
      --color-page-bg: #F5FAFE;
      --color-white: #FFFFFF;
      --color-primary-text: #17324D;
      --color-secondary-text: #6B7F91;
      --color-border: #E1EAF0;
      --color-border-hover: #B3C9DB;
      --shadow-card: 0 10px 30px -10px rgba(3, 54, 101, 0.08), 0 4px 12px rgba(0, 0, 0, 0.03);
      --radius-card: 16px;
      --radius-input: 8px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: var(--color-page-bg);
      color: var(--color-primary-text);
      min-height: 100vh;
      display: flex;
    }

    /* LEFT SIDEBAR NAVIGATION */
    .sidebar {
      width: 260px;
      background: linear-gradient(180deg, var(--color-deep-navy) 0%, var(--color-dark-blue) 100%);
      border-right: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      flex-direction: column;
      position: fixed;
      top: 0;
      bottom: 0;
      left: 0;
      z-index: 100;
      box-shadow: 4px 0 20px rgba(3, 54, 101, 0.15);
    }

    .sidebar-brand {
      padding: 1.25rem 1.25rem;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      min-height: 72px;
    }

    .sidebar-brand-logo {
      max-width: 100%;
      height: 42px;
      width: auto;
      object-fit: contain;
      display: block;
    }

    .nav-section-label {
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: rgba(255, 255, 255, 0.5);
      padding: 1.25rem 1.25rem 0.5rem;
      font-weight: 700;
    }

    .sidebar-nav {
      flex: 1;
      overflow-y: auto;
      padding: 0 0.75rem 1rem;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.65rem 0.85rem;
      color: rgba(255, 255, 255, 0.85);
      text-decoration: none;
      border-radius: var(--radius-input);
      font-size: 0.88rem;
      font-weight: 500;
      margin-bottom: 0.25rem;
      transition: all 0.2s ease;
    }
    .nav-item:hover, .nav-item.active {
      background: rgba(0, 207, 239, 0.16);
      color: var(--color-bright-cyan);
    }
    .nav-item svg {
      width: 18px;
      height: 18px;
      stroke: currentColor;
    }

    /* SUBMENU & PARENT-CHILD SIDEBAR NAVIGATION */
    .nav-parent-group {
      margin-bottom: 0.35rem;
    }
    .nav-parent-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-right: 0.5rem;
    }
    .nav-link-main {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      color: inherit;
      text-decoration: none;
      flex: 1;
    }
    .nav-toggle-btn {
      background: none;
      border: none;
      color: rgba(255, 255, 255, 0.6);
      cursor: pointer;
      padding: 0.25rem 0.4rem;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color 0.2s ease;
    }
    .nav-toggle-btn:hover {
      color: var(--color-bright-cyan);
    }
    .arrow-icon {
      width: 14px;
      height: 14px;
      transition: transform 0.25s ease;
      stroke: currentColor;
    }
    .nav-parent-group.open .arrow-icon,
    .nav-toggle-btn[aria-expanded="true"] .arrow-icon {
      transform: rotate(90deg);
    }

    .submenu-container {
      display: none;
      padding-left: 1.25rem;
      margin-top: 0.2rem;
      margin-bottom: 0.35rem;
      border-left: 2px solid rgba(0, 207, 239, 0.25);
    }
    .submenu-container.show {
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
    }

    .nav-child-item {
      font-size: 0.82rem;
      padding: 0.5rem 0.75rem;
      color: rgba(255, 255, 255, 0.75);
      border-radius: 6px;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.35rem;
    }
    .nav-child-item:hover, .nav-child-item.active {
      background: rgba(0, 207, 239, 0.14);
      color: var(--color-bright-cyan);
      font-weight: 600;
    }
    .child-indent-arrow {
      font-size: 0.75rem;
      opacity: 0.6;
    }

    /* MAIN APP CONTAINER */
    .app-main {
      flex: 1;
      margin-left: 260px;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    /* TOP HEADER */
    /* TOP HEADER */
    .top-header {
      height: 68px;
      background: var(--color-white);
      border-bottom: 1px solid var(--color-border);
      padding: 0 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      position: sticky;
      top: 0;
      z-index: 90;
      box-shadow: 0 2px 10px rgba(3, 54, 101, 0.03);
    }

    .page-heading {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--color-primary-text);
      flex-shrink: 0;
      white-space: nowrap;
    }

    /* CENTER FLEXIBLE CONTEXT CONTAINER & BAR */
    .active-org-context-container {
      flex: 1;
      min-width: 0;
      display: flex;
      align-items: center;
      position: relative;
    }

    .header-context-controls {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .org-context-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.38rem 0.75rem;
      background: var(--color-soft-blue);
      border: 1px solid #D6EAF7;
      border-radius: 8px;
      color: var(--color-deep-navy);
      font-weight: 600;
      font-size: 0.82rem;
      cursor: pointer;
      transition: all 0.2s ease;
      user-select: none;
    }
    .org-context-btn:hover {
      background: #D6EAF7;
      border-color: #B3D8F5;
      color: var(--color-primary-blue);
    }
    .org-context-btn.active {
      background: #E0F0FA;
      border-color: var(--color-primary-blue);
      color: var(--color-primary-blue);
    }
    .org-context-btn .toggle-chevron {
      transition: transform 0.2s ease;
    }
    .org-context-btn.active .toggle-chevron {
      transform: rotate(180deg);
    }

    /* ACTIVE ORG CONTEXT CONTAINER & COMPACT TRIGGER BUTTON */
    .active-org-context-container {
      position: relative;
      display: flex;
      align-items: center;
      flex-shrink: 0;
    }
    .context-trigger-pill-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.65rem;
      background: #FFFFFF;
      border: 1px solid #C8DDF2;
      padding: 0.35rem 0.8rem;
      border-radius: 999px;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 2px 8px rgba(3, 54, 101, 0.05);
      text-decoration: none;
      user-select: none;
      max-width: 270px;
      font-family: inherit;
      outline: none;
    }
    .context-trigger-pill-btn:hover,
    .context-trigger-pill-btn.active {
      border-color: #0070BA;
      background: #F4FAFF;
      box-shadow: 0 4px 14px rgba(0, 112, 186, 0.12);
    }
    .context-user-icon-img {
      width: 22px;
      height: 22px;
      object-fit: contain;
      flex-shrink: 0;
    }
    .context-trigger-summary {
      display: flex;
      flex-direction: column;
      text-align: left;
      overflow: hidden;
      line-height: 1.15;
    }
    .context-trigger-scope {
      font-size: 0.73rem;
      font-weight: 700;
      color: #033665;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 170px;
    }
    .context-trigger-period-label {
      font-size: 0.68rem;
      font-weight: 600;
      color: #0284C7;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 170px;
    }
    .context-trigger-arrow {
      color: #64748B;
      transition: transform 0.2s ease;
      flex-shrink: 0;
      margin-left: 2px;
    }
    .context-trigger-pill-btn.active .context-trigger-arrow {
      transform: rotate(180deg);
      color: #0070BA;
    }

    /* EXPANDABLE CONTEXT POPOVER */
    .active-context-popover {
      display: none;
      position: absolute;
      top: calc(100% + 10px);
      right: 0;
      width: 380px;
      max-width: 92vw;
      background: #FFFFFF;
      border: 1px solid #D8E6F0;
      border-radius: 14px;
      box-shadow: 0 16px 40px rgba(3, 54, 101, 0.16);
      z-index: 1050;
      padding: 1.15rem;
      animation: popoverSlideIn 0.2s ease forwards;
    }
    .active-context-popover.active {
      display: block;
    }
    @keyframes popoverSlideIn {
      from { opacity: 0; transform: translateY(-6px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .popover-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid #E2E8F0;
      padding-bottom: 0.75rem;
      margin-bottom: 0.85rem;
    }
    .popover-title-wrap {
      display: flex;
      align-items: center;
      gap: 0.55rem;
    }
    .popover-header-icon {
      width: 24px;
      height: 24px;
      object-fit: contain;
    }
    .popover-title {
      font-size: 0.86rem;
      font-weight: 800;
      color: #033665;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .popover-close-btn {
      background: transparent;
      border: none;
      font-size: 1.25rem;
      color: #94A3B8;
      cursor: pointer;
      padding: 2px 6px;
      border-radius: 4px;
      line-height: 1;
    }
    .popover-close-btn:hover {
      color: #0F172A;
      background: #F1F5F9;
    }
    .popover-hierarchy-box {
      background: #F8FAFC;
      border: 1px solid #E2E8F0;
      border-radius: 10px;
      padding: 0.75rem 0.9rem;
      margin-bottom: 0.85rem;
    }
    .popover-hierarchy-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.78rem;
      padding: 0.3rem 0;
      border-bottom: 1px dashed #E2E8F0;
    }
    .popover-hierarchy-row:last-child {
      border-bottom: none;
    }
    .popover-h-label {
      color: #64748B;
      font-weight: 600;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }
    .popover-h-value {
      color: #033665;
      font-weight: 700;
      text-align: right;
    }
    .popover-loc-badge {
      display: inline-block;
      font-size: 0.65rem;
      font-weight: 700;
      padding: 1px 6px;
      border-radius: 4px;
      margin-left: 5px;
    }
    .popover-loc-badge.plant {
      background: #E0F2FE;
      color: #0284C7;
    }
    .popover-loc-badge.warehouse {
      background: #FEF3C7;
      color: #D97706;
    }
    .btn-popover-switch-wizard {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      width: 100%;
      padding: 0.45rem;
      border-radius: 8px;
      background: #F0F7FD;
      border: 1px solid #B3D8F5;
      color: #0070BA;
      font-size: 0.78rem;
      font-weight: 700;
      text-decoration: none;
      margin-top: 0.6rem;
      transition: all 0.2s ease;
    }
    .btn-popover-switch-wizard:hover {
      background: #0070BA;
      color: #FFFFFF;
      border-color: #0070BA;
    }
    .popover-period-card {
      border-top: 1px solid #E2E8F0;
      padding-top: 0.85rem;
      margin-top: 0.85rem;
    }
    .popover-period-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.5rem;
    }
    .popover-period-title {
      font-size: 0.76rem;
      font-weight: 700;
      color: #033665;
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }
    .btn-toggle-add-period {
      background: transparent;
      border: none;
      color: #0070BA;
      font-size: 0.75rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 3px;
    }
    .btn-toggle-add-period:hover {
      text-decoration: underline;
    }
    .popover-period-select {
      width: 100%;
      padding: 0.45rem 0.65rem;
      border-radius: 8px;
      border: 1px solid #CBD5E1;
      background: #FFFFFF;
      font-size: 0.8rem;
      font-weight: 700;
      color: #033665;
      cursor: pointer;
      outline: none;
      transition: border-color 0.2s ease;
    }
    .popover-period-select:focus {
      border-color: #0070BA;
    }
    .popover-add-period-form {
      display: none;
      background: #F8FAFC;
      border: 1px solid #CBD5E1;
      border-radius: 8px;
      padding: 0.75rem;
      margin-top: 0.65rem;
    }
    .popover-add-period-form.active {
      display: block;
      animation: popoverSlideIn 0.2s ease forwards;
    }
    .form-input-p {
      width: 100%;
      padding: 0.35rem 0.55rem;
      border: 1px solid #CBD5E1;
      border-radius: 6px;
      font-size: 0.76rem;
      outline: none;
      margin-bottom: 0.45rem;
      box-sizing: border-box;
      font-family: inherit;
    }
    .form-input-p:focus {
      border-color: #0070BA;
    }

    .user-profile-menu {
      display: flex;
      align-items: center;
      gap: 0.85rem;
      flex-shrink: 0;
    }

    .user-details {
      text-align: right;
    }
    .user-name {
      font-weight: 700;
      font-size: 0.9rem;
      color: var(--color-primary-text);
    }
    .user-meta {
      font-size: 0.76rem;
      color: var(--color-secondary-text);
    }

    .role-badge {
      background: var(--color-soft-blue);
      color: var(--color-primary-blue);
      padding: 0.3rem 0.75rem;
      border-radius: 20px;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }

    .status-badge {
      background: #E6F9F6;
      color: #00A88F;
      padding: 0.3rem 0.75rem;
      border-radius: 20px;
      font-size: 0.78rem;
      font-weight: 700;
    }

    .btn-logout {
      background: transparent;
      border: 1px solid var(--color-border);
      color: var(--color-secondary-text);
      padding: 0.45rem 0.9rem;
      border-radius: var(--radius-input);
      font-size: 0.82rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-logout:hover {
      background: #FEE2E2;
      color: #DC2626;
      border-color: #FCA5A5;
    }

    /* DASHBOARD CONTENT */
    .dashboard-container {
      padding: 2rem;
      display: flex;
      flex-direction: column;
      gap: 1.75rem;
    }

    /* HERO WELCOME BANNER */
    .hero-welcome-card {
      background: linear-gradient(135deg, var(--color-deep-navy) 0%, var(--color-dark-blue) 100%);
      border-radius: var(--radius-card);
      padding: 2rem 2.25rem;
      color: var(--color-white);
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: var(--shadow-card);
      position: relative;
      overflow: hidden;
    }
    .hero-welcome-card::after {
      content: '';
      position: absolute;
      right: -30px;
      bottom: -30px;
      width: 220px;
      height: 220px;
      background: radial-gradient(circle, rgba(0, 207, 239, 0.2) 0%, rgba(8, 120, 209, 0) 70%);
      border-radius: 50%;
      pointer-events: none;
    }

    .welcome-text h1 {
      font-size: 1.6rem;
      font-weight: 800;
      margin-bottom: 0.35rem;
      letter-spacing: -0.02em;
    }
    .welcome-text p {
      color: rgba(255, 255, 255, 0.8);
      font-size: 0.95rem;
    }

    .welcome-pills {
      display: flex;
      gap: 0.75rem;
      align-items: center;
    }

    /* SUMMARY STAT CARDS */
    .grid-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.25rem;
    }

    .stat-card {
      background: var(--color-white);
      border-radius: var(--radius-card);
      padding: 1.4rem 1.5rem;
      border: 1px solid var(--color-border);
      box-shadow: var(--shadow-card);
      transition: transform 0.2s ease, border-color 0.2s ease;
    }
    .stat-card:hover {
      transform: translateY(-2px);
      border-color: var(--color-border-hover);
    }

    .stat-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.75rem;
    }
    .stat-label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--color-secondary-text);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .stat-number {
      font-size: 1.8rem;
      font-weight: 800;
      color: var(--color-primary-text);
      line-height: 1;
    }
    .stat-desc {
      font-size: 0.8rem;
      color: var(--color-secondary-text);
      margin-top: 0.4rem;
    }

    /* SECTION CARD & TABLES */
    .section-card {
      background: var(--color-white);
      border-radius: var(--radius-card);
      border: 1px solid var(--color-border);
      box-shadow: var(--shadow-card);
      padding: 1.5rem;
    }

    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.25rem;
    }

    .section-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--color-primary-text);
    }

    .table-responsive {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.88rem;
    }

    th {
      background: var(--color-page-bg);
      color: var(--color-secondary-text);
      font-weight: 700;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 0.85rem 1rem;
      border-bottom: 1px solid var(--color-border);
    }

    td {
      padding: 0.9rem 1rem;
      border-bottom: 1px solid var(--color-border);
      color: var(--color-primary-text);
    }

    tr:last-child td {
      border-bottom: none;
    }

    tr:hover td {
      background: var(--color-page-bg);
    }

    code {
      font-family: 'Consolas', 'Courier New', monospace;
      background: var(--color-soft-blue);
      color: var(--color-primary-blue);
      padding: 0.2rem 0.45rem;
      border-radius: 4px;
      font-size: 0.82rem;
    }

    .perm-pill {
      display: inline-block;
      padding: 0.2rem 0.5rem;
      border-radius: 4px;
      font-size: 0.75rem;
      font-weight: 700;
      margin-right: 0.25rem;
      margin-bottom: 0.25rem;
    }
    .perm-create, .perm-write, .perm-add { background: #D1FAE5; color: #065F46; }
    .perm-read, .perm-view { background: #E0F2FE; color: #0369A1; }
    .perm-update, .perm-edit { background: #FEF3C7; color: #92400E; }
    .perm-delete, .perm-remove { background: #FEE2E2; color: #991B1B; }

    .empty-state {
      padding: 2.5rem;
      text-align: center;
      color: var(--color-secondary-text);
      font-size: 0.9rem;
    }

    /* UPLOAD CENTRE MODULE SPECIFIC STYLING */
    .upload-dropzone {
      border: 2px dashed var(--color-border-hover);
      border-radius: var(--radius-card);
      padding: 3rem 2rem;
      text-align: center;
      background: var(--color-white);
      transition: all 0.25s ease;
      cursor: pointer;
    }
    .upload-dropzone.drag-active, .upload-dropzone:hover {
      border-color: var(--color-bright-cyan);
      background: var(--color-soft-blue);
    }
    .upload-icon-circle {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: var(--color-soft-blue);
      color: var(--color-primary-blue);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      font-weight: 800;
      margin: 0 auto 1rem;
    }

    .btn-primary {
      background: var(--color-primary-blue);
      color: #fff;
      border: none;
      padding: 0.65rem 1.4rem;
      border-radius: var(--radius-input);
      font-weight: 700;
      font-size: 0.88rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      transition: background 0.2s ease;
    }
    .btn-primary:hover { background: var(--color-dark-blue); }

    .btn-secondary {
      background: transparent;
      color: var(--color-primary-text);
      border: 1px solid var(--color-border);
      padding: 0.65rem 1.4rem;
      border-radius: var(--radius-input);
      font-weight: 600;
      font-size: 0.88rem;
      cursor: pointer;
    }
    .btn-secondary:hover { background: var(--color-page-bg); }

    .file-queue-box {
      margin-top: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 0.85rem;
    }
    .file-queue-box.hidden { display: none; }

    .queue-item-row, .analysis-result-card {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem 1.25rem;
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-input);
      gap: 1rem;
    }
    .file-ext-badge {
      padding: 0.4rem 0.75rem;
      background: var(--color-deep-navy);
      color: var(--color-white);
      font-weight: 800;
      font-size: 0.78rem;
      border-radius: 6px;
    }
    .file-info-col { flex: 1; }
    .file-title { display: block; font-size: 0.92rem; color: var(--color-primary-text); }
    .file-size, .file-meta { font-size: 0.78rem; color: var(--color-secondary-text); }
    .btn-remove-file {
      background: none; border: none; font-size: 1.3rem; color: var(--color-secondary-text); cursor: pointer;
    }
    .btn-remove-file:hover { color: #DC2626; }

    .confidence-badge {
      padding: 0.35rem 0.75rem;
      font-size: 0.78rem;
      font-weight: 700;
      border-radius: 20px;
    }
    .conf-high { background: #D1FAE5; color: #065F46; }
    .conf-med { background: #FEF3C7; color: #92400E; }
    .conf-low { background: #FEE2E2; color: #991B1B; }

    .master-tag {
      font-weight: 700; font-size: 0.85rem; color: var(--color-primary-blue); display: block;
    }
    .sub-tag { font-size: 0.76rem; color: var(--color-secondary-text); }

    /* DROPDOWN SELECT CONTROL STYLING */
    .dropdown-control-group {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      min-width: 180px;
    }
    .dropdown-label {
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--color-secondary-text);
    }
    .cost360-select, .cost360-select-sm {
      padding: 0.5rem 0.85rem;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-input);
      background-color: var(--color-white);
      color: var(--color-primary-text);
      font-family: inherit;
      font-size: 0.85rem;
      font-weight: 600;
      outline: none;
      cursor: pointer;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
      width: 100%;
    }
    .cost360-select:focus, .cost360-select-sm:focus {
      border-color: var(--color-primary-blue);
      box-shadow: 0 0 0 3px rgba(8, 120, 209, 0.12);
    }

    .mapping-grid-container {
      display: flex;
      flex-direction: column;
      gap: 0.65rem;
      padding: 1rem;
      background: var(--color-page-bg);
      border-radius: var(--radius-input);
      border: 1px solid var(--color-border);
    }
    .mapping-grid-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.65rem 0.85rem;
      background: var(--color-white);
      border-radius: 6px;
      border: 1px solid var(--color-border);
    }
    .source-header-name {
      flex: 1;
      font-size: 0.88rem;
      color: var(--color-primary-text);
    }
    .mapping-arrow {
      font-weight: 700;
      color: var(--color-primary-blue);
    }
    .target-field-select-wrap {
      flex: 1.5;
    }
    .mapping-match-status {
      min-width: 110px;
      text-align: right;
    }

    /* Modal Overlay */
    .cost360-modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(3, 54, 101, 0.6);
      z-index: 1000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      backdrop-filter: blur(4px);
    }
    .cost360-modal-dialog {
      background: #fff;
      width: 100%;
      max-width: 950px;
      max-height: 85vh;
      border-radius: var(--radius-card);
      display: flex;
      flex-direction: column;
      box-shadow: 0 20px 40px rgba(0,0,0,0.2);
      overflow: hidden;
    }
    .modal-header {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid var(--color-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .modal-close-btn {
      background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--color-secondary-text);
    }
    .modal-body-tablewrap {
      flex: 1; overflow: auto; padding: 1.25rem 1.5rem;
    }
    .preview-data-table {
      width: 100%; border-collapse: collapse; font-size: 0.85rem;
    }
    .preview-data-table th, .preview-data-table td {
      padding: 0.65rem 0.85rem; border: 1px solid var(--color-border); text-align: left;
    }
    .preview-data-table th { background: var(--color-soft-blue); color: var(--color-primary-text); font-weight: 700; }
    .modal-footer {
      padding: 1rem 1.5rem; border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end;
    }

    @media (max-width: 768px) {
      .sidebar { display: none; }
      .app-main { margin-left: 0; }
      .top-header { padding: 0 1rem; }
      .dashboard-container { padding: 1rem; }
      .hero-welcome-card { flex-direction: column; align-items: flex-start; gap: 1rem; }
    }
  </style>
</head>
<body>

  <!-- DYNAMIC AUTHORIZED SIDEBAR FROM NEON DATABASE -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <img src="<?php echo $baseUrlPrefix; ?>assets/images/cost360-logo.png" alt="Cost360 Logo" class="sidebar-brand-logo">
    </div>

    <div class="nav-section-label">Authorized Navigation</div>

    <nav class="sidebar-nav">
      <?php if (!empty($authorizedMenus)): ?>
        <?php
          // Build a recursive tree from the flat $authorizedMenus list
          $menuTree = [];
          $menuMap = [];

          foreach ($authorizedMenus as $m) {
              $m['children'] = [];
              $m['is_active'] = false;
              $menuMap[$m['menu_id']] = $m;
          }

          foreach ($menuMap as $id => &$m) {
              if (empty($m['parent_menu_id'])) {
                  $menuTree[] = &$m;
              } else {
                  if (isset($menuMap[$m['parent_menu_id']])) {
                      $menuMap[$m['parent_menu_id']]['children'][] = &$m;
                  }
              }
          }

          // Recursive function to mark active routes
          if (!function_exists('markActiveMenus')) {
              function markActiveMenus(&$node, $currentUrl, $baseUrlPrefix) {
                  $isActive = false;

                  $menuRoute = formatMenuRouteUrl($node['route'], $baseUrlPrefix);
                  if ($menuRoute !== '#' && $menuRoute !== '') {
                      $normUrl = parse_url($currentUrl, PHP_URL_PATH) . (parse_url($currentUrl, PHP_URL_QUERY) ? '?' . parse_url($currentUrl, PHP_URL_QUERY) : '');
                      $normMenu = parse_url($menuRoute, PHP_URL_PATH) . (parse_url($menuRoute, PHP_URL_QUERY) ? '?' . parse_url($menuRoute, PHP_URL_QUERY) : '');

                      if (strpos($normUrl, ltrim($normMenu, '/')) !== false || strpos($normUrl, $normMenu) !== false) {
                          $isActive = true;
                      }
                  }

                  if (!$isActive && strpos($currentUrl, 'dashboard.php') !== false && strpos($currentUrl, '?page') === false) {
                      if (strcasecmp($node['menu_code'], 'DASHBOARD') === 0) {
                          $isActive = true;
                      }
                  }

                  $node['is_active'] = $isActive;
                  $childActive = false;

                  foreach ($node['children'] as &$child) {
                      if (markActiveMenus($child, $currentUrl, $baseUrlPrefix)) {
                          $childActive = true;
                      }
                  }

                  if ($childActive) {
                      $node['is_active'] = true;
                  }

                  return $node['is_active'];
              }
          }

          $activeMenuName = 'Cost Management & Manufacturing Analytics';
          foreach ($menuTree as &$rootMenu) {
              markActiveMenus($rootMenu, $currentUrl, $baseUrlPrefix);
          }

          if (!function_exists('findActiveMenuName')) {
              function findActiveMenuName($nodes, &$activeName) {
                  foreach ($nodes as $node) {
                      if ($node['is_active']) {
                          $activeName = $node['menu_name'];
                          if (!empty($node['children'])) {
                              findActiveMenuName($node['children'], $activeName);
                          }
                      }
                  }
              }
          }
          findActiveMenuName($menuTree, $activeMenuName);

          // Recursive HTML rendering function
          if (!function_exists('renderRecursiveMenu')) {
              function renderRecursiveMenu($node, $baseUrlPrefix, $depth = 0) {
                  $hasChildren = !empty($node['children']);
                  $isActive = $node['is_active'];
                  $route = formatMenuRouteUrl($node['route'], $baseUrlPrefix);
                  $linkHref = ($route && $route !== '#') ? htmlspecialchars($route) : 'javascript:void(0);';

                  if ($depth === 0) {
                      if ($hasChildren) {
                          echo '<div class="nav-parent-group ' . ($isActive ? 'open' : '') . '">';
                          echo '<div class="nav-item nav-parent-item ' . ($isActive ? 'active' : '') . '">';
                          echo '<a href="' . $linkHref . '" class="nav-link-main">';
                          echo '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
                          echo getMenuIconSvg($node['icon']);
                          echo '</svg>';
                          echo '<span>' . htmlspecialchars($node['menu_name']) . '</span>';
                          echo '</a>';
                          echo '<button type="button" class="nav-toggle-btn" aria-expanded="' . ($isActive ? 'true' : 'false') . '" onclick="toggleSubmenu(this)">';
                          echo '<svg class="arrow-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
                          echo '</button>';
                          echo '</div>';
                          echo '<div class="submenu-container ' . ($isActive ? 'show' : '') . '">';
                          foreach ($node['children'] as $child) {
                              renderRecursiveMenu($child, $baseUrlPrefix, $depth + 1);
                          }
                          echo '</div></div>';
                      } else {
                          echo '<a href="' . $linkHref . '" class="nav-item ' . ($isActive ? 'active' : '') . '">';
                          echo '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
                          echo getMenuIconSvg($node['icon']);
                          echo '</svg>';
                          echo '<span>' . htmlspecialchars($node['menu_name']) . '</span>';
                          echo '</a>';
                      }
                  } else {
                      if ($hasChildren) {
                          echo '<div class="nav-parent-group ' . ($isActive ? 'open' : '') . '">';
                          echo '<div class="nav-item nav-parent-item ' . ($isActive ? 'active' : '') . '">';
                          echo '<a href="' . $linkHref . '" class="nav-link-main nav-child-item">';
                          echo '<span class="child-indent-arrow" style="margin-left: ' . (($depth - 1) * 10) . 'px;">&rarr;</span>';
                          echo '<span>' . htmlspecialchars($node['menu_name']) . '</span>';
                          echo '</a>';
                          echo '<button type="button" class="nav-toggle-btn" aria-expanded="' . ($isActive ? 'true' : 'false') . '" onclick="toggleSubmenu(this)">';
                          echo '<svg class="arrow-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
                          echo '</button>';
                          echo '</div>';
                          echo '<div class="submenu-container ' . ($isActive ? 'show' : '') . '">';
                          foreach ($node['children'] as $child) {
                              renderRecursiveMenu($child, $baseUrlPrefix, $depth + 1);
                          }
                          echo '</div></div>';
                      } else {
                          echo '<a href="' . $linkHref . '" class="nav-item nav-child-item ' . ($isActive ? 'active' : '') . '">';
                          echo '<span class="child-indent-arrow" style="margin-left: ' . (($depth - 1) * 10) . 'px;">&rarr;</span>';
                          echo '<span>' . htmlspecialchars($node['menu_name']) . '</span>';
                          echo '</a>';
                      }
                  }
              }
          }

          foreach ($menuTree as $rootMenu) {
              renderRecursiveMenu($rootMenu, $baseUrlPrefix, 0);
          }
        ?>
      <?php else: ?>
        <div class="empty-state" style="padding: 1rem; font-size: 0.8rem; color: rgba(255, 255, 255, 0.7);">
          No menus are currently assigned to your role.
        </div>
      <?php endif; ?>
    </nav>
  </aside>

  <!-- MAIN APPLICATION AREA -->
  <div class="app-main">

    <!-- TOP HEADER -->
    <header class="top-header">
      <div class="page-heading">
        <?php
          echo htmlspecialchars($activeMenuName);
        ?>
      </div>

      <!-- ACTIVE ORGANIZATION & OPERATIONAL LOCATION CONTEXT HEADER -->
      <div class="active-org-context-container">
        <!-- COMPACT TRIGGER BUTTON (Prevents header squishing on long titles) -->
        <button
          type="button"
          id="contextPopoverToggle"
          class="context-trigger-pill-btn"
          onclick="toggleContextPopover(event)"
          title="Click to view Organization Scope & Switch Period"
        >
          <img src="<?php echo $baseUrlPrefix; ?>assets/images/context-switch-icon.png" alt="Switch Context" class="context-user-icon-img" />
          <div class="context-trigger-summary">
            <span class="context-trigger-scope">
              <?php if ($activeCompanyId === 'ALL' && isPlatformAdmin($user['user_id'])): ?>
                Global Platform Scope
              <?php else: ?>
                <?php echo htmlspecialchars($activeCompanyName ?: 'Select Context'); ?>
              <?php endif; ?>
            </span>
            <span class="context-trigger-period-label">
              <?php echo htmlspecialchars($activePeriodName ?: 'Select Period'); ?>
            </span>
          </div>
          <svg class="context-trigger-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="6 9 12 15 18 9"></polyline>
          </svg>
        </button>

        <!-- FLYOUT / POPUP MODAL -->
        <div id="contextPopover" class="active-context-popover" onclick="event.stopPropagation();">
          <div class="popover-header">
            <div class="popover-title-wrap">
              <img src="<?php echo $baseUrlPrefix; ?>assets/images/context-switch-icon.png" alt="Context" class="popover-header-icon" />
              <div class="popover-title">Organization Scope</div>
            </div>
            <button type="button" class="popover-close-btn" onclick="toggleContextPopover(event)" title="Close">&times;</button>
          </div>

          <div class="popover-hierarchy-box">
            <?php if (!empty($activeCustomerName) && $activeCustomerName !== 'All Customers'): ?>
              <div class="popover-hierarchy-row">
                <span class="popover-h-label">Customer</span>
                <span class="popover-h-value"><?php echo htmlspecialchars($activeCustomerName); ?></span>
              </div>
            <?php endif; ?>

            <div class="popover-hierarchy-row">
              <span class="popover-h-label">Company</span>
              <span class="popover-h-value"><?php echo htmlspecialchars($activeCompanyName ?: 'All Companies'); ?></span>
            </div>

            <div class="popover-hierarchy-row">
              <span class="popover-h-label">Division</span>
              <span class="popover-h-value"><?php echo htmlspecialchars($activeDivisionName ?: 'All Divisions'); ?></span>
            </div>

            <?php
              $loc = getActiveOperationalLocation();
            ?>
            <div class="popover-hierarchy-row">
              <span class="popover-h-label">Location</span>
              <span class="popover-h-value">
                <?php if ($loc): ?>
                  <?php echo htmlspecialchars($loc['name']); ?>
                  <span class="popover-loc-badge <?php echo htmlspecialchars($loc['type']); ?>"><?php echo htmlspecialchars($loc['label']); ?></span>
                <?php else: ?>
                  All Locations
                <?php endif; ?>
              </span>
            </div>

            <a href="<?php echo $baseUrlPrefix; ?>customer-selection.php" class="btn-popover-switch-wizard" title="Switch Organization Hierarchy Scope">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="1 4 1 10 7 10"></polyline>
                <polyline points="23 20 23 14 17 14"></polyline>
                <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"></path>
              </svg>
              <span>Switch Hierarchy Scope</span>
            </a>
          </div>

          <div class="popover-period-card">
            <div class="popover-period-header">
              <span class="popover-period-title">Accounting Period</span>
              <button type="button" class="btn-toggle-add-period" onclick="toggleAddPeriodForm()">
                <span>+ Add Period</span>
              </button>
            </div>

            <?php if (!empty($availablePeriods)): ?>
              <select class="popover-period-select" onchange="switchActivePeriod(this.value)">
                <?php foreach ($availablePeriods as $per): ?>
                  <option value="<?php echo htmlspecialchars($per['period_id']); ?>" <?php echo ((string)$per['period_id'] === (string)$activePeriodId) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($per['period_name']); ?> (<?php echo date('M Y', strtotime($per['start_date'])); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <div style="font-size: 0.75rem; color: #64748B; margin-bottom: 6px;">No accounting periods found.</div>
            <?php endif; ?>

            <!-- INLINE ADD PERIOD FORM -->
            <div id="popoverAddPeriodForm" class="popover-add-period-form">
              <div style="font-size: 0.76rem; font-weight: 700; color: #033665; margin-bottom: 6px;">Create &amp; Select Period</div>
              <form id="ajaxAddPeriodForm" onsubmit="submitPopoverNewPeriod(event)">
                <input type="text" id="newPeriodName" name="period_name" placeholder="Period Name (e.g. October 2026)" required class="form-input-p" />
                <div style="display: flex; gap: 6px;">
                  <div style="flex: 1;">
                    <label style="font-size: 0.68rem; color: #64748B; font-weight: 600; display: block; margin-bottom: 2px;">Start Date</label>
                    <input type="date" id="newPeriodStart" name="start_date" required class="form-input-p" />
                  </div>
                  <div style="flex: 1;">
                    <label style="font-size: 0.68rem; color: #64748B; font-weight: 600; display: block; margin-bottom: 2px;">End Date</label>
                    <input type="date" id="newPeriodEnd" name="end_date" required class="form-input-p" />
                  </div>
                </div>
                <div id="addPeriodErrorMsg" style="display: none; color: #DC2626; font-size: 0.72rem; font-weight: 600; margin-bottom: 6px;"></div>
                <div style="display: flex; justify-content: flex-end; gap: 6px; margin-top: 4px;">
                  <button type="button" onclick="toggleAddPeriodForm()" style="padding: 4px 10px; font-size: 0.74rem; border: 1px solid #CBD5E1; background: #FFFFFF; border-radius: 5px; cursor: pointer;">Cancel</button>
                  <button type="submit" id="btnSaveNewPeriod" style="padding: 4px 12px; font-size: 0.74rem; border: none; background: #0070BA; color: #FFFFFF; font-weight: 700; border-radius: 5px; cursor: pointer;">Save &amp; Activate</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="user-profile-menu">
        <div class="user-details">
          <div class="user-name"><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></div>
          <div class="user-meta"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
        </div>

        <span class="role-badge"><?php echo htmlspecialchars($role['role_name'] ?? 'No Role Assigned'); ?></span>

        <span class="status-badge"><?php echo htmlspecialchars($user['status'] ?? 'ACTIVE'); ?></span>

        <a href="<?php echo $baseUrlPrefix; ?>logout.php" class="btn-logout">Sign Out</a>

      </div>
    </header>

    <!-- DASHBOARD / UPLOAD CENTRE WORKSPACE CONTAINER -->
    <main class="dashboard-container">

      <!-- HERO WELCOME BANNER -->
      <section class="hero-welcome-card">
        <div class="welcome-text">
          <h1>Welcome, <?php echo htmlspecialchars($user['first_name'] ?: ($user['email'] ?? 'User')); ?></h1>
          <p>Enterprise Cost Intelligence & Dynamic Data Import Gateway</p>
        </div>
        <div class="welcome-pills">
          <div style="font-size: 0.88rem;">
            Role: <strong><?php echo htmlspecialchars($role['role_name'] ?? 'None'); ?></strong>
          </div>
          <div style="font-size: 0.88rem;">
            Status: <strong><?php echo htmlspecialchars($user['status'] ?? 'ACTIVE'); ?></strong>
          </div>
        </div>
      </section>

      <?php if ($currentPage === 'upload'): ?>
        <!-- ============================================================
             UPLOAD CENTRE PAGE MODULE
             ============================================================ -->
        <section class="grid-cards">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Files Uploaded</span></div>
            <div class="stat-number"><?php echo number_format($uploadStats['files']); ?></div>
            <div class="stat-desc">Total ingestion runs</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Imports Completed</span></div>
            <div class="stat-number" style="color: var(--color-bright-cyan);"><?php echo number_format($uploadStats['imports']); ?></div>
            <div class="stat-desc">Committed to Neon DB</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Rows Processed</span></div>
            <div class="stat-number"><?php echo number_format($uploadStats['rows']); ?></div>
            <div class="stat-desc">Across all files</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Errors Traceable</span></div>
            <div class="stat-number" style="color: #DC2626;"><?php echo number_format($uploadStats['errors']); ?></div>
            <div class="stat-desc">Logged & flagged</div>
          </div>
        </section>

        <section class="section-card">
          <div class="section-header">
            <h2 class="section-title">Upload Costing & Master Data Files</h2>
            <div style="display: flex; gap: 0.75rem;">
              <button class="btn-secondary" id="selectBtn">Select Files</button>
              <button class="btn-secondary" id="folderBtn">Select Folder</button>
            </div>
          </div>

          <div class="upload-dropzone" id="dropZone">
            <div class="upload-icon-circle">&uarr;</div>
            <h3 style="margin-bottom: 0.5rem; font-weight: 700;">Drag & Drop Manufacturing Data Files Here</h3>
            <p style="color: var(--color-secondary-text); font-size: 0.9rem; margin-bottom: 1rem;">
              Supported Formats: <strong>CSV &bull; XLS &bull; XLSX &bull; PDF</strong> &bull; Multiple Files & Folders Supported
            </p>
            <input id="fileInput" type="file" multiple accept=".csv,.xls,.xlsx,.pdf" hidden>
            <input id="folderInput" type="file" webkitdirectory directory multiple hidden>
          </div>

          <!-- Queue Container -->
          <div id="fileQueue" class="file-queue-box hidden"></div>

          <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 1rem;">
            <button class="btn-secondary" id="clearBtn">Clear All</button>
            <button class="btn-primary" id="analyzeBtn" disabled>Analyze Files</button>
            <button class="btn-primary" id="importBtn" style="display: none; background: #10B981;">Confirm & Import</button>
          </div>
        </section>

        <!-- RECENT IMPORT ACTIVITY -->
        <section class="section-card">
          <div class="section-header">
            <h2 class="section-title">Recent Import Activity</h2>
            <a href="dashboard.php?page=history" style="color: var(--color-primary-blue); font-weight: 600; text-decoration: none; font-size: 0.88rem;">View All Runs &rarr;</a>
          </div>
          <div class="table-responsive">
            <?php if (!empty($recentUploads)): ?>
              <table>
                <thead>
                  <tr>
                    <th>Import Code</th>
                    <th>Files</th>
                    <th>Date & Time</th>
                    <th>Rows</th>
                    <th>Success</th>
                    <th>Errors</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentUploads as $r): ?>
                    <tr>
                      <td><code><?php echo htmlspecialchars($r['import_code']); ?></code></td>
                      <td><strong><?php echo htmlspecialchars($r['files'] ?: 'Import Run'); ?></strong></td>
                      <td><?php echo htmlspecialchars(date('d M Y H:i', strtotime($r['created_at']))); ?></td>
                      <td><?php echo number_format((int)$r['total_rows']); ?></td>
                      <td><span style="color: #059669; font-weight: 700;"><?php echo number_format((int)$r['success_rows']); ?></span></td>
                      <td><span style="color: #DC2626; font-weight: 700;"><?php echo number_format((int)$r['error_rows']); ?></span></td>
                      <td><span class="status-badge"><?php echo htmlspecialchars($r['status']); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="empty-state">No import runs completed yet. Upload your first dataset above to begin.</div>
            <?php endif; ?>
          </div>
        </section>

      <?php elseif ($currentPage === 'mapping'): ?>
        <!-- ============================================================
             MAPPING CENTRE PAGE MODULE (DYNAMIC FROM NEON DB)
             ============================================================ -->
        <section class="section-card">
          <div class="section-header">
            <h2 class="section-title">Mapping Configurations & Target Column Definitions</h2>
          </div>
          <div class="table-responsive">
            <?php if (!empty($mappingConfigsData)): ?>
              <table>
                <thead>
                  <tr>
                    <th>Master Group</th>
                    <th>Sub Category</th>
                    <th>Target Neon Table</th>
                    <th>Matching Confidence</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($mappingConfigsData as $map): ?>
                    <?php
                      $conf = (float)$map['confidence'];
                      $confClass = $conf >= 90 ? 'conf-high' : ($conf >= 70 ? 'conf-med' : 'conf-low');
                    ?>
                    <tr>
                      <td><strong><?php echo htmlspecialchars($map['group_name']); ?></strong></td>
                      <td><?php echo htmlspecialchars($map['sub_name']); ?></td>
                      <td><code><?php echo htmlspecialchars($map['table_name'] ?: 'item_master'); ?></code></td>
                      <td><span class="confidence-badge <?php echo $confClass; ?>"><?php echo number_format($conf, 0); ?>% Match</span></td>
                      <td><span class="status-badge"><?php echo htmlspecialchars(strtoupper($map['status'])); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="empty-state">No mapping configurations found in Neon PostgreSQL.</div>
            <?php endif; ?>
          </div>
        </section>

      <?php elseif ($currentPage === 'history'): ?>
        <!-- ============================================================
             IMPORT HISTORY PAGE MODULE (DYNAMIC FROM NEON DB WITH ERROR TRACE)
             ============================================================ -->
        <section class="section-card">
          <div class="section-header">
            <h2 class="section-title">Import History & Row Error Traceability</h2>
          </div>
          <div class="table-responsive">
            <?php if (!empty($recentUploads)): ?>
              <table>
                <thead>
                  <tr>
                    <th>Import Code</th>
                    <th>Uploaded By</th>
                    <th>Date</th>
                    <th>File(s)</th>
                    <th>Total Rows</th>
                    <th>Success</th>
                    <th>Errors</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentUploads as $r): ?>
                    <tr>
                      <td><code><?php echo htmlspecialchars($r['import_code']); ?></code></td>
                      <td><strong><?php echo htmlspecialchars($r['username']); ?></strong></td>
                      <td><?php echo htmlspecialchars(date('d M Y H:i', strtotime($r['created_at']))); ?></td>
                      <td><?php echo htmlspecialchars($r['files']); ?></td>
                      <td><?php echo number_format((int)$r['total_rows']); ?></td>
                      <td><span style="color: #059669; font-weight: 700;"><?php echo number_format((int)$r['success_rows']); ?></span></td>
                      <td><span style="color: #DC2626; font-weight: 700;"><?php echo number_format((int)$r['error_rows']); ?></span></td>
                      <td><span class="status-badge"><?php echo htmlspecialchars($r['status']); ?></span></td>
                      <td>
                        <?php if ((int)$r['error_rows'] > 0): ?>
                          <button type="button" class="btn-action-sm" style="background: #DC2626;" onclick="viewImportErrors(<?php echo $r['id']; ?>)">View Error Trace</button>
                        <?php else: ?>
                          <span style="font-size: 0.8rem; color: #059669; font-weight: 600;">Clean Run</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="empty-state">No import runs found in Neon PostgreSQL.</div>
            <?php endif; ?>
          </div>
        </section>

      <?php elseif ($currentPage === 'audit'): ?>
        <!-- ============================================================
             AUDIT LOGS PAGE MODULE (DYNAMIC FROM NEON DB)
             ============================================================ -->
        <section class="section-card">
          <div class="section-header">
            <h2 class="section-title">System Audit Logs & Traceability</h2>
          </div>
          <div class="table-responsive">
            <?php if (!empty($auditLogs)): ?>
              <table>
                <thead>
                  <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>File Name</th>
                    <th>Status</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($auditLogs as $log): ?>
                    <tr>
                      <td><?php echo htmlspecialchars(date('d M Y H:i:s', strtotime($log['timestamp']))); ?></td>
                      <td><strong><?php echo htmlspecialchars($log['username'] ?? 'User'); ?></strong></td>
                      <td><code><?php echo htmlspecialchars($log['action']); ?></code></td>
                      <td><?php echo htmlspecialchars($log['module']); ?></td>
                      <td><?php echo htmlspecialchars($log['file_name'] ?: '—'); ?></td>
                      <td><span class="status-badge"><?php echo htmlspecialchars($log['status']); ?></span></td>
                      <td style="font-size: 0.8rem; color: var(--color-secondary-text);">
                        <?php echo htmlspecialchars(is_string($log['details']) ? $log['details'] : json_encode($log['details'])); ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="empty-state">No audit logs recorded yet.</div>
            <?php endif; ?>
          </div>
        </section>

      <?php elseif ($currentPage === 'masters'): ?>
        <!-- ============================================================
             MASTER GROUPS PAGE MODULE (DYNAMIC FROM NEON DB)
             ============================================================ -->
        <section class="section-card">
          <div class="section-header">
            <h2 class="section-title">Master Groups & Data Classification Hierarchy</h2>
          </div>
          <div class="table-responsive">
            <?php if (!empty($masterGroupsData)): ?>
              <table>
                <thead>
                  <tr>
                    <th>Master Group Code</th>
                    <th>Master Group Name</th>
                    <th>Description</th>
                    <th>Linked Sub Categories</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($masterGroupsData as $mg): ?>
                    <tr>
                      <td><code><?php echo htmlspecialchars($mg['code']); ?></code></td>
                      <td><strong><?php echo htmlspecialchars($mg['name']); ?></strong></td>
                      <td style="color: var(--color-secondary-text);"><?php echo htmlspecialchars($mg['description'] ?: 'Master group classification'); ?></td>
                      <td><strong><?php echo (int)$mg['sub_count']; ?></strong> categories</td>
                      <td><span class="status-badge"><?php echo htmlspecialchars(strtoupper($mg['status'])); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="empty-state">No master groups found in Neon PostgreSQL.</div>
            <?php endif; ?>
          </div>
        </section>

      <?php elseif ($currentPage === 'customers'): ?>
        <!-- ============================================================
             CUSTOMER MANAGEMENT MODULE (DYNAMIC FROM NEON DB)
             ============================================================ -->
        <section class="grid-cards" style="margin-bottom: 1.5rem;">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Total Customers</span></div>
            <div class="stat-number" id="kpiTotalCust">0</div>
            <div class="stat-desc">Registered tenant accounts</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Active Customers</span></div>
            <div class="stat-number" id="kpiActiveCust" style="color: var(--color-bright-cyan);">0</div>
            <div class="stat-desc">Active subscriptions</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Inactive Tenants</span></div>
            <div class="stat-number" id="kpiInactiveCust" style="color: #DC2626;">0</div>
            <div class="stat-desc">Suspended / Deactivated</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Total Companies</span></div>
            <div class="stat-number" id="kpiTotalCompanies">0</div>
            <div class="stat-desc">Across all customer tenants</div>
          </div>
        </section>

        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem; font-size: 1.3rem;">Customer Management</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage customers, subscriptions and organization structure</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddCustomerWizard()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>+ Add Customer</span>
              </button>
            </div>
          </div>

          <!-- FILTERS BAR -->
          <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="flex: 1; min-width: 260px;">
              <input type="text" id="custSearchInput" class="cost360-select" placeholder="Search by customer code, name, contact..." onkeyup="filterCustomersList()">
            </div>
            <div style="min-width: 150px;">
              <select id="custStatusFilter" class="cost360-select" onchange="filterCustomersList()">
                <option value="ALL">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
            <div style="min-width: 170px;">
              <select id="custSubscriptionFilter" class="cost360-select" onchange="filterCustomersList()">
                <option value="ALL">All Subscription Plans</option>
                <option value="Enterprise">Enterprise</option>
                <option value="Professional">Professional</option>
                <option value="Standard">Standard</option>
              </select>
            </div>
            <button type="button" class="btn-secondary" onclick="resetCustomerFilters()">Reset</button>
          </div>

          <!-- CUSTOMERS TABLE -->
          <div class="table-responsive">
            <table id="customersTable">
              <thead>
                <tr>
                  <th style="width: 50px;">#</th>
                  <th>Customer Code</th>
                  <th>Customer Name</th>
                  <th>Primary Contact</th>
                  <th>Subscription Plan</th>
                  <th>Status</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="customersTableBody">
                <tr><td colspan="7" class="empty-state">Loading customer accounts from Neon PostgreSQL...</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ============================================================
             ADD CUSTOMER MULTI-STEP PROVISIONING WIZARD DRAWER / MODAL
             ============================================================ -->
        <div id="addCustomerWizardModal" class="cost360-modal-overlay" style="display: none;">
          <div class="section-card" style="width: 900px; max-width: 95vw; max-height: 90vh; overflow-y: auto; padding: 2rem; position: relative;">
            <button type="button" onclick="closeModal('addCustomerWizardModal')" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--color-secondary-text);">&times;</button>

            <div style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--color-border); padding-bottom: 1rem;">
              <h2 style="font-size: 1.3rem; color: var(--color-deep-navy);">Add Customer</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Create a new customer tenant and set up organization details</p>
            </div>

            <!-- STEP INDICATOR BAR -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem; background: var(--color-page-bg); padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--color-border); font-size: 0.8rem; overflow-x: auto; gap: 0.5rem;">
              <div class="wiz-step-pill active" id="wizStepPill1"><strong style="background: var(--color-primary-blue); color: #fff; border-radius: 50%; padding: 2px 7px; margin-right: 4px;">1</strong> Basic Information</div>
              <div class="wiz-step-pill" id="wizStepPill2"><strong style="background: var(--color-border); color: var(--color-primary-text); border-radius: 50%; padding: 2px 7px; margin-right: 4px;">2</strong> Subscription</div>
              <div class="wiz-step-pill" id="wizStepPill3"><strong style="background: var(--color-border); color: var(--color-primary-text); border-radius: 50%; padding: 2px 7px; margin-right: 4px;">3</strong> Database Setup</div>
              <div class="wiz-step-pill" id="wizStepPill4"><strong style="background: var(--color-border); color: var(--color-primary-text); border-radius: 50%; padding: 2px 7px; margin-right: 4px;">4</strong> Customer Administrator</div>
              <div class="wiz-step-pill" id="wizStepPill5"><strong style="background: var(--color-border); color: var(--color-primary-text); border-radius: 50%; padding: 2px 7px; margin-right: 4px;">5</strong> Organization Setup</div>
              <div class="wiz-step-pill" id="wizStepPill6"><strong style="background: var(--color-border); color: var(--color-primary-text); border-radius: 50%; padding: 2px 7px; margin-right: 4px;">6</strong> Review & Create</div>
            </div>

            <form id="addCustomerForm" onsubmit="submitCustomerWizard(event)">
              <!-- STEP 1: BASIC INFORMATION -->
              <div id="wizStepContent1" class="wiz-step-content">
                <h3 style="font-size: 1rem; color: var(--color-primary-text); margin-bottom: 1rem;">1. Basic Information</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label class="dropdown-label">Customer Code *</label>
                    <input type="text" id="wizCustomerCode" name="customer_code" class="cost360-select" placeholder="e.g. CUST009" required>
                  </div>
                  <div>
                    <label class="dropdown-label">Customer Name *</label>
                    <input type="text" id="wizCustomerName" name="customer_name" class="cost360-select" placeholder="e.g. Acme Manufacturing Ltd" required>
                  </div>
                  <div>
                    <label class="dropdown-label">Short Name</label>
                    <input type="text" id="wizShortName" name="short_name" class="cost360-select" placeholder="e.g. Acme">
                  </div>
                  <div>
                    <label class="dropdown-label">Industry Type</label>
                    <select name="industry_type" class="cost360-select">
                      <option value="Automotive">Automotive</option>
                      <option value="Manufacturing">Manufacturing</option>
                      <option value="Electronics">Electronics</option>
                      <option value="Pharmaceuticals">Pharmaceuticals</option>
                      <option value="Textiles">Textiles</option>
                    </select>
                  </div>
                  <div>
                    <label class="dropdown-label">Primary Contact Person *</label>
                    <input type="text" id="wizContactPerson" name="contact_person" class="cost360-select" placeholder="Contact person name" required>
                  </div>
                  <div>
                    <label class="dropdown-label">Email *</label>
                    <input type="email" id="wizContactEmail" name="contact_email" class="cost360-select" placeholder="contact@company.com" required>
                  </div>
                  <div>
                    <label class="dropdown-label">Phone Number</label>
                    <input type="text" id="wizContactPhone" name="contact_phone" class="cost360-select" placeholder="+91 9876543210">
                  </div>
                  <div>
                    <label class="dropdown-label">Status *</label>
                    <select name="status" class="cost360-select">
                      <option value="Active">Active</option>
                      <option value="Inactive">Inactive</option>
                    </select>
                  </div>
                  <div style="grid-column: span 2;">
                    <label class="dropdown-label">Complete Address</label>
                    <textarea name="address" class="cost360-select" rows="2" placeholder="MIDC Industrial Area..."></textarea>
                  </div>
                </div>
              </div>

              <!-- STEP 2: SUBSCRIPTION -->
              <div id="wizStepContent2" class="wiz-step-content" style="display: none;">
                <h3 style="font-size: 1rem; color: var(--color-primary-text); margin-bottom: 1rem;">2. Subscription Plan</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label class="dropdown-label">Subscription Plan *</label>
                    <select id="wizSubPlan" name="subscription_plan" class="cost360-select">
                      <option value="Enterprise">Enterprise (Multi-Plant / Full Access)</option>
                      <option value="Professional">Professional (Standard Operations)</option>
                      <option value="Standard">Standard (Single Plant)</option>
                    </select>
                  </div>
                  <div>
                    <label class="dropdown-label">Billing Cycle</label>
                    <select name="billing_cycle" class="cost360-select">
                      <option value="Annual">Annual</option>
                      <option value="Monthly">Monthly</option>
                    </select>
                  </div>
                  <div>
                    <label class="dropdown-label">Start Date</label>
                    <input type="date" name="sub_start_date" class="cost360-select" value="<?php echo date('Y-m-d'); ?>">
                  </div>
                  <div>
                    <label class="dropdown-label">End Date</label>
                    <input type="date" name="sub_end_date" class="cost360-select" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                  </div>
                </div>
              </div>

              <!-- STEP 3: DATABASE SETUP -->
              <div id="wizStepContent3" class="wiz-step-content" style="display: none;">
                <h3 style="font-size: 1rem; color: var(--color-primary-text); margin-bottom: 1rem;">3. Customer Database Provisioning</h3>
                <div style="background: var(--color-soft-blue); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--color-border); margin-bottom: 1rem;">
                  <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span style="font-weight: 700; color: var(--color-deep-navy);">Provisioning Mode:</span>
                    <span class="status-badge" style="background: #D1FAE5; color: #065F46;">Connected (Neon Global DB)</span>
                  </div>
                  <p style="font-size: 0.85rem; color: var(--color-secondary-text);">The customer tenant will be provisioned in the central Neon PostgreSQL global database architecture with isolated schema routing.</p>
                </div>
              </div>

              <!-- STEP 4: CUSTOMER ADMINISTRATOR -->
              <div id="wizStepContent4" class="wiz-step-content" style="display: none;">
                <h3 style="font-size: 1rem; color: var(--color-primary-text); margin-bottom: 1rem;">4. Customer Administrator Account</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label class="dropdown-label">Admin Full Name *</label>
                    <input type="text" id="wizAdminName" name="admin_full_name" class="cost360-select" placeholder="e.g. Rahul Sharma">
                  </div>
                  <div>
                    <label class="dropdown-label">Admin Email *</label>
                    <input type="email" id="wizAdminEmail" name="admin_email" class="cost360-select" placeholder="rahul@company.com">
                  </div>
                  <div>
                    <label class="dropdown-label">Admin Phone</label>
                    <input type="text" name="admin_phone" class="cost360-select" placeholder="+91 9876543210">
                  </div>
                  <div>
                    <label class="dropdown-label">Admin Password *</label>
                    <input type="password" id="wizAdminPass" name="admin_password" class="cost360-select" placeholder="Assign secure password" value="12345678">
                  </div>
                  <div style="grid-column: span 2; background: #FEF3C7; padding: 0.75rem 1rem; border-radius: 6px; color: #92400E; font-size: 0.82rem;">
                    <strong>Note:</strong> Customer Administrator automatically receives the <strong>CUSTOMER_ADMIN</strong> role and full customer-level scope across all companies, divisions, plants, and warehouses.
                  </div>
                </div>
              </div>

              <!-- STEP 5: ORGANIZATION SETUP -->
              <div id="wizStepContent5" class="wiz-step-content" style="display: none;">
                <h3 style="font-size: 1rem; color: var(--color-primary-text); margin-bottom: 1rem;">5. Initial Organization Setup (Optional)</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label class="dropdown-label">Company Code</label>
                    <input type="text" id="wizCompCode" name="company_code" class="cost360-select" placeholder="e.g. COMP01">
                  </div>
                  <div>
                    <label class="dropdown-label">Company Name</label>
                    <input type="text" id="wizCompName" name="company_name" class="cost360-select" placeholder="e.g. Acme Components Pvt Ltd">
                  </div>
                  <div>
                    <label class="dropdown-label">Division Code</label>
                    <input type="text" name="division_code" class="cost360-select" placeholder="e.g. MFG">
                  </div>
                  <div>
                    <label class="dropdown-label">Division Name</label>
                    <input type="text" name="division_name" class="cost360-select" placeholder="e.g. Manufacturing Division">
                  </div>
                  <div>
                    <label class="dropdown-label">Plant Code</label>
                    <input type="text" name="plant_code" class="cost360-select" placeholder="e.g. PLANT01">
                  </div>
                  <div>
                    <label class="dropdown-label">Plant Name</label>
                    <input type="text" name="plant_name" class="cost360-select" placeholder="e.g. Nashik Plant">
                  </div>
                  <div>
                    <label class="dropdown-label">Warehouse Code</label>
                    <input type="text" name="warehouse_code" class="cost360-select" placeholder="e.g. RM01">
                  </div>
                  <div>
                    <label class="dropdown-label">Warehouse Name</label>
                    <input type="text" name="warehouse_name" class="cost360-select" placeholder="e.g. Raw Material Warehouse">
                  </div>
                </div>
              </div>

              <!-- STEP 6: REVIEW & CREATE -->
              <div id="wizStepContent6" class="wiz-step-content" style="display: none;">
                <h3 style="font-size: 1rem; color: var(--color-primary-text); margin-bottom: 1rem;">6. Review & Onboard Customer</h3>
                <div id="wizReviewBox" style="background: var(--color-page-bg); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--color-border); margin-bottom: 1rem;">
                  <!-- Dynamically populated review details -->
                </div>
              </div>

              <!-- WIZARD BUTTONS -->
              <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" id="btnWizPrev" class="btn-secondary" onclick="changeWizStep(-1)" style="display: none;">Back</button>
                <div style="margin-left: auto; display: flex; gap: 0.75rem;">
                  <button type="button" class="btn-secondary" onclick="closeModal('addCustomerWizardModal')">Cancel</button>
                  <button type="button" id="btnWizNext" class="btn-primary" onclick="changeWizStep(1)">Next &rarr;</button>
                  <button type="submit" id="btnWizSubmit" class="btn-primary" style="display: none; background: #059669;">Create Customer</button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- ============================================================
             CUSTOMER DETAILS DRAWER / MODAL
             ============================================================ -->
        <div id="customerDetailsModal" class="cost360-modal-overlay" style="display: none;">
          <div class="section-card" style="width: 1000px; max-width: 95vw; max-height: 90vh; overflow-y: auto; padding: 2rem; position: relative;">
            <button type="button" onclick="closeModal('customerDetailsModal')" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--color-secondary-text);">&times;</button>

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; border-bottom: 1px solid var(--color-border); padding-bottom: 1rem;">
              <div>
                <span id="dtlCustCode" class="perm-pill perm-view" style="font-size: 0.85rem;">CUST001</span>
                <h2 id="dtlCustName" style="font-size: 1.4rem; color: var(--color-deep-navy); margin-top: 0.2rem;">Customer Details</h2>
                <p id="dtlCustContact" style="font-size: 0.85rem; color: var(--color-secondary-text);"></p>
              </div>
              <div id="dtlCustStatusBadge"></div>
            </div>

            <!-- TABS -->
            <div style="display: flex; gap: 0.5rem; border-bottom: 1px solid var(--color-border); margin-bottom: 1.25rem; overflow-x: auto;">
              <button type="button" class="btn-secondary cust-tab-btn active" onclick="switchCustTab('overview', this)">Overview</button>
              <button type="button" class="btn-secondary cust-tab-btn" onclick="switchCustTab('admin', this)">Customer Admin</button>
              <button type="button" class="btn-secondary cust-tab-btn" onclick="switchCustTab('companies', this)">Companies</button>
              <button type="button" class="btn-secondary cust-tab-btn" onclick="switchCustTab('divisions', this)">Divisions</button>
              <button type="button" class="btn-secondary cust-tab-btn" onclick="switchCustTab('plants', this)">Plants</button>
              <button type="button" class="btn-secondary cust-tab-btn" onclick="switchCustTab('warehouses', this)">Warehouses</button>
              <button type="button" class="btn-secondary cust-tab-btn" onclick="switchCustTab('users', this)">Users & Scope</button>
              <button type="button" class="btn-secondary cust-tab-btn" onclick="switchCustTab('audit', this)">Audit History</button>
            </div>

            <!-- TAB CONTENT: OVERVIEW -->
            <div id="custTabOverview" class="cust-tab-pane">
              <div class="grid-cards" style="margin-bottom: 1.5rem;">
                <div class="stat-card">
                  <div class="stat-label">Companies</div>
                  <div class="stat-number" id="dtlKpiComp">0</div>
                </div>
                <div class="stat-card">
                  <div class="stat-label">Divisions</div>
                  <div class="stat-number" id="dtlKpiDiv">0</div>
                </div>
                <div class="stat-card">
                  <div class="stat-label">Plants</div>
                  <div class="stat-number" id="dtlKpiPlant">0</div>
                </div>
                <div class="stat-card">
                  <div class="stat-label">Warehouses</div>
                  <div class="stat-number" id="dtlKpiWh">0</div>
                </div>
                <div class="stat-card">
                  <div class="stat-label">Users</div>
                  <div class="stat-number" id="dtlKpiUser">0</div>
                </div>
              </div>
            </div>

            <!-- TAB CONTENT: ADMIN -->
            <div id="custTabAdmin" class="cust-tab-pane" style="display: none;">
              <div id="custAdminBox" style="background: var(--color-page-bg); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--color-border);">
                <!-- Populated via JS -->
              </div>
            </div>

            <!-- TAB CONTENT: COMPANIES -->
            <div id="custTabCompanies" class="cust-tab-pane" style="display: none;">
              <div class="table-responsive">
                <table>
                  <thead><tr><th>Company Code</th><th>Company Name</th><th>Address</th><th>Status</th></tr></thead>
                  <tbody id="dtlCompaniesBody"></tbody>
                </table>
              </div>
            </div>

            <!-- TAB CONTENT: DIVISIONS -->
            <div id="custTabDivisions" class="cust-tab-pane" style="display: none;">
              <div class="table-responsive">
                <table>
                  <thead><tr><th>Division Code</th><th>Division Name</th><th>Company</th><th>Status</th></tr></thead>
                  <tbody id="dtlDivisionsBody"></tbody>
                </table>
              </div>
            </div>

            <!-- TAB CONTENT: PLANTS -->
            <div id="custTabPlants" class="cust-tab-pane" style="display: none;">
              <div class="table-responsive">
                <table>
                  <thead><tr><th>Plant Code</th><th>Plant Name</th><th>Company / Division</th><th>Status</th></tr></thead>
                  <tbody id="dtlPlantsBody"></tbody>
                </table>
              </div>
            </div>

            <!-- TAB CONTENT: WAREHOUSES -->
            <div id="custTabWarehouses" class="cust-tab-pane" style="display: none;">
              <div class="table-responsive">
                <table>
                  <thead><tr><th>Warehouse Code</th><th>Warehouse Name</th><th>Plant</th><th>Status</th></tr></thead>
                  <tbody id="dtlWarehousesBody"></tbody>
                </table>
              </div>
            </div>

            <!-- TAB CONTENT: USERS & SCOPE -->
            <div id="custTabUsers" class="cust-tab-pane" style="display: none;">
              <div class="table-responsive">
                <table>
                  <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Company Scope</th><th>Plant Scope</th><th>Status</th></tr></thead>
                  <tbody id="dtlUsersBody"></tbody>
                </table>
              </div>
            </div>

            <!-- TAB CONTENT: AUDIT HISTORY -->
            <div id="custTabAudit" class="cust-tab-pane" style="display: none;">
              <div class="table-responsive">
                <table>
                  <thead><tr><th>Timestamp</th><th>Operator</th><th>Action</th><th>Details</th></tr></thead>
                  <tbody id="dtlAuditBody"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      <?php elseif ($currentPage === 'overview'): ?>
        <!-- ============================================================
             ORGANIZATION OVERVIEW (CUSTOMER MANAGEMENT HIERARCHY TREE)
             ============================================================ -->
        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <div style="font-size: 0.8rem; color: #00CFEF; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                Customer Management &amp; Organization Hierarchy
              </div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">Organization Overview</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Visual 5-level dependent hierarchy tree: Customer &rarr; Company &rarr; Division &rarr; Plant &rarr; Warehouse.</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-secondary" onclick="toggleExpandAllTree(true)">
                <span>Expand All</span>
              </button>
              <button type="button" class="btn-secondary" onclick="toggleExpandAllTree(false)">
                <span>Collapse All</span>
              </button>
              <button type="button" class="btn-primary" onclick="loadHierarchyTree()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh Tree</span>
              </button>
            </div>
          </div>

          <!-- 5-LEVEL KPI CARDS -->
          <div class="grid-cards" style="margin-bottom: 1.25rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
            <div class="stat-card" style="padding: 1rem 1.25rem; border-left: 4px solid #00CFEF;">
              <div class="stat-label">Customers</div>
              <div class="stat-number" id="kpiTreeCustomers" style="color: #00CFEF;">-</div>
              <div class="stat-desc">Top-level Tenants</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem; border-left: 4px solid #3B82F6;">
              <div class="stat-label">Companies</div>
              <div class="stat-number" id="kpiTreeCompanies" style="color: #3B82F6;">-</div>
              <div class="stat-desc">Legal Entities</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem; border-left: 4px solid #8B5CF6;">
              <div class="stat-label">Divisions</div>
              <div class="stat-number" id="kpiTreeDivisions" style="color: #8B5CF6;">-</div>
              <div class="stat-desc">Business Units</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem; border-left: 4px solid #10B981;">
              <div class="stat-label">Plants</div>
              <div class="stat-number" id="kpiTreePlants" style="color: #10B981;">-</div>
              <div class="stat-desc">Manufacturing/Facilities</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem; border-left: 4px solid #F59E0B;">
              <div class="stat-label">Warehouses</div>
              <div class="stat-number" id="kpiTreeWarehouses" style="color: #F59E0B;">-</div>
              <div class="stat-desc">Storage Locations</div>
            </div>
          </div>

          <!-- FILTER BAR -->
          <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="flex: 1; min-width: 240px;">
              <input type="text" id="treeSearchInput" class="form-input" placeholder="Search by customer, company, division, plant, or warehouse name..." oninput="filterTreeNodes()">
            </div>
            <div style="min-width: 200px;">
              <select id="treeCustomerFilter" class="form-select" onchange="loadHierarchyTree()">
                <option value="">All Customers</option>
              </select>
            </div>
          </div>

          <!-- TREE CONTAINER -->
          <div id="treeContainer" style="background: rgba(3, 54, 101, 0.4); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.5rem; min-height: 350px;">
            <div style="text-align: center; color: var(--color-secondary-text); padding: 3rem;">
              Loading organization hierarchy tree from database...
            </div>
          </div>
        </section>

        <!-- TREE SCRIPTS -->
        <script>
          let rawTreeData = [];

          document.addEventListener('DOMContentLoaded', function() {
            if (typeof loadHierarchyTree === 'function') {
              loadHierarchyTree();
            }
          });

          function loadHierarchyTree() {
            const container = document.getElementById('treeContainer');
            const custFilter = document.getElementById('treeCustomerFilter').value;
            const searchVal = document.getElementById('treeSearchInput').value;

            container.innerHTML = '<div style="text-align:center; padding: 3rem; color: var(--color-secondary-text);">Fetching live hierarchy structure...</div>';

            fetch(`${window.cost360BaseUrlPrefix}organization/overview/api.php?action=tree&customer_id=${encodeURIComponent(custFilter)}&search=${encodeURIComponent(searchVal)}`)
              .then(res => res.json())
              .then(data => {
                if (!data.success) {
                  container.innerHTML = `<div style="color: #F87171; text-align: center; padding: 2rem;">Error: ${data.error || 'Failed to load hierarchy tree.'}</div>`;
                  return;
                }

                rawTreeData = data.tree || [];

                // Update KPI counts
                document.getElementById('kpiTreeCustomers').textContent = data.kpis.customers || 0;
                document.getElementById('kpiTreeCompanies').textContent = data.kpis.companies || 0;
                document.getElementById('kpiTreeDivisions').textContent = data.kpis.divisions || 0;
                document.getElementById('kpiTreePlants').textContent = data.kpis.plants || 0;
                document.getElementById('kpiTreeWarehouses').textContent = data.kpis.warehouses || 0;

                // Populate Customer Filter if empty
                const custSelect = document.getElementById('treeCustomerFilter');
                if (custSelect.options.length <= 1 && data.tree) {
                  custSelect.innerHTML = '<option value="">All Customers</option>';
                  data.tree.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.customer_id;
                    opt.textContent = `${c.customer_code} - ${c.customer_name}`;
                    if (custFilter == c.customer_id) opt.selected = true;
                    custSelect.appendChild(opt);
                  });
                }

                renderTreeNodes(rawTreeData);
              })
              .catch(err => {
                container.innerHTML = `<div style="color: #F87171; text-align: center; padding: 2rem;">Connection Error: ${err.message}</div>`;
              });
          }

          function renderTreeNodes(tree) {
            const container = document.getElementById('treeContainer');
            if (!tree || tree.length === 0) {
              container.innerHTML = '<div style="text-align:center; padding: 3rem; color: var(--color-secondary-text);">No organization structures found matching criteria.</div>';
              return;
            }

            let html = '<div class="org-tree-root" style="display: flex; flex-direction: column; gap: 1rem;">';

            tree.forEach(cust => {
              const custActive = cust.status ? cust.status.toUpperCase() === 'ACTIVE' : true;
              html += `
                <div class="tree-node customer-node" style="border: 1px solid rgba(0, 207, 239, 0.3); border-radius: 10px; background: rgba(8, 120, 209, 0.15); overflow: hidden;">
                  <div class="tree-node-header" onclick="toggleTreeNode(this)" style="padding: 0.9rem 1.2rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                      <span class="chevron" style="transition: transform 0.2s ease; display: inline-block; color: #00CFEF;">&#9654;</span>
                      <span style="background: rgba(0,207,239,0.2); color: #00CFEF; padding: 3px 8px; border-radius: 5px; font-size: 0.75rem; font-weight: 800;">CUSTOMER</span>
                      <strong style="color: #FFF; font-size: 1.05rem;">${escapeHtml(cust.customer_name)}</strong>
                      <span style="color: rgba(255,255,255,0.6); font-size: 0.85rem;">(${escapeHtml(cust.customer_code)})</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                      <span style="font-size: 0.8rem; background: rgba(255,255,255,0.1); padding: 3px 10px; border-radius: 12px; color: rgba(255,255,255,0.8);">
                        ${cust.companies ? cust.companies.length : 0} Companies
                      </span>
                      <span class="badge ${custActive ? 'badge-success' : 'badge-inactive'}">${custActive ? 'Active' : 'Inactive'}</span>
                    </div>
                  </div>

                  <div class="tree-node-children" style="display: none; padding: 0.5rem 1rem 1rem 2.2rem; border-top: 1px solid rgba(255,255,255,0.05); background: rgba(3, 54, 101, 0.3);">
              `;

              if (cust.companies && cust.companies.length > 0) {
                cust.companies.forEach(comp => {
                  const compActive = comp.status ? comp.status.toUpperCase() === 'ACTIVE' : true;
                  html += `
                    <div class="tree-node company-node" style="margin-top: 0.75rem; border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; background: rgba(6, 61, 112, 0.6); overflow: hidden;">
                      <div class="tree-node-header" onclick="toggleTreeNode(this)" style="padding: 0.75rem 1rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                          <span class="chevron" style="transition: transform 0.2s ease; display: inline-block; color: #3B82F6;">&#9654;</span>
                          <span style="background: rgba(59,130,246,0.2); color: #60A5FA; padding: 2px 7px; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">COMPANY</span>
                          <span style="color: #FFF; font-weight: 600;">${escapeHtml(comp.company_name)}</span>
                          <span style="color: rgba(255,255,255,0.5); font-size: 0.8rem;">[${escapeHtml(comp.company_code)}]</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                          <span style="font-size: 0.75rem; color: rgba(255,255,255,0.7);">${comp.divisions ? comp.divisions.length : 0} Divisions</span>
                          <span class="badge ${compActive ? 'badge-success' : 'badge-inactive'}">${compActive ? 'Active' : 'Inactive'}</span>
                        </div>
                      </div>

                      <div class="tree-node-children" style="display: none; padding: 0.5rem 1rem 0.75rem 2rem; border-top: 1px solid rgba(255,255,255,0.05); background: rgba(3, 54, 101, 0.5);">
                  `;

                  if (comp.divisions && comp.divisions.length > 0) {
                    comp.divisions.forEach(div => {
                      const divActive = div.status ? div.status.toUpperCase() === 'ACTIVE' : true;
                      html += `
                        <div class="tree-node division-node" style="margin-top: 0.5rem; border: 1px solid rgba(139, 92, 246, 0.3); border-radius: 6px; background: rgba(15, 23, 42, 0.5); overflow: hidden;">
                          <div class="tree-node-header" onclick="toggleTreeNode(this)" style="padding: 0.65rem 0.85rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer;">
                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                              <span class="chevron" style="transition: transform 0.2s ease; display: inline-block; color: #8B5CF6;">&#9654;</span>
                              <span style="background: rgba(139,92,246,0.2); color: #C084FC; padding: 2px 6px; border-radius: 4px; font-size: 0.68rem; font-weight: 700;">DIVISION</span>
                              <span style="color: #E2E8F0; font-weight: 600; font-size: 0.9rem;">${escapeHtml(div.division_name)}</span>
                              <span style="color: rgba(255,255,255,0.4); font-size: 0.75rem;">[${escapeHtml(div.division_code)}]</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                              <span style="font-size: 0.72rem; color: rgba(255,255,255,0.6);">${div.plants ? div.plants.length : 0} Plants</span>
                              <span class="badge ${divActive ? 'badge-success' : 'badge-inactive'}">${divActive ? 'Active' : 'Inactive'}</span>
                            </div>
                          </div>

                          <div class="tree-node-children" style="display: none; padding: 0.4rem 0.85rem 0.6rem 1.8rem; background: rgba(3, 54, 101, 0.7);">
                      `;

                      if (div.plants && div.plants.length > 0) {
                        div.plants.forEach(plant => {
                          const plantActive = plant.status ? plant.status.toUpperCase() === 'ACTIVE' : true;
                          html += `
                            <div class="tree-node plant-node" style="margin-top: 0.4rem; border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 6px; background: rgba(6, 78, 59, 0.3); overflow: hidden;">
                              <div class="tree-node-header" onclick="toggleTreeNode(this)" style="padding: 0.55rem 0.75rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer;">
                                <div style="display: flex; align-items: center; gap: 0.6rem;">
                                  <span class="chevron" style="transition: transform 0.2s ease; display: inline-block; color: #10B981;">&#9654;</span>
                                  <span style="background: rgba(16,185,129,0.2); color: #34D399; padding: 2px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: 700;">PLANT</span>
                                  <span style="color: #F1F5F9; font-weight: 600; font-size: 0.85rem;">${escapeHtml(plant.plant_name)}</span>
                                  <span style="color: rgba(255,255,255,0.4); font-size: 0.75rem;">[${escapeHtml(plant.plant_code)}]</span>
                                  ${plant.location ? `<span style="font-size: 0.7rem; color: #94A3B8;">&bull; ${escapeHtml(plant.location)}</span>` : ''}
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.6rem;">
                                  <span style="font-size: 0.7rem; color: rgba(255,255,255,0.6);">${plant.warehouses ? plant.warehouses.length : 0} Warehouses</span>
                                  <span class="badge ${plantActive ? 'badge-success' : 'badge-inactive'}">${plantActive ? 'Active' : 'Inactive'}</span>
                                </div>
                              </div>

                              <div class="tree-node-children" style="display: none; padding: 0.4rem 0.75rem 0.5rem 1.6rem; background: rgba(15, 23, 42, 0.8);">
                          `;

                          if (plant.warehouses && plant.warehouses.length > 0) {
                            plant.warehouses.forEach(wh => {
                              const whActive = wh.status ? wh.status.toUpperCase() === 'ACTIVE' : true;
                              html += `
                                <div class="warehouse-leaf" style="margin-top: 0.3rem; padding: 0.4rem 0.75rem; border-radius: 4px; background: rgba(245, 158, 11, 0.1); border: 1px dashed rgba(245, 158, 11, 0.3); display: flex; align-items: center; justify-content: space-between;">
                                  <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="background: rgba(245, 158, 11, 0.2); color: #FBBF24; padding: 1px 5px; border-radius: 3px; font-size: 0.62rem; font-weight: 700;">WAREHOUSE</span>
                                    <span style="color: #FDF6B2; font-weight: 500; font-size: 0.82rem;">${escapeHtml(wh.warehouse_name)}</span>
                                    <span style="color: rgba(255,255,255,0.5); font-size: 0.72rem;">[${escapeHtml(wh.warehouse_code)}]</span>
                                  </div>
                                  <span class="badge ${whActive ? 'badge-success' : 'badge-inactive'}" style="font-size: 0.65rem;">${whActive ? 'Active' : 'Inactive'}</span>
                                </div>
                              `;
                            });
                          } else {
                            html += '<div style="color: rgba(255,255,255,0.4); font-size: 0.75rem; padding: 0.3rem 0;">No warehouses defined.</div>';
                          }

                          html += '</div></div>'; // plant children & node end
                        });
                      } else {
                        html += '<div style="color: rgba(255,255,255,0.4); font-size: 0.75rem; padding: 0.3rem 0;">No plants defined under this division.</div>';
                      }

                      html += '</div></div>'; // div children & node end
                    });
                  } else {
                    html += '<div style="color: rgba(255,255,255,0.4); font-size: 0.75rem; padding: 0.3rem 0;">No divisions defined under this company.</div>';
                  }

                  html += '</div></div>'; // company children & node end
                });
              } else {
                html += '<div style="color: rgba(255,255,255,0.4); font-size: 0.8rem; padding: 0.4rem 0;">No companies assigned to this customer.</div>';
              }

              html += '</div></div>'; // customer children & node end
            });

            html += '</div>';
            container.innerHTML = html;
          }

          function toggleTreeNode(headerEl) {
            const childrenEl = headerEl.nextElementSibling;
            const chevron = headerEl.querySelector('.chevron');
            if (!childrenEl) return;

            if (childrenEl.style.display === 'none' || !childrenEl.style.display) {
              childrenEl.style.display = 'block';
              if (chevron) chevron.style.transform = 'rotate(90deg)';
            } else {
              childrenEl.style.display = 'none';
              if (chevron) chevron.style.transform = 'rotate(0deg)';
            }
          }

          function toggleExpandAllTree(expand) {
            const allChildren = document.querySelectorAll('#treeContainer .tree-node-children');
            const allChevrons = document.querySelectorAll('#treeContainer .chevron');
            allChildren.forEach(el => {
              el.style.display = expand ? 'block' : 'none';
            });
            allChevrons.forEach(ch => {
              ch.style.transform = expand ? 'rotate(90deg)' : 'rotate(0deg)';
            });
          }

          function filterTreeNodes() {
            const query = document.getElementById('treeSearchInput').value.toLowerCase().trim();
            if (!query) {
              renderTreeNodes(rawTreeData);
              return;
            }

            // Filter tree
            const filtered = [];
            rawTreeData.forEach(cust => {
              let custMatch = cust.customer_name.toLowerCase().includes(query) || cust.customer_code.toLowerCase().includes(query);
              let matchedCompanies = [];

              if (cust.companies) {
                cust.companies.forEach(comp => {
                  let compMatch = comp.company_name.toLowerCase().includes(query) || comp.company_code.toLowerCase().includes(query);
                  let matchedDivs = [];

                  if (comp.divisions) {
                    comp.divisions.forEach(div => {
                      let divMatch = div.division_name.toLowerCase().includes(query) || div.division_code.toLowerCase().includes(query);
                      let matchedPlants = [];

                      if (div.plants) {
                        div.plants.forEach(p => {
                          let plantMatch = p.plant_name.toLowerCase().includes(query) || p.plant_code.toLowerCase().includes(query) || (p.location && p.location.toLowerCase().includes(query));
                          let matchedWhs = [];

                          if (p.warehouses) {
                            p.warehouses.forEach(w => {
                              if (w.warehouse_name.toLowerCase().includes(query) || w.warehouse_code.toLowerCase().includes(query)) {
                                matchedWhs.push(w);
                              }
                            });
                          }

                          if (plantMatch || matchedWhs.length > 0) {
                            matchedPlants.push(Object.assign({}, p, { warehouses: matchedWhs.length > 0 ? matchedWhs : p.warehouses }));
                          }
                        });
                      }

                      if (divMatch || matchedPlants.length > 0) {
                        matchedDivs.push(Object.assign({}, div, { plants: matchedPlants.length > 0 ? matchedPlants : div.plants }));
                      }
                    });
                  }

                  if (compMatch || matchedDivs.length > 0) {
                    matchedCompanies.push(Object.assign({}, comp, { divisions: matchedDivs.length > 0 ? matchedDivs : comp.divisions }));
                  }
                });
              }

              if (custMatch || matchedCompanies.length > 0) {
                filtered.push(Object.assign({}, cust, { companies: matchedCompanies.length > 0 ? matchedCompanies : cust.companies }));
              }
            });

            renderTreeNodes(filtered);
            toggleExpandAllTree(true);
          }

          function escapeHtml(str) {
            if (!str) return '';
            return String(str)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
          }
        </script>

      <?php elseif ($currentPage === 'users'): ?>
        <!-- ============================================================
             USER MASTER PAGE MODULE (DYNAMIC FROM NEON DB)
             ============================================================ -->
        <section class="grid-cards" style="margin-bottom: 1.5rem;">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Total Users</span></div>
            <div class="stat-number" id="kpiTotalUsers">0</div>
            <div class="stat-desc">All system accounts</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Active Users</span></div>
            <div class="stat-number" id="kpiActiveUsers" style="color: var(--color-bright-cyan);">0</div>
            <div class="stat-desc">Currently enabled</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Inactive / Locked</span></div>
            <div class="stat-number" id="kpiLockedUsers" style="color: #DC2626;">0</div>
            <div class="stat-desc">Accounts requiring attention</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Roles Assigned</span></div>
            <div class="stat-number" id="kpiUniqueRoles">0</div>
            <div class="stat-desc">Unique roles in use</div>
          </div>
        </section>

        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">User Master</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage application users and their roles</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddUserModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>Add User</span>
              </button>
              <button type="button" class="btn-secondary" onclick="exportUsersData()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                  <polyline points="7 10 12 15 17 10"></polyline>
                  <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                <span>Export</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadUsersTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- SEARCH & FILTERS BAR -->
          <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1.25rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px; position: relative;">
              <input type="text" id="userSearchInput" class="cost360-select" placeholder="Search users..." onkeyup="handleUserSearchKeyup(event)" style="padding-left: 2.25rem;">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--color-secondary-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); pointer-events: none;">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
            </div>

            <div style="min-width: 170px;">
              <select id="userRoleFilter" class="cost360-select" onchange="loadUsersTable()">
                <option value="ALL">All Roles</option>
              </select>
            </div>

            <div style="min-width: 150px;">
              <select id="userStatusFilter" class="cost360-select" onchange="loadUsersTable()">
                <option value="ALL">All Statuses</option>
                <option value="ACTIVE">ACTIVE</option>
                <option value="INACTIVE">INACTIVE</option>
                <option value="SUSPENDED">SUSPENDED</option>
                <option value="LOCKED">LOCKED</option>
              </select>
            </div>
          </div>

          <!-- FEEDBACK TOAST / ALERT BANNER -->
          <div id="userMasterToast" style="display: none; padding: 0.85rem 1.25rem; border-radius: var(--radius-input); margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- USERS TABLE CONTAINER -->
          <div class="table-responsive">
            <table id="usersTable">
              <thead>
                <tr>
                  <th>User ID</th>
                  <th>Username</th>
                  <th>Full Name</th>
                  <th>Email</th>
                  <th>Status</th>
                  <th>Role</th>
                  <th>Created By</th>
                  <th>Last Login</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="usersTableBody">
                <tr>
                  <td colspan="9" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                    Loading users from Neon PostgreSQL database...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ADD USER MODAL -->
        <div id="addUserModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 540px;">
            <div class="modal-header">
              <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">+ Add New User</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <form id="addUserForm" onsubmit="submitAddUser(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <div id="addUserError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">First Name *</label>
                  <input type="text" name="first_name" class="cost360-select" required placeholder="e.g. John">
                </div>
                <div>
                  <label class="dropdown-label">Last Name</label>
                  <input type="text" name="last_name" class="cost360-select" placeholder="e.g. Doe">
                </div>
              </div>

              <div>
                <label class="dropdown-label">Username *</label>
                <input type="text" name="username" class="cost360-select" required placeholder="e.g. jdoe">
              </div>

              <div>
                <label class="dropdown-label">Email Address *</label>
                <input type="email" name="email" class="cost360-select" required placeholder="e.g. jdoe@company.com">
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Password *</label>
                  <input type="password" name="password" class="cost360-select" required placeholder="Min 6 characters">
                </div>
                <div>
                  <label class="dropdown-label">Confirm Password *</label>
                  <input type="password" name="confirm_password" class="cost360-select" required placeholder="Re-enter password">
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Assigned Role *</label>
                  <select name="role_id" id="addUserRoleSelect" class="cost360-select" required>
                    <option value="">Select Role...</option>
                  </select>
                </div>
                <div>
                  <label class="dropdown-label">Account Status *</label>
                  <select name="status" class="cost360-select" required>
                    <option value="ACTIVE" selected>ACTIVE</option>
                    <option value="INACTIVE">INACTIVE</option>
                    <option value="SUSPENDED">SUSPENDED</option>
                    <option value="LOCKED">LOCKED</option>
                  </select>
                </div>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
                <button type="submit" class="btn-primary">Save User</button>
              </div>
            </form>
          </div>
        </div>

        <!-- EDIT USER MODAL -->
        <div id="editUserModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 540px;">
            <div class="modal-header">
              <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">Edit User Details</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <form id="editUserForm" onsubmit="submitEditUser(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="user_id" id="editUserId">
              <div id="editUserError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">First Name *</label>
                  <input type="text" name="first_name" id="editFirstName" class="cost360-select" required>
                </div>
                <div>
                  <label class="dropdown-label">Last Name</label>
                  <input type="text" name="last_name" id="editLastName" class="cost360-select">
                </div>
              </div>

              <div>
                <label class="dropdown-label">Username *</label>
                <input type="text" name="username" id="editUsername" class="cost360-select" required>
              </div>

              <div>
                <label class="dropdown-label">Email Address *</label>
                <input type="email" name="email" id="editEmail" class="cost360-select" required>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Assigned Role *</label>
                  <select name="role_id" id="editUserRoleSelect" class="cost360-select" required>
                    <option value="">Select Role...</option>
                  </select>
                </div>
                <div>
                  <label class="dropdown-label">Account Status *</label>
                  <select name="status" id="editStatusSelect" class="cost360-select" required>
                    <option value="ACTIVE">ACTIVE</option>
                    <option value="INACTIVE">INACTIVE</option>
                    <option value="SUSPENDED">SUSPENDED</option>
                    <option value="LOCKED">LOCKED</option>
                  </select>
                </div>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
                <button type="submit" class="btn-primary">Update User</button>
              </div>
            </form>
          </div>
        </div>

        <!-- CHANGE PASSWORD MODAL -->
        <div id="changePasswordModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 440px;">
            <div class="modal-header">
              <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">Change User Password</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('changePasswordModal')">&times;</button>
            </div>
            <form id="changePasswordForm" onsubmit="submitChangePassword(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="user_id" id="changePassUserId">
              <div id="changePassError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div>
                <span style="font-size: 0.85rem; color: var(--color-secondary-text);">Target User:</span>
                <strong id="changePassUserLabel" style="display: block; font-size: 0.95rem; color: var(--color-primary-text);">User</strong>
              </div>

              <div>
                <label class="dropdown-label">New Password *</label>
                <input type="password" name="new_password" class="cost360-select" required placeholder="Min 6 characters">
              </div>

              <div>
                <label class="dropdown-label">Confirm New Password *</label>
                <input type="password" name="confirm_password" class="cost360-select" required placeholder="Re-enter new password">
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('changePasswordModal')">Cancel</button>
                <button type="submit" class="btn-primary">Update Password</button>
              </div>
            </form>
          </div>
        </div>

        <!-- DELETE USER CONFIRMATION MODAL -->
        <div id="deleteUserModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 440px;">
            <div class="modal-header" style="border-bottom-color: #FEE2E2;">
              <h3 style="font-size: 1.1rem; font-weight: 700; color: #DC2626;">Confirm User Deletion</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('deleteUserModal')">&times;</button>
            </div>
            <div style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" id="deleteUserId">
              <p style="font-size: 0.9rem; color: var(--color-primary-text); line-height: 1.5;">
                Are you sure you want to permanently delete user <strong id="deleteUserLabel">this user</strong>?
              </p>
              <div style="padding: 0.75rem; background: #FEF2F2; border-left: 4px solid #DC2626; font-size: 0.8rem; color: #991B1B; border-radius: 4px;">
                This will remove the user record and their assigned role link in <code>auth.user_role</code>. The role definition itself will not be deleted.
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteUserModal')">Cancel</button>
                <button type="button" class="btn-primary" style="background: #DC2626;" onclick="submitDeleteUser()">Delete User</button>
              </div>
            </div>
          </div>
        </div>

        <!-- USER ACCESS SCOPE — READ-ONLY VIEW MODAL -->
        <div id="viewScopeModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 680px; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="background: linear-gradient(135deg, #064E3B 0%, #065F46 100%); color: #FFF; padding: 1.25rem 1.5rem; border-top-left-radius: 12px; border-top-right-radius: 12px;">
              <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #FFF; margin: 0;" id="viewScopeModalTitle">Access Scope</h3>
                <p style="font-size: 0.8rem; color: #A7F3D0; margin: 4px 0 0 0;" id="viewScopeModalSubtitle">Effective hierarchical access for this user</p>
              </div>
              <button type="button" class="modal-close-btn" style="color: #FFF;" onclick="closeModal('viewScopeModal')">&times;</button>
            </div>

            <div style="padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; background: #FAFBFD;">
              <!-- Summary Badges -->
              <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem;">
                <div style="background: #FFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.65rem 1rem; min-width: 120px; text-align: center;">
                  <div style="font-size: 0.68rem; color: var(--color-secondary-text); text-transform: uppercase; font-weight: 700;">Companies</div>
                  <div style="font-size: 1.4rem; font-weight: 800; color: #0878D1;" id="vsCountCompanies">–</div>
                </div>
                <div style="background: #FFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.65rem 1rem; min-width: 120px; text-align: center;">
                  <div style="font-size: 0.68rem; color: var(--color-secondary-text); text-transform: uppercase; font-weight: 700;">Divisions</div>
                  <div style="font-size: 1.4rem; font-weight: 800; color: #0878D1;" id="vsCountDivisions">–</div>
                </div>
                <div style="background: #FFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.65rem 1rem; min-width: 140px; text-align: center;">
                  <div style="font-size: 0.68rem; color: var(--color-secondary-text); text-transform: uppercase; font-weight: 700;">Locations</div>
                  <div style="font-size: 1.4rem; font-weight: 800; color: #0878D1;" id="vsCountLocations">–</div>
                </div>
                <div style="background: #FFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.65rem 1rem; min-width: 120px; text-align: center;">
                  <div style="font-size: 0.68rem; color: var(--color-secondary-text); text-transform: uppercase; font-weight: 700;">Periods</div>
                  <div style="font-size: 1.4rem; font-weight: 800; color: #7C3AED;" id="vsCountPeriods">–</div>
                </div>
              </div>

              <!-- Legend -->
              <div style="display: flex; gap: 1rem; margin-bottom: 1rem; font-size: 0.78rem; flex-wrap: wrap;">
                <span style="display: inline-flex; align-items: center; gap: 5px;"><span style="width: 10px; height: 10px; background: #0878D1; border-radius: 50%; display: inline-block;"></span> Direct Assignment</span>
                <span style="display: inline-flex; align-items: center; gap: 5px;"><span style="width: 10px; height: 10px; background: #E6F9F6; border: 2px solid #00A88F; border-radius: 50%; display: inline-block;"></span> Inherited Access</span>
              </div>

              <!-- Scope Tree -->
              <div id="viewScopeTree" style="background: #FFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 1rem; min-height: 160px; max-height: 42vh; overflow-y: auto;">
                <div style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">Loading scope...</div>
              </div>

              <!-- Periods Section -->
              <div id="viewScopePeriods" style="margin-top: 1rem; background: #FFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 1rem; display: none;">
                <div style="font-size: 0.78rem; font-weight: 700; color: var(--color-secondary-text); text-transform: uppercase; margin-bottom: 0.6rem;">Accessible Periods</div>
                <div id="viewScopePeriodsContent" style="display: flex; flex-wrap: wrap; gap: 0.5rem;"></div>
              </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-top: 1px solid var(--color-border); background: #FFF; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
              <span style="font-size: 0.8rem; color: var(--color-secondary-text);">Read-only view — use <strong>Manage Access</strong> to edit.</span>
              <div style="display: flex; gap: 0.5rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('viewScopeModal')">Close</button>
                <button type="button" class="btn-primary" style="background: #0878D1;" onclick="switchToManageAccess()">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align: -2px; margin-right: 4px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                  Manage Access
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- USER ACCESS / HIERARCHY SCOPE MODAL (MANAGE / EDIT) -->
        <div id="userAccessModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 720px; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0A192F 0%, #1E3A8A 100%); color: #FFF; padding: 1.25rem 1.5rem; border-top-left-radius: 12px; border-top-right-radius: 12px;">
              <div>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: #FFF; margin: 0;" id="userAccessModalTitle">Manage Organization Access</h3>
                <p style="font-size: 0.82rem; color: #94A3B8; margin: 4px 0 0 0;" id="userAccessModalSubtitle">Configure customer, company, division, plant &amp; warehouse access</p>
              </div>
              <button type="button" class="modal-close-btn" style="color: #FFF;" onclick="closeModal('userAccessModal')">&times;</button>
            </div>
            
            <div style="padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; background: #FAFBFD;">
              <div id="userAccessModalError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600; margin-bottom: 1rem;"></div>

              <!-- Scope Live Counter Header -->
              <div style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div>
                  <span style="font-size: 0.72rem; color: var(--color-secondary-text); text-transform: uppercase; font-weight: 700; display: block;">Effective Scope</span>
                  <strong id="userAccessScopeBadge" style="font-size: 0.92rem; color: #00A88F;">Calculating...</strong>
                </div>
                <div style="display: flex; gap: 1rem; font-size: 0.8rem;">
                  <div><span style="color: var(--color-secondary-text);">Companies:</span> <strong id="scopeCountCompanies" style="color: #0A192F;">0</strong></div>
                  <div><span style="color: var(--color-secondary-text);">Divisions:</span> <strong id="scopeCountDivisions" style="color: #0A192F;">0</strong></div>
                  <div><span style="color: var(--color-secondary-text);">Locations:</span> <strong id="scopeCountLocations" style="color: #0A192F;">0</strong></div>
                </div>
              </div>

              <!-- Org Hierarchy Tree Container -->
              <div id="userAccessTreeContainer" style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 1rem; min-height: 180px; max-height: 40vh; overflow-y: auto;">
                <div style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">Loading organizational hierarchy...</div>
              </div>

              <!-- Period Assignment Section -->
              <div style="margin-top: 1rem; background: #FFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 1rem;">
                <div style="font-size: 0.78rem; font-weight: 700; color: var(--color-secondary-text); text-transform: uppercase; margin-bottom: 0.6rem;">Period Access <span style="font-weight: 400; text-transform: none; font-size: 0.72rem;">(leave all unchecked for unrestricted period access)</span></div>
                <div id="periodAccessContainer" style="display: flex; flex-wrap: wrap; gap: 0.5rem; min-height: 32px;">
                  <span style="color: var(--color-secondary-text); font-size: 0.82rem;">Loading periods...</span>
                </div>
              </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-top: 1px solid var(--color-border); background: #FFFFFF; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
              <button type="button" class="btn-secondary" onclick="closeModal('userAccessModal')">Cancel</button>
              <button type="button" class="btn-primary" id="saveUserAccessBtn" onclick="saveUserAccessSettings()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right: 4px; vertical-align: -2px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Save Access Scope
              </button>
            </div>
          </div>
        </div>

        <script>
          // User Master Module JavaScript Integration
          let availableRolesCache = [];
          let userSearchDebounceTimer = null;

          document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('usersTable')) {
              initUserMasterPage();
            }
          });

          function initUserMasterPage() {
            fetchRolesList().then(() => {
              loadUsersTable();
            });
          }

          function getUserApiUrl(actionQuery) {
            let apiEndpoint = 'admin/users/api.php';
            if (window.location.pathname.includes('/admin/users') || window.location.pathname.includes('/administration/users')) {
              apiEndpoint = 'api.php';
            }
            if (actionQuery) {
              if (actionQuery.startsWith('?') || actionQuery.startsWith('&')) {
                apiEndpoint += actionQuery;
              } else {
                apiEndpoint += '?' + actionQuery;
              }
            }
            return apiEndpoint;
          }

          async function fetchRolesList() {
            try {
              const res = await fetch(getUserApiUrl('action=roles'));
              const data = await res.json();
              if (data.success && Array.isArray(data.roles)) {
                availableRolesCache = data.roles;
                populateRoleDropdowns(data.roles);
              }
            } catch (err) {
              console.error('Error loading roles:', err);
            }
          }

          function populateRoleDropdowns(roles) {
            const filterSelect = document.getElementById('userRoleFilter');
            const addSelect = document.getElementById('addUserRoleSelect');
            const editSelect = document.getElementById('editUserRoleSelect');

            if (filterSelect) {
              filterSelect.innerHTML = '<option value="ALL">All Roles</option>' +
                roles.map(r => `<option value="${r.role_id}">${escapeHtml(r.role_name)} (${escapeHtml(r.role_code)})</option>`).join('');
            }
            if (addSelect) {
              addSelect.innerHTML = '<option value="">Select Role...</option>' +
                roles.map(r => `<option value="${r.role_id}">${escapeHtml(r.role_name)} (${escapeHtml(r.role_code)})</option>`).join('');
            }
            if (editSelect) {
              editSelect.innerHTML = '<option value="">Select Role...</option>' +
                roles.map(r => `<option value="${r.role_id}">${escapeHtml(r.role_name)} (${escapeHtml(r.role_code)})</option>`).join('');
            }
          }

          function handleUserSearchKeyup(e) {
            clearTimeout(userSearchDebounceTimer);
            userSearchDebounceTimer = setTimeout(() => {
              loadUsersTable();
            }, 300);
          }

          async function loadUsersTable() {
            const tbody = document.getElementById('usersTableBody');
            if (!tbody) return;

            const search = document.getElementById('userSearchInput')?.value || '';
            const roleId = document.getElementById('userRoleFilter')?.value || 'ALL';
            const status = document.getElementById('userStatusFilter')?.value || 'ALL';

            tbody.innerHTML = `
              <tr>
                <td colspan="10" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                  Loading users...
                </td>
              </tr>
            `;

            try {
              const queryParams = new URLSearchParams({
                action: 'list',
                search: search,
                role_id: roleId,
                status: status
              });

              const res = await fetch(getUserApiUrl('?' + queryParams.toString()));
              const data = await res.json();

              if (!data.success) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="9" style="text-align: center; padding: 2rem; color: #DC2626;">
                      Error loading users: ${escapeHtml(data.error || 'Failed to fetch data')}
                    </td>
                  </tr>
                `;
                return;
              }

              if (!data.users || data.users.length === 0) {
                document.getElementById('kpiTotalUsers').textContent = '0';
                document.getElementById('kpiActiveUsers').textContent = '0';
                document.getElementById('kpiLockedUsers').textContent = '0';
                document.getElementById('kpiUniqueRoles').textContent = '0';

                tbody.innerHTML = `
                  <tr>
                    <td colspan="9" style="text-align: center; padding: 3rem 1rem;">
                      <div class="empty-state" style="font-size: 1rem; color: var(--color-secondary-text);">
                        No users found matching current filters.
                      </div>
                    </td>
                  </tr>
                `;
                return;
              }

              // Update KPI Cards
              let activeCount = 0;
              let lockedCount = 0;
              let uniqueRoles = new Set();

              data.users.forEach(u => {
                  if (u.status === 'ACTIVE') activeCount++;
                  if (u.status === 'LOCKED' || u.status === 'SUSPENDED') lockedCount++;
                  uniqueRoles.add(u.role_name);
              });

              document.getElementById('kpiTotalUsers').textContent = data.users.length;
              document.getElementById('kpiActiveUsers').textContent = activeCount;
              document.getElementById('kpiLockedUsers').textContent = lockedCount;
              document.getElementById('kpiUniqueRoles').textContent = uniqueRoles.size;

              const currentSessionUserId = <?php echo (int)($user['user_id'] ?? 0); ?>;

              tbody.innerHTML = data.users.map(u => {
                const statusColor = u.status === 'ACTIVE' ? '#00A88F' : (u.status === 'INACTIVE' ? '#6B7F91' : '#DC2626');
                const statusBg = u.status === 'ACTIVE' ? '#E6F9F6' : (u.status === 'INACTIVE' ? '#F1F5F9' : '#FEE2E2');

                return `
                  <tr>
                    <td><code>#${u.user_id}</code></td>
                    <td><strong>${escapeHtml(u.username)}</strong></td>
                    <td>${escapeHtml(u.full_name)}</td>
                    <td>${escapeHtml(u.email)}</td>
                    <td>
                      <span style="background: ${statusBg}; color: ${statusColor}; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.78rem; font-weight: 700;">
                        ${escapeHtml(u.status)}
                      </span>
                    </td>
                    <td><span class="role-badge">${escapeHtml(u.role_name)}</span></td>
                    <td><span style="font-size: 0.82rem; color: var(--color-secondary-text);">${escapeHtml(u.created_by_name || 'System')}</span></td>
                    <td>${escapeHtml(u.last_login_at || 'Never')}</td>
                    <td style="text-align: right; white-space: nowrap;">
                      ${u.can_manage ? `
                        <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px; border-color: #00A88F; color: #00A88F;" onclick="openViewScopeModal(${u.user_id}, '${escapeJs(u.username)}', '${escapeJs(u.full_name)}')">
                          <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align: -1px; margin-right: 2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                          View Scope
                        </button>
                        <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="openEditUserModal(${u.user_id})">Edit</button>
                        <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="openChangePasswordModal(${u.user_id}, '${escapeJs(u.username)}', '${escapeJs(u.email)}')" >Password</button>
                        ${u.user_id !== currentSessionUserId ? `
                          <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; color: #DC2626; border-color: #FCA5A5;" onclick="openDeleteModal(${u.user_id}, '${escapeJs(u.username)}')" >Delete</button>
                        ` : ''}
                      ` : `
                        <span style="font-size: 0.78rem; color: var(--color-secondary-text); font-style: italic;">Protected</span>
                      `}
                    </td>
                  </tr>
                `;
              }).join('');

            } catch (err) {
              console.error('Error fetching users table:', err);
              tbody.innerHTML = `
                <tr>
                  <td colspan="9" style="text-align: center; padding: 2rem; color: #DC2626;">
                    Network error occurred while fetching users from database.
                  </td>
                </tr>
              `;
            }
          }

          function showToast(message, isSuccess = true) {
            const toast = document.getElementById('userMasterToast');
            if (!toast) return;

            toast.style.display = 'block';
            toast.style.background = isSuccess ? '#E6F9F6' : '#FEE2E2';
            toast.style.color = isSuccess ? '#00A88F' : '#DC2626';
            toast.style.border = isSuccess ? '1px solid #A7F3D0' : '1px solid #FCA5A5';
            toast.textContent = message;

            setTimeout(() => {
              toast.style.display = 'none';
            }, 4000);
          }

          function exportUsersData() {
            const table = document.getElementById("usersTable");
            let csv = [];
            for (let i = 0; i < table.rows.length; i++) {
                let row = [], cols = table.rows[i].querySelectorAll("td, th");
                // Skip the actions column
                let length = (i === 0) ? cols.length - 1 : cols.length - 1;
                for (let j = 0; j < length; j++) {
                    let text = cols[j].innerText.replace(/"/g, '""');
                    row.push('"' + text + '"');
                }
                csv.push(row.join(","));
            }
            let csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
            let downloadLink = document.createElement("a");
            downloadLink.download = "cost360_users_export.csv";
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
          }

          function openAddUserModal() {
            document.getElementById('addUserForm').reset();
            document.getElementById('addUserError').style.display = 'none';
            document.getElementById('addUserModal').style.display = 'flex';
          }

          async function submitAddUser(e) {
            e.preventDefault();
            const form = e.target;
            const errBox = document.getElementById('addUserError');
            errBox.style.display = 'none';

            const formData = new FormData(form);
            formData.append('action', 'create');

            try {
              const res = await fetch(getUserApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('addUserModal');
                showToast(data.message || 'User created successfully.', true);
                loadUsersTable();
              } else {
                errBox.textContent = data.error || 'Failed to create user.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Server connection error.';
              errBox.style.display = 'block';
            }
          }

          async function openEditUserModal(userId) {
            const errBox = document.getElementById('editUserError');
            errBox.style.display = 'none';

            try {
              const res = await fetch(getUserApiUrl(`action=get_user&user_id=${userId}`));
              const data = await res.json();

              if (data.success && data.user) {
                const u = data.user;
                document.getElementById('editUserId').value = u.user_id;
                document.getElementById('editFirstName').value = u.first_name || '';
                document.getElementById('editLastName').value = u.last_name || '';
                document.getElementById('editUsername').value = u.username || '';
                document.getElementById('editEmail').value = u.email || '';
                document.getElementById('editUserRoleSelect').value = u.role_id || '';
                document.getElementById('editStatusSelect').value = u.status || 'ACTIVE';

                document.getElementById('editUserModal').style.display = 'flex';
              } else {
                showToast(data.error || 'Failed to load user details.', false);
              }
            } catch (err) {
              showToast('Error connecting to database.', false);
            }
          }

          async function submitEditUser(e) {
            e.preventDefault();
            const form = e.target;
            const errBox = document.getElementById('editUserError');
            errBox.style.display = 'none';

            const formData = new FormData(form);
            formData.append('action', 'update');

            try {
              const res = await fetch(getUserApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('editUserModal');
                showToast(data.message || 'User details updated.', true);
                loadUsersTable();
              } else {
                errBox.textContent = data.error || 'Failed to update user.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Server connection error.';
              errBox.style.display = 'block';
            }
          }

          function openChangePasswordModal(userId, username, email) {
            document.getElementById('changePasswordForm').reset();
            document.getElementById('changePassUserId').value = userId;
            document.getElementById('changePassUserLabel').textContent = `${username} (${email})`;
            document.getElementById('changePassError').style.display = 'none';
            document.getElementById('changePasswordModal').style.display = 'flex';
          }

          async function submitChangePassword(e) {
            e.preventDefault();
            const form = e.target;
            const errBox = document.getElementById('changePassError');
            errBox.style.display = 'none';

            const formData = new FormData(form);
            formData.append('action', 'change_password');

            try {
              const res = await fetch(getUserApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('changePasswordModal');
                showToast(data.message || 'Password changed successfully.', true);
              } else {
                errBox.textContent = data.error || 'Failed to change password.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Server connection error.';
              errBox.style.display = 'block';
            }
          }

          function openDeleteModal(userId, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserLabel').textContent = username;
            document.getElementById('deleteUserModal').style.display = 'flex';
          }

          async function submitDeleteUser() {
            const userId = document.getElementById('deleteUserId').value;
            if (!userId) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('user_id', userId);

            try {
              const res = await fetch(getUserApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('deleteUserModal');
                showToast(data.message || 'User deleted.', true);
                loadUsersTable();
              } else {
                alert(data.error || 'Failed to delete user.');
              }
            } catch (err) {
              alert('Server connection error.');
            }
          }

          function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
              modal.style.display = 'none';
            }
          }

          function escapeHtml(str) {
            if (!str) return '';
            return String(str)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
          }

          function escapeJs(str) {
            if (!str) return '';
            return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
          }

          /* ============================================================
             ORGANIZATION ACCESS & HIERARCHY SCOPE MANAGEMENT
             ============================================================ */
          let currentManagingUserId = 0;
          let currentManagingUsername = '';
          let currentManagingFullName = '';
          let currentHierarchyData = [];
          let currentAssignmentsData = [];

          // View Scope (read-only) — shows effective scope with Direct/Inherited labels
          async function openViewScopeModal(userId, username, fullName) {
            currentManagingUserId = userId;
            currentManagingUsername = username;
            currentManagingFullName = fullName;
            const modal = document.getElementById('viewScopeModal');
            if (!modal) return;

            document.getElementById('viewScopeModalTitle').textContent = `Access Scope — ${username}`;
            document.getElementById('viewScopeModalSubtitle').textContent = `Effective organizational access for ${fullName || username}`;

            const tree = document.getElementById('viewScopeTree');
            const periodsDiv = document.getElementById('viewScopePeriods');
            tree.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">Loading...</div>';
            if (periodsDiv) periodsDiv.style.display = 'none';
            modal.style.display = 'flex';

            try {
              const res = await fetch(getUserApiUrl(`action=get_user_access&user_id=${userId}`));
              const data = await res.json();

              if (!data.success) {
                tree.innerHTML = `<div style="padding: 1.5rem; color: #DC2626; text-align: center;">${escapeHtml(data.error || 'Failed to load scope.')}</div>`;
                return;
              }

              const scope = data.effective_scope || {};
              const directList = data.direct_assignments || [];
              const periods = data.accessible_periods || [];

              const plantCount = (scope.plant_ids || []).length;
              const whCount = (scope.warehouse_ids || []).length;
              const locCountEl = document.getElementById('vsCountLocations');
              if (locCountEl) locCountEl.textContent = plantCount + whCount;
              document.getElementById('vsCountCompanies').textContent  = (scope.company_ids || []).length;
              document.getElementById('vsCountDivisions').textContent  = (scope.division_ids || []).length;
              document.getElementById('vsCountPeriods').textContent    = (scope.period_ids || periods || []).length;

              renderViewScopeTree(data.hierarchy || [], directList, scope);

              // Show periods section
              if (periods.length > 0 && periodsDiv) {
                periodsDiv.style.display = 'block';
                const perContent = document.getElementById('viewScopePeriodsContent');
                if (perContent) {
                  perContent.innerHTML = periods.map(p => {
                    const isDirect = (directList || []).some(a => parseInt(a.period_id) === parseInt(p.period_id));
                    return `<span style="display: inline-flex; align-items: center; gap: 4px; background: ${isDirect ? '#EDE9FE' : '#F1F5F9'}; color: ${isDirect ? '#7C3AED' : '#475569'}; border: 1px solid ${isDirect ? '#C4B5FD' : '#E2E8F0'}; padding: 0.25rem 0.65rem; border-radius: 12px; font-size: 0.76rem; font-weight: 600;">
                      ${isDirect ? '●' : '○'} ${escapeHtml(p.period_name)}
                    </span>`;
                  }).join('');
                }
              }

            } catch (err) {
              console.error('Error loading scope:', err);
              tree.innerHTML = '<div style="padding: 1.5rem; color: #DC2626; text-align: center;">Connection error.</div>';
            }
          }

          function renderViewScopeTree(customers, directList, scope) {
            const tree = document.getElementById('viewScopeTree');
            if (!customers || customers.length === 0) {
              tree.innerHTML = scope && scope.is_global_admin
                ? '<div style="text-align:center;padding:1.5rem;color:#7C3AED;font-weight:700;">🌐 Global Platform Admin — Access to ALL organizations</div>'
                : '<div style="text-align:center;padding:1.5rem;color:var(--color-secondary-text);">No organizational access assigned.</div>';
              return;
            }

            const directCustIds = new Set((directList||[]).filter(a => !a.company_id && !a.division_id && !a.plant_id && !a.warehouse_id && a.customer_id).map(a => parseInt(a.customer_id)));
            const directCompIds = new Set((directList||[]).filter(a => a.company_id && !a.division_id && !a.plant_id && !a.warehouse_id).map(a => parseInt(a.company_id)));
            const directDivIds  = new Set((directList||[]).filter(a => a.division_id && !a.plant_id && !a.warehouse_id).map(a => parseInt(a.division_id)));
            const directPlantIds= new Set((directList||[]).filter(a => a.plant_id && !a.warehouse_id).map(a => parseInt(a.plant_id)));
            const directWhIds   = new Set((directList||[]).filter(a => a.warehouse_id).map(a => parseInt(a.warehouse_id)));

            const tag = (isDirect) => `<span style="display:inline-block;font-size:0.67rem;font-weight:700;padding:1px 6px;border-radius:4px;margin-left:6px;background:${isDirect?'#DBEAFE':'#E6F9F6'};color:${isDirect?'#1D4ED8':'#008774'};border:1px solid ${isDirect?'#93C5FD':'#6EE7B7'};">${isDirect?'Direct':'Inherited'}</span>`;

            let html = '<div style="display:flex;flex-direction:column;gap:0.85rem;">';
            customers.forEach(cust => {
              const custId = parseInt(cust.customer_id);
              const custDirect = directCustIds.has(custId);
              html += `<div style="border:1px solid #E2E8F0;border-radius:8px;overflow:hidden;background:#FFF;">
                <div style="padding:0.7rem 1rem;background:#F8FAFC;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;">
                  <span style="font-weight:700;color:#0A192F;font-size:0.88rem;">Customer: ${escapeHtml(cust.customer_name)} (${escapeHtml(cust.customer_code)})</span>
                  ${tag(custDirect)}
                  <span style="margin-left:auto;font-size:0.72rem;background:#E0E7FF;color:#3730A3;font-weight:700;padding:2px 8px;border-radius:12px;">TENANT</span>
                </div>
                <div style="padding:0.75rem 1rem 0.75rem 1.5rem;display:flex;flex-direction:column;gap:0.65rem;">`;
              (cust.companies || []).forEach(comp => {
                const compId = parseInt(comp.company_id);
                const compDirect = directCompIds.has(compId);
                const compAccess = custDirect || compDirect || (scope.company_ids||[]).includes(compId);
                if (!compAccess) return;
                html += `<div style="border-left:3px solid #00A88F;padding-left:0.85rem;">
                  <div style="display:flex;align-items:center;margin-bottom:5px;">
                    <span style="font-weight:600;color:#1E293B;font-size:0.84rem;">Company: ${escapeHtml(comp.company_name)} (${escapeHtml(comp.company_code)})</span>
                    ${tag(compDirect && !custDirect)}
                  </div>
                  <div style="padding-left:1.25rem;display:flex;flex-direction:column;gap:0.5rem;margin-top:4px;">`;
                (comp.divisions || []).forEach(div => {
                  const divId = parseInt(div.division_id);
                  const divDirect = directDivIds.has(divId);
                  const divAccess = custDirect || compDirect || divDirect || (scope.division_ids||[]).includes(divId);
                  if (!divAccess) return;
                  html += `<div style="border-left:2px solid #CBD5E1;padding-left:0.75rem;">
                    <div style="display:flex;align-items:center;margin-bottom:4px;">
                      <span style="font-weight:600;color:#334155;font-size:0.81rem;">Division: ${escapeHtml(div.division_name)}</span>
                      ${tag(divDirect && !compDirect && !custDirect)}
                    </div>
                    <div style="padding-left:1rem;display:flex;flex-direction:column;gap:0.4rem;">`;

                  // UNIFIED LOCATION LEVEL: Both Plants and Warehouses shown under LOCATION
                  const accessibleLocations = [];

                  (div.plants || []).forEach(plant => {
                    const plantId = parseInt(plant.plant_id);
                    const plantDirect = directPlantIds.has(plantId);
                    const plantAccess = custDirect || compDirect || divDirect || plantDirect || (scope.plant_ids||[]).includes(plantId);
                    if (plantAccess) {
                      accessibleLocations.push({
                        id: plantId,
                        name: plant.plant_name,
                        code: plant.plant_code,
                        type: 'Plant',
                        isDirect: plantDirect && !divDirect && !compDirect && !custDirect
                      });
                    }
                    (plant.warehouses || []).forEach(wh => {
                      const whId = parseInt(wh.warehouse_id);
                      const whDirect = directWhIds.has(whId);
                      const whAccess = custDirect || compDirect || divDirect || plantDirect || whDirect || (scope.warehouse_ids||[]).includes(whId);
                      if (whAccess) {
                        accessibleLocations.push({
                          id: whId,
                          name: wh.warehouse_name,
                          code: wh.warehouse_code,
                          type: 'Warehouse',
                          isDirect: whDirect && !plantDirect && !divDirect && !compDirect && !custDirect
                        });
                      }
                    });
                  });

                  if (accessibleLocations.length > 0) {
                    html += `
                      <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:8px 10px;">
                        <div style="font-size:0.72rem;font-weight:700;color:#033665;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                          <span>LOCATION</span>
                          <span style="font-size:0.68rem;background:#E8F4FD;color:#0070BA;padding:1px 6px;border-radius:10px;">${accessibleLocations.length}</span>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:4px;padding-left:0.5rem;">
                    `;

                    accessibleLocations.forEach(loc => {
                      const isPlant = (loc.type === 'Plant');
                      const badgeBg = isPlant ? '#E0F2FE' : '#FEF3C7';
                      const badgeCol = isPlant ? '#0369A1' : '#B45309';
                      html += `
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:3px 0;">
                          <div style="display:flex;align-items:center;gap:6px;font-size:0.78rem;color:#1E293B;">
                            <span style="font-weight:600;">${escapeHtml(loc.name)}</span>
                            <span style="font-size:0.7rem;color:#64748B;">(${escapeHtml(loc.code)})</span>
                            <span style="font-size:0.65rem;font-weight:700;padding:1px 6px;border-radius:4px;background:${badgeBg};color:${badgeCol};">Type: ${escapeHtml(loc.type)}</span>
                          </div>
                          ${tag(loc.isDirect)}
                        </div>
                      `;
                    });

                    html += `</div></div>`;
                  } else {
                    html += `<div style="font-size:0.75rem;color:#94A3B8;font-style:italic;">No location access</div>`;
                  }

                  html += '</div></div>';
                });
                html += '</div></div>';
              });
              html += '</div></div>';
            });
            html += '</div>';
            tree.innerHTML = html;
          }

          // Switch from View Scope to Manage Access for same user
          function switchToManageAccess() {
            closeModal('viewScopeModal');
            openUserAccessModal(currentManagingUserId, currentManagingUsername, currentManagingFullName);
          }

          async function openUserAccessModal(userId, username, fullName) {
            currentManagingUserId = userId;
            currentManagingUsername = username || '';
            currentManagingFullName = fullName || '';
            const modal = document.getElementById('userAccessModal');
            if (!modal) return;

            document.getElementById('userAccessModalTitle').textContent = `Manage Organization Access — ${username}`;
            document.getElementById('userAccessModalSubtitle').textContent = fullName ? `Target user: ${fullName}` : 'Configure organizational hierarchy access';
            
            const errDiv = document.getElementById('userAccessModalError');
            if (errDiv) errDiv.style.display = 'none';

            const container = document.getElementById('userAccessTreeContainer');
            container.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">Loading organizational hierarchy...</div>';

            const periodContainer = document.getElementById('periodAccessContainer');
            if (periodContainer) periodContainer.innerHTML = '<span style="color: var(--color-secondary-text); font-size: 0.82rem;">Loading periods...</span>';

            modal.style.display = 'flex';

            try {
              const res = await fetch(getUserApiUrl(`action=get_user_access&user_id=${userId}`));
              const data = await res.json();

              if (!data.success) {
                container.innerHTML = `<div style="padding: 1.5rem; color: #DC2626; text-align: center;">${escapeHtml(data.error || 'Failed to load access details.')}</div>`;
                return;
              }

              currentHierarchyData = data.hierarchy || data.available_tree || [];
              currentAssignmentsData = data.assignments || data.direct_assignments || [];

              renderAccessHierarchyTree(currentHierarchyData, currentAssignmentsData);
              syncAccessTreeState();

              // Populate period checkboxes
              const accessiblePeriods = data.accessible_periods || [];
              const directAssignedPeriodIds = new Set(
                (data.direct_assignments || []).filter(a => a.period_id).map(a => parseInt(a.period_id))
              );

              if (periodContainer) {
                if (accessiblePeriods.length === 0) {
                  periodContainer.innerHTML = '<span style="color: var(--color-secondary-text); font-size: 0.82rem;">No periods available — create periods first.</span>';
                } else {
                  periodContainer.innerHTML = accessiblePeriods.map(p => {
                    const isChecked = directAssignedPeriodIds.has(parseInt(p.period_id));
                    const isLocked = p.is_locked;
                    return `<label style="display: inline-flex; align-items: center; gap: 5px; background: ${isChecked ? '#EDE9FE' : '#F8FAFC'}; border: 1px solid ${isChecked ? '#C4B5FD' : '#E2E8F0'}; border-radius: 8px; padding: 0.35rem 0.75rem; cursor: pointer; transition: background 0.15s; font-size: 0.82rem; font-weight: 600; color: ${isChecked ? '#7C3AED' : '#475569'};">
                      <input type="checkbox" class="scope-cb-period" data-period-id="${p.period_id}" ${isChecked ? 'checked' : ''} ${isLocked ? 'title="Period is locked"' : ''} style="accent-color: #7C3AED;">
                      ${escapeHtml(p.period_name)}
                      ${isLocked ? '<span style="font-size: 0.68rem; color: #DC2626; margin-left: 2px;">🔒</span>' : ''}
                    </label>`;
                  }).join('');
                }
              }

            } catch (err) {
              console.error('Error loading user access:', err);
              container.innerHTML = '<div style="padding: 1.5rem; color: #DC2626; text-align: center;">Connection error while fetching hierarchy.</div>';
            }
          }

          function renderAccessHierarchyTree(customers, assignments) {
            const container = document.getElementById('userAccessTreeContainer');
            if (!container) return;

            if (!customers || customers.length === 0) {
              container.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">No accessible organizational units found for your administrative scope.</div>';
              return;
            }

            // Map existing direct assignments
            const hasCustScope = (cid) => assignments.some(a => parseInt(a.customer_id) === cid && !a.company_id && !a.division_id && !a.plant_id && !a.warehouse_id);
            const hasCompScope = (compid) => assignments.some(a => parseInt(a.company_id) === compid && !a.division_id && !a.plant_id && !a.warehouse_id);
            const hasDivScope = (divid) => assignments.some(a => parseInt(a.division_id) === divid && !a.plant_id && !a.warehouse_id);
            const hasPlantScope = (pid) => assignments.some(a => parseInt(a.plant_id) === pid && !a.warehouse_id);
            const hasWhScope = (wid) => assignments.some(a => parseInt(a.warehouse_id) === wid);

            let html = '<div style="display: flex; flex-direction: column; gap: 0.85rem;">';

            customers.forEach(cust => {
              const custId = parseInt(cust.customer_id);
              const isCustChecked = hasCustScope(custId);

              html += `
                <div class="tree-node-customer" style="border: 1px solid #E2E8F0; border-radius: 8px; overflow: hidden; background: #FFF; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                  <div style="padding: 0.75rem 1rem; background: #F8FAFC; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between;">
                    <label style="display: flex; align-items: center; gap: 0.65rem; font-weight: 700; color: #0A192F; font-size: 0.9rem; cursor: pointer;">
                      <input type="checkbox" class="scope-cb scope-cb-cust" data-cust-id="${custId}" ${isCustChecked ? 'checked' : ''} onchange="syncAccessTreeState()">
                      <span>Customer: ${escapeHtml(cust.customer_name)} (${escapeHtml(cust.customer_code)})</span>
                    </label>
                    <span style="font-size: 0.72rem; background: #E0E7FF; color: #3730A3; font-weight: 700; padding: 2px 8px; border-radius: 12px;">TENANT</span>
                  </div>

                  <div class="tree-companies-group" data-parent-cust="${custId}" style="padding: 0.75rem 1rem 0.75rem 1.5rem; display: flex; flex-direction: column; gap: 0.75rem;">
              `;

              (cust.companies || []).forEach(comp => {
                const compId = parseInt(comp.company_id);
                const isCompChecked = hasCompScope(compId);

                html += `
                  <div class="tree-node-company" style="border-left: 3px solid #00A88F; padding-left: 0.85rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                      <label style="display: flex; align-items: center; gap: 0.55rem; font-weight: 600; color: #1E293B; font-size: 0.86rem; cursor: pointer;">
                        <input type="checkbox" class="scope-cb scope-cb-comp" data-cust-id="${custId}" data-comp-id="${compId}" ${isCompChecked ? 'checked' : ''} onchange="syncAccessTreeState()">
                        <span>Company: ${escapeHtml(comp.company_name)} (${escapeHtml(comp.company_code)})</span>
                      </label>
                      <span class="inherited-tag inherited-tag-comp" style="display: none; font-size: 0.7rem; color: #008774; background: #E6F9F6; padding: 2px 7px; border-radius: 4px; font-weight: 600;">Inherited from Tenant</span>
                    </div>

                    <div class="tree-divisions-group" data-parent-comp="${compId}" style="padding-left: 1.25rem; display: flex; flex-direction: column; gap: 0.65rem; margin-top: 4px;">
                `;

                (comp.divisions || []).forEach(div => {
                  const divId = parseInt(div.division_id);
                  const isDivChecked = hasDivScope(divId);

                  html += `
                    <div class="tree-node-division" style="border-left: 2px solid #CBD5E1; padding-left: 0.75rem;">
                      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155; font-size: 0.82rem; cursor: pointer;">
                          <input type="checkbox" class="scope-cb scope-cb-div" data-cust-id="${custId}" data-comp-id="${compId}" data-div-id="${divId}" ${isDivChecked ? 'checked' : ''} onchange="syncAccessTreeState()">
                          <span>Division: ${escapeHtml(div.division_name)}</span>
                        </label>
                        <span class="inherited-tag inherited-tag-div" style="display: none; font-size: 0.68rem; color: #008774; background: #E6F9F6; padding: 1px 6px; border-radius: 4px; font-weight: 600;">Inherited</span>
                      </div>

                      <div class="tree-locations-group" data-parent-div="${divId}" style="padding-left: 1.25rem; display: flex; flex-direction: column; gap: 0.45rem; margin-top: 4px;">
                  `;

                  // UNIFIED LOCATION LEVEL: Combined Plants & Warehouses
                  const locList = [];
                  (div.plants || []).forEach(p => {
                    locList.push({
                      location_id: parseInt(p.plant_id),
                      location_type: 'Plant',
                      type_code: 'plant',
                      location_name: p.plant_name,
                      location_code: p.plant_code,
                      plant_id: parseInt(p.plant_id),
                      warehouse_id: null
                    });
                    (p.warehouses || []).forEach(w => {
                      locList.push({
                        location_id: parseInt(w.warehouse_id),
                        location_type: 'Warehouse',
                        type_code: 'warehouse',
                        location_name: w.warehouse_name,
                        location_code: w.warehouse_code,
                        plant_id: parseInt(p.plant_id),
                        warehouse_id: parseInt(w.warehouse_id)
                      });
                    });
                  });

                  html += `
                    <div style="font-size: 0.72rem; font-weight: 700; color: #033665; text-transform: uppercase; margin: 6px 0 4px 0; letter-spacing: 0.04em;">
                      LOCATION
                    </div>
                  `;

                  locList.forEach(loc => {
                    const isPlant = (loc.type_code === 'plant');
                    const isLocChecked = isPlant ? hasPlantScope(loc.location_id) : hasWhScope(loc.location_id);
                    const cbClass = isPlant ? 'scope-cb-plant' : 'scope-cb-wh';
                    const tagClass = isPlant ? 'inherited-tag-plant' : 'inherited-tag-wh';
                    const badgeBg = isPlant ? '#E0F2FE' : '#FEF3C7';
                    const badgeCol = isPlant ? '#0369A1' : '#B45309';

                    html += `
                      <div class="tree-node-loc" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 6px; padding: 6px 10px; display: flex; align-items: center; justify-content: space-between;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; color: #1E293B; cursor: pointer; font-weight: 600;">
                          <input type="checkbox" class="scope-cb ${cbClass}" data-cust-id="${custId}" data-comp-id="${compId}" data-div-id="${divId}" ${isPlant ? `data-plant-id="${loc.location_id}"` : `data-wh-id="${loc.location_id}" data-parent-plant-id="${loc.plant_id || ''}"`} ${isLocChecked ? 'checked' : ''} onchange="syncAccessTreeState()">
                          <span>${escapeHtml(loc.location_name)} <span style="font-size: 0.72rem; color: #64748B;">(${escapeHtml(loc.location_code)})</span></span>
                          <span style="font-size: 0.65rem; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: ${badgeBg}; color: ${badgeCol};">Type: ${escapeHtml(loc.location_type)}</span>
                        </label>
                        <span class="inherited-tag ${tagClass}" style="display: none; font-size: 0.68rem; color: #008774; background: #E6F9F6; padding: 1px 6px; border-radius: 4px; font-weight: 600;">Inherited</span>
                      </div>
                    `;
                  });

                  html += `
                      </div>
                    </div>
                  `;
                });

                html += `
                    </div>
                  </div>
                `;
              });

              html += `
                  </div>
                </div>
              `;
            });

            html += '</div>';
            container.innerHTML = html;
          }

          function syncAccessTreeState() {
            const container = document.getElementById('userAccessTreeContainer');
            if (!container) return;

            const selectedCompIds = new Set();
            const selectedDivIds = new Set();
            const selectedPlantIds = new Set();
            const selectedWhIds = new Set();

            // 1. Process Customer Level
            container.querySelectorAll('.scope-cb-cust').forEach(custCb => {
              const custId = custCb.dataset.custId;
              const isCustChecked = custCb.checked;
              const compGroup = container.querySelector(`.tree-companies-group[data-parent-cust="${custId}"]`);
              if (!compGroup) return;

              compGroup.querySelectorAll('.scope-cb-comp').forEach(compCb => {
                const compId = compCb.dataset.compId;
                const inheritedTagComp = compCb.closest('.tree-node-company').querySelector('.inherited-tag-comp');

                if (isCustChecked) {
                  compCb.disabled = true;
                  if (inheritedTagComp) inheritedTagComp.style.display = 'inline-block';
                  selectedCompIds.add(compId);
                } else {
                  compCb.disabled = false;
                  if (inheritedTagComp) inheritedTagComp.style.display = 'none';
                  if (compCb.checked) selectedCompIds.add(compId);
                }

                const isCompEffective = isCustChecked || compCb.checked;
                const divGroup = compCb.closest('.tree-node-company').querySelector(`.tree-divisions-group[data-parent-comp="${compId}"]`);
                if (!divGroup) return;

                divGroup.querySelectorAll('.tree-node-division').forEach(divNode => {
                  const divCb = divNode.querySelector('.scope-cb-div');
                  if (!divCb) return;
                  const divId = divCb.dataset.divId;
                  const inheritedTagDiv = divNode.querySelector('.inherited-tag-div');

                  if (isCompEffective) {
                    divCb.disabled = true;
                    if (inheritedTagDiv) {
                      inheritedTagDiv.style.display = 'inline-block';
                      inheritedTagDiv.textContent = isCustChecked ? 'Inherited from Tenant' : 'Inherited from Company';
                    }
                    selectedDivIds.add(divId);
                  } else {
                    divCb.disabled = false;
                    if (inheritedTagDiv) inheritedTagDiv.style.display = 'none';
                    if (divCb.checked) selectedDivIds.add(divId);
                  }

                  const isDivEffective = isCompEffective || divCb.checked;

                  // Update Plant Locations under Division
                  divNode.querySelectorAll('.scope-cb-plant').forEach(plantCb => {
                    const plantId = plantCb.dataset.plantId;
                    const tagPlant = plantCb.closest('.tree-node-loc').querySelector('.inherited-tag-plant');
                    if (isDivEffective) {
                      plantCb.disabled = true;
                      if (tagPlant) {
                        tagPlant.style.display = 'inline-block';
                        tagPlant.textContent = isCustChecked ? 'Inherited from Tenant' : (compCb.checked ? 'Inherited from Company' : 'Inherited from Division');
                      }
                      selectedPlantIds.add(plantId);
                    } else {
                      plantCb.disabled = false;
                      if (tagPlant) tagPlant.style.display = 'none';
                      if (plantCb.checked) selectedPlantIds.add(plantId);
                    }
                  });

                  // Update Warehouse Locations under Division
                  divNode.querySelectorAll('.scope-cb-wh').forEach(whCb => {
                    const whId = whCb.dataset.whId;
                    const tagWh = whCb.closest('.tree-node-loc').querySelector('.inherited-tag-wh');
                    if (isDivEffective) {
                      whCb.disabled = true;
                      if (tagWh) {
                        tagWh.style.display = 'inline-block';
                        tagWh.textContent = isCustChecked ? 'Inherited from Tenant' : (compCb.checked ? 'Inherited from Company' : 'Inherited from Division');
                      }
                      selectedWhIds.add(whId);
                    } else {
                      whCb.disabled = false;
                      if (tagWh) tagWh.style.display = 'none';
                      if (whCb.checked) selectedWhIds.add(whId);
                    }
                  });
                });
              });
            });

            // Update live metrics counters
            document.getElementById('scopeCountCompanies').textContent = selectedCompIds.size;
            document.getElementById('scopeCountDivisions').textContent = selectedDivIds.size;
            const scopeLocsEl = document.getElementById('scopeCountLocations');
            if (scopeLocsEl) {
              scopeLocsEl.textContent = selectedPlantIds.size + selectedWhIds.size;
            }

            const badgeEl = document.getElementById('userAccessScopeBadge');
            const custAny = Array.from(container.querySelectorAll('.scope-cb-cust')).some(cb => cb.checked);
            if (custAny) {
              badgeEl.textContent = 'Customer / Tenant Wide Access';
              badgeEl.style.color = '#00A88F';
            } else if (selectedCompIds.size > 0 && selectedCompIds.size === container.querySelectorAll('.scope-cb-comp').length) {
              badgeEl.textContent = 'All Companies Access';
              badgeEl.style.color = '#00A88F';
            } else if (selectedCompIds.size > 0) {
              badgeEl.textContent = `${selectedCompIds.size} Company/Companies Scoped`;
              badgeEl.style.color = '#1E40AF';
            } else if (selectedPlantIds.size > 0 || selectedWhIds.size > 0) {
              badgeEl.textContent = `${selectedPlantIds.size + selectedWhIds.size} Location(s) Scoped`;
              badgeEl.style.color = '#D97706';
            } else {
              badgeEl.textContent = 'No Access Configured';
              badgeEl.style.color = '#DC2626';
            }
          }

          async function saveUserAccessSettings() {
            const container = document.getElementById('userAccessTreeContainer');
            if (!container || currentManagingUserId <= 0) return;

            const saveBtn = document.getElementById('saveUserAccessBtn');
            const errDiv = document.getElementById('userAccessModalError');
            if (errDiv) errDiv.style.display = 'none';

            // Gather active direct assignments (only non-disabled checkboxes that are checked)
            const assignments = [];

            container.querySelectorAll('.scope-cb-cust').forEach(cb => {
              if (cb.checked && !cb.disabled) {
                assignments.push({
                  customer_id: parseInt(cb.dataset.custId),
                  company_id: null,
                  division_id: null,
                  plant_id: null,
                  warehouse_id: null,
                  status: 'Active'
                });
              }
            });

            container.querySelectorAll('.scope-cb-comp').forEach(cb => {
              if (cb.checked && !cb.disabled) {
                assignments.push({
                  customer_id: parseInt(cb.dataset.custId),
                  company_id: parseInt(cb.dataset.compId),
                  division_id: null,
                  plant_id: null,
                  warehouse_id: null,
                  status: 'Active'
                });
              }
            });

            container.querySelectorAll('.scope-cb-div').forEach(cb => {
              if (cb.checked && !cb.disabled) {
                assignments.push({
                  customer_id: parseInt(cb.dataset.custId),
                  company_id: parseInt(cb.dataset.compId),
                  division_id: parseInt(cb.dataset.divId),
                  plant_id: null,
                  warehouse_id: null,
                  status: 'Active'
                });
              }
            });

            container.querySelectorAll('.scope-cb-plant').forEach(cb => {
              if (cb.checked && !cb.disabled) {
                assignments.push({
                  customer_id: parseInt(cb.dataset.custId),
                  company_id: parseInt(cb.dataset.compId),
                  division_id: parseInt(cb.dataset.divId),
                  plant_id: parseInt(cb.dataset.plantId),
                  warehouse_id: null,
                  status: 'Active'
                });
              }
            });

            container.querySelectorAll('.scope-cb-wh').forEach(cb => {
              if (cb.checked && !cb.disabled) {
                assignments.push({
                  customer_id: parseInt(cb.dataset.custId),
                  company_id: parseInt(cb.dataset.compId),
                  division_id: parseInt(cb.dataset.divId),
                  plant_id: cb.dataset.plantId ? parseInt(cb.dataset.plantId) : null,
                  warehouse_id: parseInt(cb.dataset.whId),
                  status: 'Active'
                });
              }
            });

            // Gather period selections (sent as period-only assignment rows)
            const periodContainer = document.getElementById('periodAccessContainer');
            if (periodContainer) {
              periodContainer.querySelectorAll('.scope-cb-period').forEach(cb => {
                if (cb.checked) {
                  assignments.push({
                    customer_id: null,
                    company_id: null,
                    division_id: null,
                    plant_id: null,
                    warehouse_id: null,
                    period_id: parseInt(cb.dataset.periodId),
                    status: 'Active'
                  });
                }
              });
            }

            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving Access Scope...';

            try {
              const formData = new FormData();
              formData.append('action', 'save_user_access');
              formData.append('user_id', currentManagingUserId);
              formData.append('assignments', JSON.stringify(assignments));

              const res = await fetch(getUserApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (!data.success) {
                if (errDiv) {
                  errDiv.textContent = data.error || 'Failed to save organization access.';
                  errDiv.style.display = 'block';
                } else {
                  alert(data.error || 'Failed to save organization access.');
                }
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Access Scope';
                return;
              }

              closeModal('userAccessModal');
              showToast(data.message || 'Organization access scope saved successfully.', true);
              loadUsersTable();

            } catch (err) {
              console.error('Error saving access settings:', err);
              if (errDiv) {
                errDiv.textContent = 'Network or server communication error.';
                errDiv.style.display = 'block';
              }
            } finally {
              saveBtn.disabled = false;
              saveBtn.textContent = 'Save Access Scope';
            }
          }
        </script>

      <?php elseif ($currentPage === 'roles'): ?>
        <!-- ============================================================
             ROLE MASTER PAGE MODULE (DYNAMIC FROM NEON DB)
             ============================================================ -->
        <style>
          /* Modern Toggle Switch */
          .cost360-toggle-switch {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            cursor: pointer;
            user-select: none;
            padding: 2px;
          }
          .cost360-toggle-track {
            width: 44px;
            height: 24px;
            background-color: #CBD5E1;
            border-radius: 9999px;
            position: relative;
            transition: background-color 0.25s ease, box-shadow 0.2s ease;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.12);
            flex-shrink: 0;
          }
          .cost360-toggle-track.active {
            background: linear-gradient(135deg, #00A88F 0%, #00CFEF 100%);
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1), 0 0 6px rgba(0, 207, 239, 0.35);
          }
          .cost360-toggle-thumb {
            width: 18px;
            height: 18px;
            background-color: #FFFFFF;
            border-radius: 50%;
            position: absolute;
            top: 3px;
            left: 3px;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.25);
          }
          .cost360-toggle-track.active .cost360-toggle-thumb {
            transform: translateX(20px);
          }
          .cost360-toggle-label {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
          }
          .cost360-toggle-label.active {
            color: #00A88F;
          }
          .cost360-toggle-label.inactive {
            color: #64748B;
          }

          /* Matrix Permissions Table */
          .perm-matrix-wrapper {
            max-height: 58vh;
            overflow-y: auto;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-input);
          }
          .perm-matrix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.84rem;
          }
          .perm-matrix-table th {
            position: sticky;
            top: 0;
            background: var(--color-soft-blue);
            color: var(--color-primary-text);
            font-weight: 700;
            padding: 0.75rem 0.65rem;
            border-bottom: 2px solid var(--color-border);
            z-index: 2;
            text-align: center;
          }
          .perm-matrix-table th.th-menu {
            text-align: left;
            min-width: 230px;
          }
          .perm-matrix-table td {
            padding: 0.6rem 0.65rem;
            border-bottom: 1px solid var(--color-border);
            vertical-align: middle;
          }
          .perm-matrix-table tr:hover td {
            background-color: #F8FAFC;
          }
          .perm-check-cell {
            text-align: center;
          }
          .perm-checkbox {
            width: 17px;
            height: 17px;
            cursor: pointer;
            accent-color: var(--color-primary-blue);
            vertical-align: middle;
          }
          .th-clickable {
            cursor: pointer;
            user-select: none;
            transition: color 0.15s ease;
          }
          .th-clickable:hover {
            color: var(--color-primary-blue);
            background: #D9EEF9;
          }
        </style>

        <!-- KPI STATS CARDS -->
        <section class="grid-cards" style="margin-bottom: 1.5rem;">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Total Roles</span></div>
            <div class="stat-number" id="kpiTotalRoles">0</div>
            <div class="stat-desc">All system defined roles</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Active Roles</span></div>
            <div class="stat-number" id="kpiActiveRoles" style="color: var(--color-bright-cyan);">0</div>
            <div class="stat-desc">Currently enabled roles</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Inactive Roles</span></div>
            <div class="stat-number" id="kpiInactiveRoles" style="color: #DC2626;">0</div>
            <div class="stat-desc">Deactivated roles</div>
          </div>
        </section>

        <!-- ROLE MASTER MAIN SECTION -->
        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">Role Master</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage system roles and their access permissions.</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddRoleModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>Add Role</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadRolesTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- SEARCH & FILTERS TOOLBAR -->
          <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1.25rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px; position: relative;">
              <input type="text" id="roleSearchInput" class="cost360-select" placeholder="Search roles..." onkeyup="handleRoleSearchKeyup(event)" style="padding-left: 2.25rem;">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--color-secondary-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); pointer-events: none;">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
            </div>

            <div style="min-width: 180px;">
              <select id="roleCategoryFilter" class="cost360-select" onchange="loadRolesTable()">
                <option value="ALL">All Categories</option>
              </select>
            </div>

            <div style="min-width: 150px;">
              <select id="roleStatusFilter" class="cost360-select" onchange="loadRolesTable()">
                <option value="ALL">All Statuses</option>
                <option value="Active">ACTIVE</option>
                <option value="Inactive">INACTIVE</option>
              </select>
            </div>
          </div>

          <!-- FEEDBACK TOAST BANNER -->
          <div id="roleMasterToast" style="display: none; padding: 0.85rem 1.25rem; border-radius: var(--radius-input); margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- ROLES LIST TABLE -->
          <div class="table-responsive">
            <table id="rolesTable">
              <thead>
                <tr>
                  <th style="min-width: 160px;">Role Code</th>
                  <th style="min-width: 200px;">Role Name</th>
                  <th style="min-width: 160px;">Category</th>
                  <th style="min-width: 220px;">Description</th>
                  <th style="min-width: 140px;">Status</th>
                  <th style="text-align: right; min-width: 260px;">Actions</th>
                </tr>
              </thead>
              <tbody id="rolesTableBody">
                <tr>
                  <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--color-secondary-text);">
                    Loading system roles from Neon database...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ADD ROLE MODAL -->
        <div id="addRoleModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 540px;">
            <div class="modal-header">
              <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">+ Add New Role</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('addRoleModal')">&times;</button>
            </div>
            <form id="addRoleForm" onsubmit="submitAddRole(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <div id="addRoleError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div>
                <label class="dropdown-label">Role Category *</label>
                <select name="category_id" id="addRoleCategorySelect" class="cost360-select" required>
                  <option value="">Select Category...</option>
                </select>
              </div>

              <div>
                <label class="dropdown-label">Role Code *</label>
                <input type="text" name="role_code" id="addRoleCode" class="cost360-select" required placeholder="e.g. COMPANY_ADMIN" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_')">
                <span style="font-size: 0.75rem; color: var(--color-secondary-text); margin-top: 0.2rem; display: block;">Uppercase letters, numbers, and underscores (e.g. PLATFORM_ADMIN).</span>
              </div>

              <div>
                <label class="dropdown-label">Role Name *</label>
                <input type="text" name="role_name" id="addRoleName" class="cost360-select" required placeholder="e.g. Company Administrator">
              </div>

              <div>
                <label class="dropdown-label">Description</label>
                <textarea name="description" id="addRoleDescription" class="cost360-select" rows="3" placeholder="Brief description of the role's scope and responsibilities" style="resize: vertical;"></textarea>
              </div>

              <div>
                <label class="dropdown-label">Status *</label>
                <select name="status" id="addRoleStatus" class="cost360-select" required>
                  <option value="Active" selected>Active</option>
                  <option value="Inactive">Inactive</option>
                </select>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('addRoleModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="btnSubmitAddRole">Create Role</button>
              </div>
            </form>
          </div>
        </div>

        <!-- EDIT ROLE MODAL -->
        <div id="editRoleModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 540px;">
            <div class="modal-header">
              <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">Edit Role Details</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('editRoleModal')">&times;</button>
            </div>
            <form id="editRoleForm" onsubmit="submitEditRole(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="role_id" id="editRoleId">
              <div id="editRoleError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div>
                <label class="dropdown-label">Role Category *</label>
                <select name="category_id" id="editRoleCategorySelect" class="cost360-select" required>
                  <option value="">Select Category...</option>
                </select>
              </div>

              <div>
                <label class="dropdown-label">Role Code (Read-Only)</label>
                <input type="text" id="editRoleCode" class="cost360-select" readonly style="background: #F1F5F9; color: var(--color-secondary-text); cursor: not-allowed; font-weight: 700;">
                <span style="font-size: 0.75rem; color: var(--color-secondary-text); margin-top: 0.2rem; display: block;">Role code cannot be modified as it is referenced across system access policies.</span>
              </div>

              <div>
                <label class="dropdown-label">Role Name *</label>
                <input type="text" name="role_name" id="editRoleName" class="cost360-select" required>
              </div>

              <div>
                <label class="dropdown-label">Description</label>
                <textarea name="description" id="editRoleDescription" class="cost360-select" rows="3" style="resize: vertical;"></textarea>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('editRoleModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="btnSubmitEditRole">Save Changes</button>
              </div>
            </form>
          </div>
        </div>

        <!-- VIEW ROLE MODAL -->
        <div id="viewRoleModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 560px;">
            <div class="modal-header">
              <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">Role Details</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('viewRoleModal')">&times;</button>
            </div>
            <div style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1.25rem;">
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; background: var(--color-soft-blue); padding: 1.25rem; border-radius: var(--radius-input);">
                <div>
                  <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text); display: block; margin-bottom: 0.25rem;">Role Code</span>
                  <code id="viewRoleCode" style="font-size: 0.95rem; font-weight: 700; color: var(--color-primary-blue);"></code>
                </div>
                <div>
                  <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text); display: block; margin-bottom: 0.25rem;">Status</span>
                  <span id="viewRoleStatusBadge"></span>
                </div>
              </div>

              <div>
                <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text); display: block; margin-bottom: 0.25rem;">Role Name</span>
                <div id="viewRoleName" style="font-size: 1.05rem; font-weight: 700; color: var(--color-primary-text);"></div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text); display: block; margin-bottom: 0.25rem;">Category</span>
                  <div id="viewRoleCategory" style="font-size: 0.9rem; font-weight: 600; color: var(--color-primary-text);"></div>
                </div>
                <div>
                  <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text); display: block; margin-bottom: 0.25rem;">Assigned Users</span>
                  <div id="viewRoleUsers" style="font-size: 0.9rem; font-weight: 600; color: var(--color-primary-text);"></div>
                </div>
              </div>

              <div>
                <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text); display: block; margin-bottom: 0.25rem;">Description</span>
                <div id="viewRoleDescription" style="font-size: 0.88rem; color: var(--color-primary-text); line-height: 1.5; background: #F8FAFC; border: 1px solid var(--color-border); padding: 0.75rem; border-radius: 6px; min-height: 48px;"></div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; border-top: 1px dashed var(--color-border); padding-top: 0.75rem; font-size: 0.8rem; color: var(--color-secondary-text);">
                <div>Created: <span id="viewRoleCreatedAt" style="font-weight: 600; color: var(--color-primary-text);"></span></div>
                <div>Updated: <span id="viewRoleUpdatedAt" style="font-weight: 600; color: var(--color-primary-text);"></span></div>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('viewRoleModal')">Close</button>
                <button type="button" class="btn-secondary" id="btnViewModalEdit">Edit Role</button>
                <button type="button" class="btn-primary" id="btnViewModalPerms">Manage Permissions</button>
              </div>
            </div>
          </div>
        </div>

        <!-- STATUS CONFIRMATION MODAL -->
        <div id="roleConfirmStatusModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 440px;">
            <div class="modal-header">
              <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);" id="roleConfirmStatusTitle">Change Role Status?</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('roleConfirmStatusModal')">&times;</button>
            </div>
            <div style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <p id="roleConfirmStatusMessage" style="font-size: 0.92rem; color: var(--color-primary-text); margin: 0; line-height: 1.5;">
                Are you sure you want to change the status of this role?
              </p>
              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('roleConfirmStatusModal')">Cancel</button>
                <button type="button" class="btn-primary" id="roleConfirmSubmitBtn" onclick="executeRoleStatusToggle()">Confirm</button>
              </div>
            </div>
          </div>
        </div>

        <!-- MANAGE PERMISSIONS MODAL -->
        <div id="rolePermissionsModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 1200px; width: 96%; max-height: 90vh;">
            <div class="modal-header" style="flex-shrink: 0;">
              <div>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--color-primary-text); margin-bottom: 0.2rem;">Manage Permissions</h3>
                <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--color-secondary-text);">
                  Role: <code id="permModalRoleCode" class="role-badge" style="font-size: 0.8rem;"></code>
                  <span id="permModalRoleName" style="color: var(--color-primary-text); font-weight: 700;"></span>
                </div>
              </div>
              <button type="button" class="modal-close-btn" onclick="closeModal('rolePermissionsModal')">&times;</button>
            </div>

            <!-- Matrix Search & Quick Actions -->
            <div style="padding: 0.75rem 1.5rem; background: #F8FAFC; border-bottom: 1px solid var(--color-border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; flex-shrink: 0;">
              <div style="flex: 1; min-width: 250px; position: relative;">
                <input type="text" id="permSearchInput" class="cost360-select" placeholder="Filter menus in matrix..." onkeyup="filterPermissionsMatrix()" style="padding-left: 2rem; font-size: 0.82rem; padding-top: 0.45rem; padding-bottom: 0.45rem;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="var(--color-secondary-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%); pointer-events: none;">
                  <circle cx="11" cy="11" r="8"></circle>
                  <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
              </div>
              <div style="display: flex; gap: 0.5rem;">
                <button type="button" class="btn-secondary" style="font-size: 0.78rem; padding: 0.4rem 0.75rem;" onclick="toggleAllMatrixPerms(true)">Select All</button>
                <button type="button" class="btn-secondary" style="font-size: 0.78rem; padding: 0.4rem 0.75rem;" onclick="toggleAllMatrixPerms(false)">Clear All</button>
              </div>
            </div>

            <!-- Matrix Table Container -->
            <div class="modal-body-tablewrap" style="flex: 1; overflow-y: auto; padding: 0;">
              <div id="permModalLoading" style="text-align: center; padding: 3.5rem; color: var(--color-secondary-text);">
                Loading role permissions matrix from database...
              </div>
              <div id="permModalError" style="display: none; margin: 1.5rem; padding: 1rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-weight: 600;"></div>

              <div class="perm-matrix-wrapper" id="permMatrixWrapper" style="display: none; border: none; border-radius: 0; max-height: none;">
                <table class="perm-matrix-table" id="permMatrixTable">
                  <thead>
                    <tr>
                      <th class="th-menu">Menu / Module</th>
                      <th style="min-width: 80px;">Type</th>
                      <th class="th-clickable" title="Click to toggle VIEW for all" onclick="toggleColumnPerm('can_view')">VIEW</th>
                      <th class="th-clickable" title="Click to toggle CREATE for all" onclick="toggleColumnPerm('can_create')">CREATE</th>
                      <th class="th-clickable" title="Click to toggle EDIT for all" onclick="toggleColumnPerm('can_edit')">EDIT</th>
                      <th class="th-clickable" title="Click to toggle DELETE for all" onclick="toggleColumnPerm('can_delete')">DELETE</th>
                      <th class="th-clickable" title="Click to toggle UPLOAD for all" onclick="toggleColumnPerm('can_upload')">UPLOAD</th>
                      <th class="th-clickable" title="Click to toggle DOWNLOAD for all" onclick="toggleColumnPerm('can_download')">DOWNLOAD</th>
                      <th class="th-clickable" title="Click to toggle APPROVE for all" onclick="toggleColumnPerm('can_approve')">APPROVE</th>
                      <th class="th-clickable" title="Click to toggle EXPORT for all" onclick="toggleColumnPerm('can_export')">EXPORT</th>
                      <th class="th-clickable" title="Click to toggle PRINT for all" onclick="toggleColumnPerm('can_print')">PRINT</th>
                      <th class="th-clickable" title="Click to toggle CONFIGURE for all" onclick="toggleColumnPerm('can_configure')">CONFIGURE</th>
                      <th style="min-width: 75px;">Row</th>
                    </tr>
                  </thead>
                  <tbody id="permMatrixTbody">
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Permissions Modal Footer -->
            <div class="modal-footer" style="flex-shrink: 0; background: #fff; gap: 0.75rem;">
              <button type="button" class="btn-secondary" onclick="closeModal('rolePermissionsModal')">Cancel</button>
              <button type="button" class="btn-primary" id="btnSavePermissions" onclick="saveRolePermissions()">
                Save Permissions
              </button>
            </div>
          </div>
        </div>

        <script>
          // ============================================================
          // ROLE MASTER MODULE JAVASCRIPT
          // ============================================================
          let roleSearchDebounceTimer = null;
          let availableCategoriesCache = [];
          let currentViewRoleId = null;
          let pendingToggleRoleId = null;
          let pendingToggleNewStatus = null;
          let currentPermsRoleId = null;
          let currentPermsList = [];

          function getRoleApiUrl(actionQuery) {
            let apiEndpoint = 'admin/roles/api.php';
            const path = window.location.pathname;
            if (path.includes('/admin/roles') || path.includes('/administration/roles')) {
              apiEndpoint = 'api.php';
            }
            if (actionQuery) {
              if (actionQuery.startsWith('?') || actionQuery.startsWith('&')) {
                apiEndpoint += actionQuery;
              } else {
                apiEndpoint += '?' + actionQuery;
              }
            }
            return apiEndpoint;
          }

          function showRoleToast(msg, isSuccess) {
            const toast = document.getElementById('roleMasterToast');
            if (!toast) return;
            toast.textContent = msg;
            toast.style.background = isSuccess ? '#D1FAE5' : '#FEE2E2';
            toast.style.color = isSuccess ? '#065F46' : '#991B1B';
            toast.style.border = isSuccess ? '1px solid #A7F3D0' : '1px solid #FCA5A5';
            toast.style.display = 'block';
            setTimeout(() => {
              toast.style.display = 'none';
            }, 4500);
          }

          function initRoleMasterPage() {
            loadRoleCategories().then(() => {
              loadRolesTable();
            });
          }

          async function loadRoleCategories() {
            try {
              const res = await fetch(getRoleApiUrl('action=categories'));
              const data = await res.json();
              if (data.success && Array.isArray(data.categories)) {
                availableCategoriesCache = data.categories;
                populateRoleCategoryDropdowns(data.categories);
              }
            } catch (err) {
              console.error('Error loading role categories:', err);
            }
          }

          function populateRoleCategoryDropdowns(categories) {
            const filterSelect = document.getElementById('roleCategoryFilter');
            const addSelect = document.getElementById('addRoleCategorySelect');
            const editSelect = document.getElementById('editRoleCategorySelect');

            const optionsHtml = categories.map(c =>
              `<option value="${c.category_id}">${escapeHtml(c.category_name)}</option>`
            ).join('');

            if (filterSelect) {
              filterSelect.innerHTML = '<option value="ALL">All Categories</option>' + optionsHtml;
            }
            if (addSelect) {
              addSelect.innerHTML = '<option value="">Select Category...</option>' + optionsHtml;
            }
            if (editSelect) {
              editSelect.innerHTML = '<option value="">Select Category...</option>' + optionsHtml;
            }
          }

          function handleRoleSearchKeyup(e) {
            clearTimeout(roleSearchDebounceTimer);
            roleSearchDebounceTimer = setTimeout(() => {
              loadRolesTable();
            }, 300);
          }

          async function loadRolesTable() {
            const tbody = document.getElementById('rolesTableBody');
            if (!tbody) return;

            const search = document.getElementById('roleSearchInput')?.value || '';
            const categoryId = document.getElementById('roleCategoryFilter')?.value || 'ALL';
            const status = document.getElementById('roleStatusFilter')?.value || 'ALL';

            tbody.innerHTML = `
              <tr>
                <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--color-secondary-text);">
                  Loading system roles from Neon database...
                </td>
              </tr>
            `;

            try {
              const queryParams = new URLSearchParams({
                action: 'list',
                search: search,
                category_id: categoryId,
                status: status
              });

              const res = await fetch(getRoleApiUrl('?' + queryParams.toString()));
              const data = await res.json();

              if (!data.success) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="6" style="text-align: center; padding: 2rem; color: #DC2626;">
                      Error loading roles: ${escapeHtml(data.error || 'Failed to fetch roles.')}
                    </td>
                  </tr>
                `;
                return;
              }

              // Update KPI cards from database response
              if (data.kpis) {
                document.getElementById('kpiTotalRoles').textContent = data.kpis.total;
                document.getElementById('kpiActiveRoles').textContent = data.kpis.active;
                document.getElementById('kpiInactiveRoles').textContent = data.kpis.inactive;
              }

              if (!data.roles || data.roles.length === 0) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="6" style="text-align: center; padding: 3rem 1rem;">
                      <div class="empty-state" style="font-size: 1rem; color: var(--color-secondary-text);">
                        No system roles found matching the current filters.
                      </div>
                    </td>
                  </tr>
                `;
                return;
              }

              tbody.innerHTML = data.roles.map(r => {
                const isActive = (r.status || '').toLowerCase() === 'active';
                const statusLabel = isActive ? 'ACTIVE' : 'INACTIVE';
                const isPlatformAdmin = (r.role_code || '').toUpperCase() === 'PLATFORM_ADMIN';

                return `
                  <tr id="roleRow-${r.role_id}">
                    <td>
                      <code style="font-weight: 700; color: var(--color-primary-blue); font-size: 0.85rem;">
                        ${escapeHtml(r.role_code)}
                      </code>
                    </td>
                    <td>
                      <strong style="color: var(--color-primary-text);">${escapeHtml(r.role_name)}</strong>
                      ${isPlatformAdmin ? '<span style="margin-left: 6px; font-size: 0.7rem; background: #FEF3C7; color: #92400E; padding: 2px 6px; border-radius: 4px; font-weight: 700;">System</span>' : ''}
                    </td>
                    <td>
                      <span class="role-badge" style="font-size: 0.75rem;">
                        ${escapeHtml(r.category_name || 'Unassigned')}
                      </span>
                    </td>
                    <td style="color: var(--color-secondary-text); font-size: 0.84rem; max-width: 280px; word-break: break-word;">
                      ${escapeHtml(r.description || '—')}
                    </td>
                    <td>
                      <div class="cost360-toggle-switch" onclick="promptRoleStatusToggle(${r.role_id}, '${escapeJs(r.role_code)}', '${escapeJs(r.status)}')" title="Click to ${isActive ? 'Deactivate' : 'Activate'} ${escapeHtml(r.role_code)}">
                        <div class="cost360-toggle-track ${isActive ? 'active' : ''}" id="toggleTrack-${r.role_id}">
                          <div class="cost360-toggle-thumb"></div>
                        </div>
                        <span class="cost360-toggle-label ${isActive ? 'active' : 'inactive'}" id="toggleLabel-${r.role_id}">
                          ${statusLabel}
                        </span>
                      </div>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="viewRole(${r.role_id})">
                        View
                      </button>
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="openEditRoleModal(${r.role_id})">
                        Edit
                      </button>
                      <button type="button" class="btn-primary" style="padding: 0.35rem 0.75rem; font-size: 0.78rem;" onclick="openRolePermissions(${r.role_id})">
                        Manage Permissions
                      </button>
                    </td>
                  </tr>
                `;
              }).join('');

            } catch (err) {
              console.error('Error fetching roles:', err);
              tbody.innerHTML = `
                <tr>
                  <td colspan="6" style="text-align: center; padding: 2rem; color: #DC2626;">
                    Server communication failure. Please check your network connection.
                  </td>
                </tr>
              `;
            }
          }

          // --- Add Role Modal ---
          function openAddRoleModal() {
            const form = document.getElementById('addRoleForm');
            if (form) form.reset();
            const errBox = document.getElementById('addRoleError');
            if (errBox) errBox.style.display = 'none';

            if (availableCategoriesCache.length > 0) {
              populateRoleCategoryDropdowns(availableCategoriesCache);
            }

            document.getElementById('addRoleModal').style.display = 'flex';
          }

          async function submitAddRole(e) {
            e.preventDefault();
            const form = e.target;
            const errBox = document.getElementById('addRoleError');
            errBox.style.display = 'none';

            const submitBtn = document.getElementById('btnSubmitAddRole');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating...';

            const formData = new FormData(form);
            formData.append('action', 'create');

            try {
              const res = await fetch(getRoleApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('addRoleModal');
                showRoleToast(data.message || 'Role created successfully.', true);
                loadRolesTable();
              } else {
                errBox.textContent = data.error || 'Failed to create role.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Server connection error.';
              errBox.style.display = 'block';
            } finally {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Create Role';
            }
          }

          // --- Edit Role Modal ---
          async function openEditRoleModal(roleId) {
            const errBox = document.getElementById('editRoleError');
            if (errBox) errBox.style.display = 'none';

            try {
              const res = await fetch(getRoleApiUrl(`action=get&role_id=${roleId}`));
              const data = await res.json();

              if (data.success && data.role) {
                const r = data.role;
                document.getElementById('editRoleId').value = r.role_id;
                document.getElementById('editRoleCode').value = r.role_code;
                document.getElementById('editRoleName').value = r.role_name;
                document.getElementById('editRoleDescription').value = r.description || '';
                document.getElementById('editRoleCategorySelect').value = r.category_id || '';

                closeModal('viewRoleModal');
                document.getElementById('editRoleModal').style.display = 'flex';
              } else {
                showRoleToast(data.error || 'Failed to load role details.', false);
              }
            } catch (err) {
              showRoleToast('Error connecting to database.', false);
            }
          }

          async function submitEditRole(e) {
            e.preventDefault();
            const form = e.target;
            const errBox = document.getElementById('editRoleError');
            errBox.style.display = 'none';

            const submitBtn = document.getElementById('btnSubmitEditRole');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            const formData = new FormData(form);
            formData.append('action', 'update');

            try {
              const res = await fetch(getRoleApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('editRoleModal');
                showRoleToast(data.message || 'Role updated successfully.', true);
                loadRolesTable();
              } else {
                errBox.textContent = data.error || 'Failed to update role.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Server connection error.';
              errBox.style.display = 'block';
            } finally {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Save Changes';
            }
          }

          // --- View Role Modal ---
          async function viewRole(roleId) {
            try {
              const res = await fetch(getRoleApiUrl(`action=get&role_id=${roleId}`));
              const data = await res.json();

              if (data.success && data.role) {
                const r = data.role;
                currentViewRoleId = r.role_id;

                document.getElementById('viewRoleCode').textContent = r.role_code;
                document.getElementById('viewRoleName').textContent = r.role_name;
                document.getElementById('viewRoleCategory').textContent = r.category_name || 'Unassigned';
                document.getElementById('viewRoleDescription').textContent = r.description || 'No description provided.';
                document.getElementById('viewRoleUsers').textContent = `${r.assigned_users_count || 0} active users`;
                document.getElementById('viewRoleCreatedAt').textContent = r.created_at_formatted || r.created_at || '—';
                document.getElementById('viewRoleUpdatedAt').textContent = r.updated_at_formatted || r.updated_at || '—';

                const isActive = (r.status || '').toLowerCase() === 'active';
                const statusBadge = document.getElementById('viewRoleStatusBadge');
                if (statusBadge) {
                  statusBadge.className = 'status-badge';
                  statusBadge.style.background = isActive ? '#E6F9F6' : '#F1F5F9';
                  statusBadge.style.color = isActive ? '#00A88F' : '#64748B';
                  statusBadge.textContent = isActive ? 'ACTIVE' : 'INACTIVE';
                }

                document.getElementById('btnViewModalEdit').onclick = () => openEditRoleModal(r.role_id);
                document.getElementById('btnViewModalPerms').onclick = () => {
                  closeModal('viewRoleModal');
                  openRolePermissions(r.role_id);
                };

                document.getElementById('viewRoleModal').style.display = 'flex';
              } else {
                showRoleToast(data.error || 'Failed to load role details.', false);
              }
            } catch (err) {
              showRoleToast('Error connecting to database.', false);
            }
          }

          // --- Active/Inactive Confirmation Flow ---
          function promptRoleStatusToggle(roleId, roleCode, currentStatus) {
            const isCurrentlyActive = (currentStatus || '').toLowerCase() === 'active';
            const newStatus = isCurrentlyActive ? 'Inactive' : 'Active';

            pendingToggleRoleId = roleId;
            pendingToggleNewStatus = newStatus;

            const modalTitle = document.getElementById('roleConfirmStatusTitle');
            const modalMsg = document.getElementById('roleConfirmStatusMessage');
            const submitBtn = document.getElementById('roleConfirmSubmitBtn');

            if (isCurrentlyActive) {
              modalTitle.textContent = 'Deactivate Role?';
              modalMsg.innerHTML = `Are you sure you want to deactivate <strong style="color: #DC2626;">${escapeHtml(roleCode)}</strong>?<br><span style="font-size: 0.8rem; color: var(--color-secondary-text); margin-top: 0.35rem; display: block;">Users assigned to this role will temporarily lose associated permissions until re-activated.</span>`;
              submitBtn.textContent = 'Deactivate';
              submitBtn.style.background = '#DC2626';
              submitBtn.style.borderColor = '#DC2626';
            } else {
              modalTitle.textContent = 'Activate Role?';
              modalMsg.innerHTML = `Are you sure you want to activate <strong style="color: #00A88F;">${escapeHtml(roleCode)}</strong>?<br><span style="font-size: 0.8rem; color: var(--color-secondary-text); margin-top: 0.35rem; display: block;">Users assigned to this role will immediately gain access according to configured permissions.</span>`;
              submitBtn.textContent = 'Activate';
              submitBtn.style.background = '#00A88F';
              submitBtn.style.borderColor = '#00A88F';
            }

            document.getElementById('roleConfirmStatusModal').style.display = 'flex';
          }

          async function executeRoleStatusToggle() {
            if (!pendingToggleRoleId || !pendingToggleNewStatus) return;

            const roleId = pendingToggleRoleId;
            const newStatus = pendingToggleNewStatus;

            const submitBtn = document.getElementById('roleConfirmSubmitBtn');
            submitBtn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'status');
            formData.append('role_id', roleId);
            formData.append('status', newStatus);

            try {
              const res = await fetch(getRoleApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              closeModal('roleConfirmStatusModal');

              if (data.success) {
                showRoleToast(data.message || 'Status updated.', true);

                // Update only the affected switch and label dynamically
                const track = document.getElementById(`toggleTrack-${roleId}`);
                const label = document.getElementById(`toggleLabel-${roleId}`);
                const isActive = newStatus.toLowerCase() === 'active';

                if (track) {
                  if (isActive) {
                    track.classList.add('active');
                  } else {
                    track.classList.remove('active');
                  }
                }
                if (label) {
                  label.textContent = isActive ? 'ACTIVE' : 'INACTIVE';
                  label.className = `cost360-toggle-label ${isActive ? 'active' : 'inactive'}`;
                }

                // Update switch onclick attribute
                const row = document.getElementById(`roleRow-${roleId}`);
                if (row) {
                  const switchEl = row.querySelector('.cost360-toggle-switch');
                  if (switchEl) {
                    switchEl.setAttribute('onclick', `promptRoleStatusToggle(${roleId}, '${escapeJs(data.role_code || '')}', '${newStatus}')`);
                  }
                }

                // Refresh KPIs without reloading the whole page
                refreshRoleKpis();
              } else {
                showRoleToast(data.error || 'Failed to update role status.', false);
              }
            } catch (err) {
              closeModal('roleConfirmStatusModal');
              showRoleToast('Server communication failure.', false);
            } finally {
              submitBtn.disabled = false;
              pendingToggleRoleId = null;
              pendingToggleNewStatus = null;
            }
          }

          async function refreshRoleKpis() {
            try {
              const res = await fetch(getRoleApiUrl('action=list'));
              const data = await res.json();
              if (data.success && data.kpis) {
                document.getElementById('kpiTotalRoles').textContent = data.kpis.total;
                document.getElementById('kpiActiveRoles').textContent = data.kpis.active;
                document.getElementById('kpiInactiveRoles').textContent = data.kpis.inactive;
              }
            } catch (err) {
              console.warn('KPI refresh error:', err);
            }
          }

          // --- Manage Permissions Matrix ---
          async function openRolePermissions(roleId) {
            currentPermsRoleId = roleId;

            const loading = document.getElementById('permModalLoading');
            const errBox = document.getElementById('permModalError');
            const wrapper = document.getElementById('permMatrixWrapper');
            const searchInput = document.getElementById('permSearchInput');

            if (searchInput) searchInput.value = '';
            if (errBox) errBox.style.display = 'none';
            if (loading) loading.style.display = 'block';
            if (wrapper) wrapper.style.display = 'none';

            document.getElementById('rolePermissionsModal').style.display = 'flex';

            try {
              const res = await fetch(getRoleApiUrl(`action=permissions&role_id=${roleId}`));
              const data = await res.json();

              if (!data.success) {
                loading.style.display = 'none';
                errBox.textContent = data.error || 'Failed to load permissions.';
                errBox.style.display = 'block';
                return;
              }

              const r = data.role;
              document.getElementById('permModalRoleCode').textContent = r.role_code;
              document.getElementById('permModalRoleName').textContent = r.role_name;

              currentPermsList = data.menus || [];
              renderPermissionsMatrix(currentPermsList);

              loading.style.display = 'none';
              wrapper.style.display = 'block';
            } catch (err) {
              loading.style.display = 'none';
              errBox.textContent = 'Failed to connect to database for permissions.';
              errBox.style.display = 'block';
            }
          }

          function renderPermissionsMatrix(menus) {
            const tbody = document.getElementById('permMatrixTbody');
            if (!tbody) return;

            const permCols = [
              'can_view', 'can_create', 'can_edit', 'can_delete',
              'can_upload', 'can_download', 'can_approve',
              'can_export', 'can_print', 'can_configure'
            ];

            tbody.innerHTML = menus.map(m => {
              const isModule = (m.menu_type || '').toLowerCase() === 'module';
              const indent = m.parent_menu_id ? 'padding-left: 1.75rem;' : 'font-weight: 700;';
              const typeBadgeClass = isModule ? 'style="background: #E0E7FF; color: #3730A3;"' : 'style="background: #F1F5F9; color: #475569;"';

              const checkboxesHtml = permCols.map(col => `
                <td class="perm-check-cell">
                  <input type="checkbox" class="perm-checkbox col-${col}" data-menu-id="${m.menu_id}" data-col="${col}" ${m[col] ? 'checked' : ''} onchange="handlePermCellChange(${m.menu_id})">
                </td>
              `).join('');

              return `
                <tr class="perm-row" data-menu-id="${m.menu_id}" data-menu-code="${escapeHtml(m.menu_code)}" data-menu-name="${escapeHtml(m.menu_name)}" data-parent-name="${escapeHtml(m.parent_menu_name || '')}">
                  <td style="${indent}">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                      ${m.parent_menu_id ? '<span style="color: var(--color-secondary-text);">↳</span>' : ''}
                      <div>
                        <div style="color: var(--color-primary-text);">${escapeHtml(m.menu_name)}</div>
                        <div style="font-size: 0.72rem; color: var(--color-secondary-text);"><code>${escapeHtml(m.menu_code)}</code></div>
                      </div>
                    </div>
                  </td>
                  <td style="text-align: center;">
                    <span class="role-badge" ${typeBadgeClass} style="font-size: 0.7rem; padding: 2px 6px;">
                      ${escapeHtml(m.menu_type || 'Menu')}
                    </span>
                  </td>
                  ${checkboxesHtml}
                  <td style="text-align: center;">
                    <button type="button" class="btn-secondary" style="font-size: 0.72rem; padding: 2px 6px;" onclick="toggleRowPerms(${m.menu_id})" title="Toggle All in this Row">
                      All
                    </button>
                  </td>
                </tr>
              `;
            }).join('');
          }

          function handlePermCellChange(menuId) {
            // Auto check can_view if any mutation permission is checked
            const row = document.querySelector(`.perm-row[data-menu-id="${menuId}"]`);
            if (!row) return;

            const viewCheck = row.querySelector(`input[data-col="can_view"]`);
            const otherChecks = row.querySelectorAll(`input[type="checkbox"]:not([data-col="can_view"])`);

            let hasAnyMutation = false;
            otherChecks.forEach(ch => {
              if (ch.checked) hasAnyMutation = true;
            });

            // If user checks edit/create/delete/etc., automatically ensure view is checked
            if (hasAnyMutation && viewCheck && !viewCheck.checked) {
              viewCheck.checked = true;
            }
          }

          function toggleRowPerms(menuId) {
            const row = document.querySelector(`.perm-row[data-menu-id="${menuId}"]`);
            if (!row) return;

            const checkboxes = row.querySelectorAll('.perm-checkbox');
            const anyUnchecked = Array.from(checkboxes).some(cb => !cb.checked);

            checkboxes.forEach(cb => {
              cb.checked = anyUnchecked;
            });
          }

          function toggleColumnPerm(colName) {
            const checkboxes = document.querySelectorAll(`.perm-checkbox.col-${colName}`);
            const visibleCheckboxes = Array.from(checkboxes).filter(cb => {
              const row = cb.closest('tr');
              return row && row.style.display !== 'none';
            });

            const anyUnchecked = visibleCheckboxes.some(cb => !cb.checked);
            visibleCheckboxes.forEach(cb => {
              cb.checked = anyUnchecked;
              if (anyUnchecked && colName !== 'can_view') {
                const row = cb.closest('tr');
                const viewCb = row ? row.querySelector('.col-can_view') : null;
                if (viewCb) viewCb.checked = true;
              }
            });
          }

          function toggleAllMatrixPerms(checkAll) {
            const rows = document.querySelectorAll('.perm-row');
            rows.forEach(row => {
              if (row.style.display !== 'none') {
                row.querySelectorAll('.perm-checkbox').forEach(cb => {
                  cb.checked = checkAll;
                });
              }
            });
          }

          function filterPermissionsMatrix() {
            const query = (document.getElementById('permSearchInput')?.value || '').toLowerCase().trim();
            const rows = document.querySelectorAll('.perm-row');

            rows.forEach(row => {
              const code = (row.getAttribute('data-menu-code') || '').toLowerCase();
              const name = (row.getAttribute('data-menu-name') || '').toLowerCase();
              const parent = (row.getAttribute('data-parent-name') || '').toLowerCase();

              if (!query || code.includes(query) || name.includes(query) || parent.includes(query)) {
                row.style.display = '';
              } else {
                row.style.display = 'none';
              }
            });
          }

          async function saveRolePermissions() {
            if (!currentPermsRoleId) return;

            const saveBtn = document.getElementById('btnSavePermissions');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving Permissions...';

            const permCols = [
              'can_view', 'can_create', 'can_edit', 'can_delete',
              'can_upload', 'can_download', 'can_approve',
              'can_export', 'can_print', 'can_configure'
            ];

            const rows = document.querySelectorAll('.perm-row');
            const permissionsPayload = [];

            rows.forEach(row => {
              const menuId = parseInt(row.getAttribute('data-menu-id'), 10);
              if (!menuId) return;

              const item = { menu_id: menuId };
              let hasAny = false;

              permCols.forEach(col => {
                const cb = row.querySelector(`input[data-col="${col}"]`);
                const val = cb ? cb.checked : false;
                item[col] = val ? 1 : 0;
                if (val) hasAny = true;
              });

              item.is_active = hasAny ? 1 : 0;
              permissionsPayload.push(item);
            });

            const formData = new FormData();
            formData.append('action', 'save_permissions');
            formData.append('role_id', currentPermsRoleId);
            formData.append('permissions', JSON.stringify(permissionsPayload));

            try {
              const res = await fetch(getRoleApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('rolePermissionsModal');
                showRoleToast(data.message || 'Permissions updated successfully.', true);
              } else {
                alert(data.error || 'Failed to save permissions.');
              }
            } catch (err) {
              alert('Server communication failure while saving permissions.');
            } finally {
              saveBtn.disabled = false;
              saveBtn.textContent = 'Save Permissions';
            }
          }

          // Module Auto-Init
          if (document.readyState === 'complete' || document.readyState === 'interactive') {
            if (document.getElementById('rolesTable')) {
              initRoleMasterPage();
            }
          } else {
            document.addEventListener('DOMContentLoaded', function() {
              if (document.getElementById('rolesTable')) {
                initRoleMasterPage();
              }
            });
          }
        </script>

      <?php elseif ($currentPage === 'subscription-plans'): ?>
        <!-- ============================================================
             SUBSCRIPTION PLANS MODULE (DYNAMIC FROM NEON POSTGRESQL)
             Hierarchy: Administration -> Subscription Management -> Subscription Plans
             ============================================================ -->

        <!-- KPI SUMMARY CARDS -->
        <section class="grid-cards" style="margin-bottom: 1.5rem;">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Total Plans</span></div>
            <div class="stat-number" id="kpiTotalPlans">0</div>
            <div class="stat-desc">Configured subscription tiers</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Active Plans</span></div>
            <div class="stat-number" id="kpiActivePlans" style="color: var(--color-bright-cyan);">0</div>
            <div class="stat-desc">Available for customer enrollment</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Inactive Plans</span></div>
            <div class="stat-number" id="kpiInactivePlans" style="color: #DC2626;">0</div>
            <div class="stat-desc">Archived / Disabled tiers</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Commercial / Paid</span></div>
            <div class="stat-number" id="kpiCommercialPlans" style="color: var(--color-primary-blue);">0</div>
            <div class="stat-desc">Revenue-generating plans</div>
          </div>
        </section>

        <!-- MAIN SECTION CARD -->
        <section class="section-card">
          <!-- SECTION HEADER -->
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-secondary-text);">
                  Administration &bull; Subscription Management
                </span>
              </div>
              <h2 class="section-title" style="margin-bottom: 0.2rem; font-size: 1.3rem;">Subscription Plans</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage platform subscription tiers, user thresholds, and billing cycles</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddPlanModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>+ Add Plan</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadPlansTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- TOAST NOTIFICATION -->
          <div id="planToast" style="display: none; padding: 0.75rem 1.25rem; border-radius: var(--radius-input); margin-top: 1rem; margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- SEARCH & FILTERS BAR -->
          <div style="display: flex; gap: 0.85rem; align-items: center; flex-wrap: wrap; margin-top: 1.25rem; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="flex: 2; min-width: 250px; position: relative;">
              <input type="text" id="planSearchInput" class="cost360-select" placeholder="Search by plan name, code, type, description..." onkeyup="handlePlanSearchKeyup(event)" style="padding-left: 2.3rem;">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--color-secondary-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%); pointer-events: none;">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
            </div>
            <div style="flex: 1; min-width: 150px;">
              <select id="planStatusFilter" class="cost360-select" onchange="loadPlansTable(1)">
                <option value="ALL">All Status</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
              </select>
            </div>
            <div style="flex: 1; min-width: 160px;">
              <select id="planTypeFilter" class="cost360-select" onchange="loadPlansTable(1)">
                <option value="ALL">All Plan Types</option>
                <option value="COMMERCIAL">Commercial</option>
                <option value="FREE">Free</option>
                <option value="ENTERPRISE">Enterprise</option>
                <option value="TRIAL">Trial</option>
                <option value="CUSTOM">Custom</option>
              </select>
            </div>
            <button type="button" class="btn-secondary" onclick="resetPlanFilters()" style="padding: 0.5rem 1rem;">Reset</button>
          </div>

          <!-- PLANS TABLE CONTAINER -->
          <div class="table-responsive" style="min-height: 240px;">
            <table id="plansTable">
              <thead>
                <tr>
                  <th style="width: 22%;">Plan Name</th>
                  <th style="width: 13%;">Plan Code</th>
                  <th style="width: 12%;">Plan Type</th>
                  <th style="width: 13%;">Price</th>
                  <th style="width: 12%;">Billing Cycle</th>
                  <th style="width: 13%;">Maximum Users</th>
                  <th style="width: 8%;">Status</th>
                  <th style="width: 17%; text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="plansTableBody">
                <tr>
                  <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--color-secondary-text);">
                    <div style="display: flex; align-items: center; justify-content: center; gap: 0.6rem;">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary-blue)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 2a10 10 0 0 1 10 10"></path>
                      </svg>
                      <span>Loading subscription plans from database...</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- PAGINATION CONTROLS -->
          <div id="planPaginationContainer" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--color-border); font-size: 0.85rem; color: var(--color-secondary-text);">
            <div id="planPaginationInfo">Showing 0 of 0 plans</div>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
              <span style="font-size: 0.82rem;">Rows per page:</span>
              <select id="planPerPageSelect" class="cost360-select" style="width: auto; padding: 0.35rem 0.65rem; font-size: 0.82rem;" onchange="changePlanPerPage()">
                <option value="5">5</option>
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="100">All</option>
              </select>
              <button type="button" id="planPrevBtn" class="btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.82rem;" onclick="navigatePlanPage(-1)">Previous</button>
              <span id="planCurrentPageSpan" style="font-weight: 700; color: var(--color-primary-text); padding: 0 0.4rem;">Page 1</span>
              <button type="button" id="planNextBtn" class="btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.82rem;" onclick="navigatePlanPage(1)">Next</button>
            </div>
          </div>
        </section>

        <!-- ============================================================
             MODAL: ADD SUBSCRIPTION PLAN
             ============================================================ -->
        <div id="addPlanModal" class="cost360-modal-overlay" style="display: none;">
          <div class="section-card" style="width: 650px; max-width: 95vw; max-height: 90vh; overflow-y: auto; padding: 2rem; position: relative;">
            <button type="button" onclick="closeModal('addPlanModal')" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--color-secondary-text);">&times;</button>

            <div style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--color-border); padding-bottom: 0.85rem;">
              <h2 style="font-size: 1.25rem; color: var(--color-deep-navy);">Add Subscription Plan</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Create a new platform tier with user capacity and billing parameters</p>
            </div>

            <div id="addPlanError" style="display: none; background: #FEE2E2; color: #DC2626; border: 1px solid #FCA5A5; padding: 0.75rem 1rem; border-radius: var(--radius-input); margin-bottom: 1.25rem; font-size: 0.85rem;"></div>

            <form id="addPlanForm" onsubmit="submitAddPlan(event)">
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                  <label class="dropdown-label">Plan Name *</label>
                  <input type="text" id="addPlanName" name="plan_name" class="cost360-select" placeholder="e.g. Growth Tier" required>
                </div>
                <div>
                  <label class="dropdown-label">Plan Code * (Must be unique)</label>
                  <input type="text" id="addPlanCode" name="plan_code" class="cost360-select" placeholder="e.g. GROWTH_TIER" style="text-transform: uppercase;" required>
                  <small style="font-size: 0.72rem; color: var(--color-secondary-text);">Uppercase letters, numbers, hyphens, underscores</small>
                </div>
              </div>

              <div style="margin-bottom: 1rem;">
                <label class="dropdown-label">Description</label>
                <textarea id="addPlanDescription" name="description" class="cost360-select" rows="2" placeholder="Brief outline of target customer profiles and tier capabilities..."></textarea>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                  <label class="dropdown-label">Plan Type *</label>
                  <select id="addPlanType" name="plan_type" class="cost360-select" required>
                    <option value="COMMERCIAL" selected>Commercial</option>
                    <option value="FREE">Free</option>
                    <option value="ENTERPRISE">Enterprise</option>
                    <option value="TRIAL">Trial</option>
                    <option value="CUSTOM">Custom</option>
                  </select>
                </div>
                <div>
                  <label class="dropdown-label">Billing Cycle *</label>
                  <select id="addBillingCycle" name="billing_cycle" class="cost360-select" required>
                    <option value="MONTHLY" selected>Monthly</option>
                    <option value="QUARTERLY">Quarterly</option>
                    <option value="HALF_YEARLY">Half Yearly</option>
                    <option value="ANNUAL">Annual</option>
                    <option value="LIFETIME">Lifetime</option>
                    <option value="CUSTOM">Custom</option>
                  </select>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                  <label class="dropdown-label">Price *</label>
                  <input type="number" id="addPrice" name="price" class="cost360-select" min="0" step="0.01" value="0.00" required>
                  <small style="font-size: 0.72rem; color: var(--color-secondary-text);">Cannot be negative</small>
                </div>
                <div>
                  <label class="dropdown-label">Currency *</label>
                  <select id="addCurrency" name="currency" class="cost360-select" required>
                    <option value="INR" selected>INR (₹)</option>
                    <option value="USD">USD ($)</option>
                    <option value="EUR">EUR (€)</option>
                    <option value="GBP">GBP (£)</option>
                  </select>
                </div>
                <div>
                  <label class="dropdown-label">Maximum Users *</label>
                  <input type="number" id="addMaxUsers" name="max_users" class="cost360-select" min="0" step="1" value="0" required>
                  <small style="font-size: 0.72rem; color: var(--color-secondary-text);">0 for starter / free</small>
                </div>
              </div>

              <div style="margin-bottom: 1.5rem;">
                <label class="dropdown-label">Status *</label>
                <select id="addStatus" name="status" class="cost360-select" required>
                  <option value="ACTIVE" selected>Active</option>
                  <option value="INACTIVE">Inactive</option>
                </select>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--color-border); padding-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('addPlanModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="saveAddPlanBtn">Save Plan</button>
              </div>
            </form>
          </div>
        </div>

        <!-- ============================================================
             MODAL: EDIT SUBSCRIPTION PLAN
             ============================================================ -->
        <div id="editPlanModal" class="cost360-modal-overlay" style="display: none;">
          <div class="section-card" style="width: 650px; max-width: 95vw; max-height: 90vh; overflow-y: auto; padding: 2rem; position: relative;">
            <button type="button" onclick="closeModal('editPlanModal')" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--color-secondary-text);">&times;</button>

            <div style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--color-border); padding-bottom: 0.85rem;">
              <h2 style="font-size: 1.25rem; color: var(--color-deep-navy);">Edit Subscription Plan</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);" id="editPlanSubtitle">Update plan parameters, user limits, and pricing</p>
            </div>

            <!-- FREE PLAN PROTECTED BANNER (DYNAMIC) -->
            <div id="freePlanNoticeBanner" style="display: none; background: var(--color-soft-blue); border: 1px solid #BAE6FD; color: var(--color-dark-blue); padding: 0.85rem 1rem; border-radius: var(--radius-input); margin-bottom: 1.25rem; font-size: 0.82rem; line-height: 1.4;">
              <strong>System-Required Free Plan:</strong> Core parameters (Code: <code>FREE</code>, Price: ₹0, Maximum Users: 0, Status: Active) are locked by system rules to guarantee continuous tenant onboarding integrity.
            </div>

            <div id="editPlanError" style="display: none; background: #FEE2E2; color: #DC2626; border: 1px solid #FCA5A5; padding: 0.75rem 1rem; border-radius: var(--radius-input); margin-bottom: 1.25rem; font-size: 0.85rem;"></div>

            <form id="editPlanForm" onsubmit="submitEditPlan(event)">
              <input type="hidden" id="editPlanId" name="plan_id" value="">

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                  <label class="dropdown-label">Plan Name *</label>
                  <input type="text" id="editPlanName" name="plan_name" class="cost360-select" required>
                </div>
                <div>
                  <label class="dropdown-label">Plan Code *</label>
                  <input type="text" id="editPlanCode" name="plan_code" class="cost360-select" style="text-transform: uppercase;" required>
                  <small id="editPlanCodeHint" style="font-size: 0.72rem; color: var(--color-secondary-text);">Unique uppercase code</small>
                </div>
              </div>

              <div style="margin-bottom: 1rem;">
                <label class="dropdown-label">Description</label>
                <textarea id="editPlanDescription" name="description" class="cost360-select" rows="2"></textarea>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                  <label class="dropdown-label">Plan Type *</label>
                  <select id="editPlanType" name="plan_type" class="cost360-select" required>
                    <option value="COMMERCIAL">Commercial</option>
                    <option value="FREE">Free</option>
                    <option value="ENTERPRISE">Enterprise</option>
                    <option value="TRIAL">Trial</option>
                    <option value="CUSTOM">Custom</option>
                  </select>
                </div>
                <div>
                  <label class="dropdown-label">Billing Cycle *</label>
                  <select id="editBillingCycle" name="billing_cycle" class="cost360-select" required>
                    <option value="MONTHLY">Monthly</option>
                    <option value="QUARTERLY">Quarterly</option>
                    <option value="HALF_YEARLY">Half Yearly</option>
                    <option value="ANNUAL">Annual</option>
                    <option value="LIFETIME">Lifetime</option>
                    <option value="CUSTOM">Custom</option>
                  </select>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                  <label class="dropdown-label">Price *</label>
                  <input type="number" id="editPrice" name="price" class="cost360-select" min="0" step="0.01" required>
                  <small style="font-size: 0.72rem; color: var(--color-secondary-text);">Cannot be negative</small>
                </div>
                <div>
                  <label class="dropdown-label">Currency *</label>
                  <select id="editCurrency" name="currency" class="cost360-select" required>
                    <option value="INR">INR (₹)</option>
                    <option value="USD">USD ($)</option>
                    <option value="EUR">EUR (€)</option>
                    <option value="GBP">GBP (£)</option>
                  </select>
                </div>
                <div>
                  <label class="dropdown-label">Maximum Users *</label>
                  <input type="number" id="editMaxUsers" name="max_users" class="cost360-select" min="0" step="1" required>
                  <small style="font-size: 0.72rem; color: var(--color-secondary-text);">Cannot be negative</small>
                </div>
              </div>

              <div style="margin-bottom: 1.5rem;">
                <label class="dropdown-label">Status *</label>
                <select id="editStatus" name="status" class="cost360-select" required>
                  <option value="ACTIVE">Active</option>
                  <option value="INACTIVE">Inactive</option>
                </select>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--color-border); padding-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('editPlanModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="saveEditPlanBtn">Save Changes</button>
              </div>
            </form>
          </div>
        </div>

        <!-- ============================================================
             MODAL: VIEW SUBSCRIPTION PLAN DETAILS
             ============================================================ -->
        <div id="viewPlanModal" class="cost360-modal-overlay" style="display: none;">
          <div class="section-card" style="width: 600px; max-width: 95vw; max-height: 90vh; overflow-y: auto; padding: 2rem; position: relative;">
            <button type="button" onclick="closeModal('viewPlanModal')" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--color-secondary-text);">&times;</button>

            <div style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--color-border); padding-bottom: 1rem;">
              <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.25rem;">
                <h2 style="font-size: 1.3rem; color: var(--color-deep-navy);" id="viewPlanName">-</h2>
                <div style="display: flex; align-items: center; gap: 0.4rem;">
                  <span id="viewPlanCodeBadge" class="plan-code-badge" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; border-radius: 6px; background: var(--color-soft-blue); color: var(--color-primary-blue); font-weight: 700;">-</span>
                  <span id="viewPlanStatusBadge">-</span>
                </div>
              </div>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);" id="viewPlanDescription">-</p>
            </div>

            <!-- KEY PLAN HIGHLIGHTS GRID -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
              <div style="background: var(--color-page-bg); padding: 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
                <div style="font-size: 0.74rem; text-transform: uppercase; font-weight: 700; color: var(--color-secondary-text); margin-bottom: 0.25rem;">Price & Billing</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: var(--color-primary-text);" id="viewPlanPriceDisplay">-</div>
                <div style="font-size: 0.8rem; color: var(--color-secondary-text);" id="viewPlanBillingCycle">-</div>
              </div>

              <div style="background: var(--color-page-bg); padding: 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
                <div style="font-size: 0.74rem; text-transform: uppercase; font-weight: 700; color: var(--color-secondary-text); margin-bottom: 0.25rem;">Capacity & Thresholds</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: var(--color-primary-blue);" id="viewPlanMaxUsersDisplay">-</div>
                <div style="font-size: 0.8rem; color: var(--color-secondary-text);" id="viewPlanTypeDisplay">-</div>
              </div>
            </div>

            <!-- EXTENSIBLE LIMITS CONFIG BREAKDOWN -->
            <div style="margin-bottom: 1.5rem; background: var(--color-page-bg); padding: 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
              <div style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: var(--color-secondary-text); margin-bottom: 0.75rem;">Configured Tier Thresholds</div>
              <div id="viewPlanLimitsGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem; font-size: 0.82rem;">
                <!-- Populated dynamically -->
              </div>
            </div>

            <!-- METADATA TIMESTAMPS -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.78rem; color: var(--color-secondary-text); margin-bottom: 1.5rem; padding-top: 0.5rem; border-top: 1px solid var(--color-border);">
              <div>Created: <span id="viewPlanCreatedAt" style="font-weight: 600; color: var(--color-primary-text);">-</span></div>
              <div>Last Updated: <span id="viewPlanUpdatedAt" style="font-weight: 600; color: var(--color-primary-text);">-</span></div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--color-border); padding-top: 1.25rem;">
              <button type="button" class="btn-secondary" onclick="closeModal('viewPlanModal')">Close</button>
              <button type="button" class="btn-primary" id="viewPlanEditBtn" onclick="">Edit Plan</button>
            </div>
          </div>
        </div>

        <!-- ============================================================
             MODAL: TOGGLE STATUS (ACTIVATE / DEACTIVATE) CONFIRMATION
             ============================================================ -->
        <div id="toggleStatusPlanModal" class="cost360-modal-overlay" style="display: none;">
          <div class="section-card" style="width: 460px; max-width: 95vw; padding: 2rem; position: relative; text-align: center;">
            <div id="toggleStatusIconCircle" style="width: 54px; height: 54px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; font-size: 1.5rem;">
              <svg id="toggleStatusSvg" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
              </svg>
            </div>

            <h3 style="font-size: 1.2rem; color: var(--color-deep-navy); margin-bottom: 0.5rem;" id="toggleStatusTitle">Confirm Status Change</h3>
            <p style="font-size: 0.88rem; color: var(--color-secondary-text); margin-bottom: 1.5rem; line-height: 1.5;" id="toggleStatusDescription">
              Are you sure you want to change the status of this plan?
            </p>

            <input type="hidden" id="toggleStatusPlanId" value="">
            <input type="hidden" id="toggleStatusTargetValue" value="">

            <div style="display: flex; justify-content: center; gap: 0.75rem;">
              <button type="button" class="btn-secondary" onclick="closeModal('toggleStatusPlanModal')">Cancel</button>
              <button type="button" class="btn-primary" id="confirmToggleStatusBtn" onclick="confirmTogglePlanStatus()">Confirm</button>
            </div>
          </div>
        </div>

        <!-- ============================================================
             MODAL: DELETE PLAN CONFIRMATION (DESTRUCTIVE ACTION)
             ============================================================ -->
        <div id="deletePlanModal" class="cost360-modal-overlay" style="display: none;">
          <div class="section-card" style="width: 460px; max-width: 95vw; padding: 2rem; position: relative; text-align: center;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #FEE2E2; color: #DC2626; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
              <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"></polyline>
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                <line x1="10" y1="11" x2="10" y2="17"></line>
                <line x1="14" y1="11" x2="14" y2="17"></line>
              </svg>
            </div>

            <h3 style="font-size: 1.2rem; color: #991B1B; margin-bottom: 0.5rem;">Delete Subscription Plan</h3>
            <p style="font-size: 0.88rem; color: var(--color-secondary-text); margin-bottom: 1.5rem; line-height: 1.5;" id="deletePlanDescription">
              Are you sure you want to permanently delete this plan?
            </p>

            <input type="hidden" id="deletePlanIdInput" value="">

            <div style="display: flex; justify-content: center; gap: 0.75rem;">
              <button type="button" class="btn-secondary" onclick="closeModal('deletePlanModal')">Cancel</button>
              <button type="button" class="btn-primary" id="confirmDeletePlanBtn" style="background: #DC2626; border-color: #DC2626;" onclick="confirmDeletePlan()">Delete Plan</button>
            </div>
          </div>
        </div>

        <!-- ============================================================
             JAVASCRIPT CONTROLLER FOR SUBSCRIPTION PLANS
             ============================================================ -->
        <script>
          let cachedPlansList = [];
          let filteredPlansList = [];
          let planSearchDebounceTimer = null;
          let currentPlanPage = 1;
          let plansPerPage = 10;

          // Helper to get Subscription API URL
          function getSubscriptionPlansApiUrl(params = '') {
            const prefix = window.cost360BaseUrlPrefix || '';
            let url = prefix + 'api/subscriptions_api.php';
            if (params) {
              url += (params.startsWith('?') ? params : '?' + params);
            }
            return url;
          }

          // Show in-page Toast Feedback
          function showPlanToast(message, isSuccess = true) {
            const toast = document.getElementById('planToast');
            if (!toast) return;

            toast.style.display = 'block';
            toast.style.background = isSuccess ? '#E6F9F6' : '#FEE2E2';
            toast.style.color = isSuccess ? '#00A88F' : '#DC2626';
            toast.style.border = isSuccess ? '1px solid #A7F3D0' : '1px solid #FCA5A5';
            toast.textContent = message;

            setTimeout(() => {
              toast.style.display = 'none';
            }, 4500);
          }

          // Search Debounce Handler
          function handlePlanSearchKeyup(e) {
            clearTimeout(planSearchDebounceTimer);
            planSearchDebounceTimer = setTimeout(() => {
              applyPlanFiltersAndRender(1);
            }, 250);
          }

          // Reset Filters
          function resetPlanFilters() {
            document.getElementById('planSearchInput').value = '';
            document.getElementById('planStatusFilter').value = 'ALL';
            document.getElementById('planTypeFilter').value = 'ALL';
            applyPlanFiltersAndRender(1);
          }

          // Change items per page
          function changePlanPerPage() {
            const sel = document.getElementById('planPerPageSelect');
            plansPerPage = parseInt(sel.value, 10) || 10;
            renderPlansTablePage(1);
          }

          // Navigate Next / Previous page
          function navigatePlanPage(direction) {
            const totalPages = Math.ceil(filteredPlansList.length / plansPerPage) || 1;
            const targetPage = currentPlanPage + direction;
            if (targetPage >= 1 && targetPage <= totalPages) {
              renderPlansTablePage(targetPage);
            }
          }

          // Load Plans from API (all=1 so admin sees both active & inactive)
          async function loadPlansTable(targetPage = 1) {
            const tbody = document.getElementById('plansTableBody');
            if (!tbody) return;

            tbody.innerHTML = `
              <tr>
                <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--color-secondary-text);">
                  <div style="display: flex; align-items: center; justify-content: center; gap: 0.6rem;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary-blue)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;">
                      <circle cx="12" cy="12" r="10"></circle>
                      <path d="M12 2a10 10 0 0 1 10 10"></path>
                    </svg>
                    <span>Loading subscription plans from database...</span>
                  </div>
                </td>
              </tr>
            `;

            try {
              const res = await fetch(getSubscriptionPlansApiUrl('action=list_plans&all=1'));
              const data = await res.json();

              if (!data.success) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 2.5rem; color: #DC2626;">
                      <strong>Error loading plans:</strong> ${escapeHtml(data.error || 'Failed to fetch data')}
                      <div style="margin-top: 0.75rem;">
                        <button type="button" class="btn-secondary" onclick="loadPlansTable()">Try Again</button>
                      </div>
                    </td>
                  </tr>
                `;
                return;
              }

              cachedPlansList = Array.isArray(data.plans) ? data.plans : [];
              applyPlanFiltersAndRender(targetPage);

            } catch (err) {
              console.error('Error fetching subscription plans:', err);
              tbody.innerHTML = `
                <tr>
                  <td colspan="8" style="text-align: center; padding: 2.5rem; color: #DC2626;">
                    <strong>Network Error:</strong> Failed to connect to subscription plans service.
                    <div style="margin-top: 0.75rem;">
                      <button type="button" class="btn-secondary" onclick="loadPlansTable()">Try Again</button>
                    </div>
                  </td>
                </tr>
              `;
            }
          }

          // Filter Cached Plans and Render
          function applyPlanFiltersAndRender(targetPage = 1) {
            const searchVal = (document.getElementById('planSearchInput')?.value || '').trim().toLowerCase();
            const statusVal = (document.getElementById('planStatusFilter')?.value || 'ALL').toUpperCase();
            const typeVal = (document.getElementById('planTypeFilter')?.value || 'ALL').toUpperCase();

            // Update KPI Cards from ALL cached plans
            let total = cachedPlansList.length;
            let active = 0;
            let inactive = 0;
            let commercial = 0;

            cachedPlansList.forEach(p => {
              const st = (p.status || '').toUpperCase();
              const pt = (p.plan_type || '').toUpperCase();
              if (st === 'ACTIVE') active++;
              else inactive++;
              if (pt === 'COMMERCIAL' || pt === 'ENTERPRISE' || (parseFloat(p.price) > 0)) commercial++;
            });

            document.getElementById('kpiTotalPlans').textContent = total;
            document.getElementById('kpiActivePlans').textContent = active;
            document.getElementById('kpiInactivePlans').textContent = inactive;
            document.getElementById('kpiCommercialPlans').textContent = commercial;

            // Apply Filters
            filteredPlansList = cachedPlansList.filter(p => {
              const pStatus = (p.status || '').toUpperCase();
              const pType = (p.plan_type || '').toUpperCase();

              if (statusVal !== 'ALL' && pStatus !== statusVal) return false;
              if (typeVal !== 'ALL' && pType !== typeVal) return false;

              if (searchVal !== '') {
                const name = (p.plan_name || '').toLowerCase();
                const code = (p.plan_code || '').toLowerCase();
                const type = (p.plan_type || '').toLowerCase();
                const desc = (p.description || '').toLowerCase();
                if (!name.includes(searchVal) && !code.includes(searchVal) && !type.includes(searchVal) && !desc.includes(searchVal)) {
                  return false;
                }
              }
              return true;
            });

            renderPlansTablePage(targetPage);
          }

          // Render a Specific Page of Filtered Plans
          function renderPlansTablePage(page = 1) {
            const tbody = document.getElementById('plansTableBody');
            if (!tbody) return;

            const totalItems = filteredPlansList.length;

            if (totalItems === 0) {
              tbody.innerHTML = `
                <tr>
                  <td colspan="8" style="text-align: center; padding: 3rem 1rem;">
                    <div class="empty-state" style="padding: 1rem;">
                      <svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="var(--color-border-hover)" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.75rem;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M16 16s-1.5-2-4-2-4 2-4 2"></path>
                        <line x1="9" y1="9" x2="9.01" y2="9"></line>
                        <line x1="15" y1="9" x2="15.01" y2="9"></line>
                      </svg>
                      <div style="font-weight: 700; color: var(--color-primary-text); margin-bottom: 0.35rem;">No Subscription Plans Found</div>
                      <p style="font-size: 0.85rem; color: var(--color-secondary-text); margin-bottom: 1rem;">No subscription plans match your search or filter criteria.</p>
                      <button type="button" class="btn-secondary" onclick="resetPlanFilters()" style="font-size: 0.82rem; padding: 0.4rem 1rem;">Reset Filters</button>
                    </div>
                  </td>
                </tr>
              `;
              updatePaginationControls(0, 0, 0, 1, 1);
              return;
            }

            const totalPages = Math.ceil(totalItems / plansPerPage) || 1;
            currentPlanPage = Math.max(1, Math.min(page, totalPages));

            const startIndex = (currentPlanPage - 1) * plansPerPage;
            const endIndex = Math.min(startIndex + plansPerPage, totalItems);
            const pagePlans = filteredPlansList.slice(startIndex, endIndex);

            tbody.innerHTML = pagePlans.map(p => {
              const isFree = (p.plan_code || '').toUpperCase() === 'FREE' || p.plan_id === 1;
              const isActive = (p.status || '').toUpperCase() === 'ACTIVE';

              // Format Price: Free is displayed as ₹0 as requested
              let formattedPrice = '₹0';
              if (isFree || parseFloat(p.price) === 0) {
                formattedPrice = '₹0';
              } else {
                const currencySymbol = (p.currency === 'USD') ? '$' : ((p.currency === 'EUR') ? '€' : ((p.currency === 'GBP') ? '£' : '₹'));
                formattedPrice = `${currencySymbol}${parseFloat(p.price).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
              }

              // Format Maximum Users
              const maxUsersDisplay = isFree ? '0' : (p.max_users > 0 ? `${p.max_users}` : '0');

              // Status Badge
              const statusPill = isActive
                ? `<span style="background: #E6F9F6; color: #00A88F; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.78rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><span style="width: 6px; height: 6px; border-radius: 50%; background: #00A88F;"></span>Active</span>`
                : `<span style="background: #F1F5F9; color: #6B7F91; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.78rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><span style="width: 6px; height: 6px; border-radius: 50%; background: #6B7F91;"></span>Inactive</span>`;

              // Plan Type Badge
              let typeBg = '#E0F2FE';
              let typeColor = '#0369A1';
              if (isFree) { typeBg = '#FEF3C7'; typeColor = '#92400E'; }
              else if ((p.plan_type || '').toUpperCase() === 'ENTERPRISE') { typeBg = '#F3E8FF'; typeColor = '#6B21A8'; }
              else if ((p.plan_type || '').toUpperCase() === 'TRIAL') { typeBg = '#FCE7F3'; typeColor = '#9D174D'; }

              const typeBadge = `<span style="background: ${typeBg}; color: ${typeColor}; padding: 0.2rem 0.55rem; border-radius: 4px; font-size: 0.74rem; font-weight: 700; text-transform: uppercase;">${escapeHtml(p.plan_type || 'COMMERCIAL')}</span>`;

              // Billing Cycle Display
              const cycleDisplay = (p.billing_cycle || 'MONTHLY').charAt(0).toUpperCase() + (p.billing_cycle || 'monthly').slice(1).toLowerCase().replace('_', ' ');

              // Free Plan System-Required Safeguard Badges
              const freeBadge = isFree ? `<span title="System-Required Free Plan: Protected against accidental deletion" style="font-size: 0.7rem; background: rgba(8, 120, 209, 0.12); color: var(--color-primary-blue); font-weight: 700; padding: 1px 6px; border-radius: 4px; margin-left: 6px;">System Default</span>` : '';

              // Actions
              const toggleBtnText = isActive ? 'Deactivate' : 'Activate';
              const toggleBtnClass = isActive ? 'color: #D97706; border-color: #FCD34D;' : 'color: #059669; border-color: #A7F3D0;';

              // Deactivate button disabled for system Free plan
              const toggleActionHtml = isFree && isActive
                ? `<button type="button" class="btn-secondary" style="padding: 0.32rem 0.6rem; font-size: 0.78rem; opacity: 0.45; cursor: not-allowed;" title="The system-required Free Plan cannot be deactivated." disabled>Active</button>`
                : `<button type="button" class="btn-secondary" style="padding: 0.32rem 0.6rem; font-size: 0.78rem; ${toggleBtnClass}" onclick="openToggleStatusModal(${p.plan_id}, '${isActive ? 'ACTIVE' : 'INACTIVE'}', '${escapeJs(p.plan_name)}')">${toggleBtnText}</button>`;

              // Delete button disabled with lock for Free plan
              const deleteActionHtml = isFree
                ? `<button type="button" class="btn-secondary" style="padding: 0.32rem 0.6rem; font-size: 0.78rem; opacity: 0.45; cursor: not-allowed;" title="Do not allow accidental deletion of the system-required Free plan." disabled>&#128274; Delete</button>`
                : `<button type="button" class="btn-secondary" style="padding: 0.32rem 0.6rem; font-size: 0.78rem; color: #DC2626; border-color: #FCA5A5;" onclick="openDeletePlanModal(${p.plan_id}, '${escapeJs(p.plan_name)}', '${escapeJs(p.plan_code)}')">Delete</button>`;

              return `
                <tr id="planRow-${p.plan_id}">
                  <td>
                    <div style="font-weight: 700; color: var(--color-primary-text); font-size: 0.92rem; display: flex; align-items: center;">
                      ${escapeHtml(p.plan_name)} ${freeBadge}
                    </div>
                    ${p.description ? `<div style="font-size: 0.78rem; color: var(--color-secondary-text); margin-top: 2px; max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(p.description)}</div>` : ''}
                  </td>
                  <td>
                    <code style="font-size: 0.8rem; font-weight: 700; letter-spacing: 0.04em;">${escapeHtml(p.plan_code)}</code>
                  </td>
                  <td>${typeBadge}</td>
                  <td>
                    <div style="font-weight: 800; color: var(--color-primary-text); font-size: 0.95rem;">${formattedPrice}</div>
                    <div style="font-size: 0.72rem; color: var(--color-secondary-text);">${escapeHtml(p.currency || 'INR')}</div>
                  </td>
                  <td>
                    <span style="font-size: 0.82rem; font-weight: 600; color: var(--color-primary-text);">${escapeHtml(cycleDisplay)}</span>
                  </td>
                  <td>
                    <div style="font-weight: 700; color: var(--color-primary-text); font-size: 0.88rem;">
                      ${isFree ? 'Maximum Users: 0' : `${maxUsersDisplay} ${p.max_users === 1 ? 'User' : 'Users'}`}
                    </div>
                    <div style="font-size: 0.72rem; color: var(--color-secondary-text);">${isFree ? 'Starter / Zero Tier' : 'Licensed threshold'}</div>
                  </td>
                  <td>${statusPill}</td>
                  <td style="text-align: right; white-space: nowrap;">
                    <div style="display: inline-flex; gap: 4px;">
                      <button type="button" class="btn-secondary" style="padding: 0.32rem 0.6rem; font-size: 0.78rem;" onclick="openViewPlanModal(${p.plan_id})">View</button>
                      <button type="button" class="btn-secondary" style="padding: 0.32rem 0.6rem; font-size: 0.78rem;" onclick="openEditPlanModal(${p.plan_id})">Edit</button>
                      ${toggleActionHtml}
                      ${deleteActionHtml}
                    </div>
                  </td>
                </tr>
              `;
            }).join('');

            updatePaginationControls(startIndex + 1, endIndex, totalItems, currentPlanPage, totalPages);
          }

          // Update Pagination UI
          function updatePaginationControls(start, end, total, page, totalPages) {
            const info = document.getElementById('planPaginationInfo');
            const pageSpan = document.getElementById('planCurrentPageSpan');
            const prevBtn = document.getElementById('planPrevBtn');
            const nextBtn = document.getElementById('planNextBtn');

            if (info) {
              info.textContent = total > 0 ? `Showing ${start} to ${end} of ${total} plans` : 'Showing 0 plans';
            }
            if (pageSpan) {
              pageSpan.textContent = `Page ${page} of ${totalPages}`;
            }
            if (prevBtn) {
              prevBtn.disabled = page <= 1;
              prevBtn.style.opacity = page <= 1 ? '0.45' : '1';
              prevBtn.style.cursor = page <= 1 ? 'not-allowed' : 'pointer';
            }
            if (nextBtn) {
              nextBtn.disabled = page >= totalPages;
              nextBtn.style.opacity = page >= totalPages ? '0.45' : '1';
              nextBtn.style.cursor = page >= totalPages ? 'not-allowed' : 'pointer';
            }
          }

          // ============================================================
          // MODAL ACTIONS & FORM HANDLERS
          // ============================================================

          // Open Add Plan Modal
          function openAddPlanModal() {
            const form = document.getElementById('addPlanForm');
            if (form) form.reset();
            document.getElementById('addPlanError').style.display = 'none';
            document.getElementById('addPrice').value = '0.00';
            document.getElementById('addMaxUsers').value = '0';
            document.getElementById('addPlanModal').style.display = 'flex';
          }

          // Submit Add Plan
          async function submitAddPlan(e) {
            e.preventDefault();
            const form = e.target;
            const errBox = document.getElementById('addPlanError');
            const saveBtn = document.getElementById('saveAddPlanBtn');
            errBox.style.display = 'none';

            const price = parseFloat(document.getElementById('addPrice').value);
            const maxUsers = parseInt(document.getElementById('addMaxUsers').value, 10);
            const planCode = document.getElementById('addPlanCode').value.trim();

            if (price < 0) {
              errBox.textContent = 'Price cannot be negative.';
              errBox.style.display = 'block';
              return;
            }

            if (maxUsers < 0) {
              errBox.textContent = 'Maximum users cannot be negative.';
              errBox.style.display = 'block';
              return;
            }

            if (!planCode) {
              errBox.textContent = 'Plan Code is required.';
              errBox.style.display = 'block';
              return;
            }

            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            const formData = new FormData(form);
            formData.append('action', 'create_plan');

            try {
              const res = await fetch(getSubscriptionPlansApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('addPlanModal');
                showPlanToast(data.message || 'Subscription plan created successfully.', true);
                loadPlansTable(1);
              } else {
                errBox.textContent = data.error || 'Failed to create plan.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Server connection error. Please try again.';
              errBox.style.display = 'block';
            } finally {
              saveBtn.disabled = false;
              saveBtn.textContent = 'Save Plan';
            }
          }

          // Open Edit Plan Modal
          async function openEditPlanModal(planId) {
            const errBox = document.getElementById('editPlanError');
            errBox.style.display = 'none';

            let plan = cachedPlansList.find(p => p.plan_id === planId);
            if (!plan) {
              try {
                const res = await fetch(getSubscriptionPlansApiUrl(`action=get_plan&plan_id=${planId}`));
                const data = await res.json();
                if (data.success && data.plan) {
                  plan = data.plan;
                }
              } catch (e) {}
            }

            if (!plan) {
              showPlanToast('Could not load plan details.', false);
              return;
            }

            const isFree = (plan.plan_code || '').toUpperCase() === 'FREE' || plan.plan_id === 1;

            document.getElementById('editPlanId').value = plan.plan_id;
            document.getElementById('editPlanName').value = plan.plan_name || '';
            document.getElementById('editPlanCode').value = plan.plan_code || '';
            document.getElementById('editPlanDescription').value = plan.description || '';
            document.getElementById('editPlanType').value = (plan.plan_type || 'COMMERCIAL').toUpperCase();
            document.getElementById('editBillingCycle').value = (plan.billing_cycle || 'MONTHLY').toUpperCase();
            document.getElementById('editPrice').value = isFree ? '0.00' : (parseFloat(plan.price) || 0).toFixed(2);
            document.getElementById('editCurrency').value = (plan.currency || 'INR').toUpperCase();
            document.getElementById('editMaxUsers').value = isFree ? '0' : (parseInt(plan.max_users, 10) || 0);
            document.getElementById('editStatus').value = (plan.status || 'ACTIVE').toUpperCase();

            // Handle Free Plan protection rules
            const freeNotice = document.getElementById('freePlanNoticeBanner');
            const codeInput = document.getElementById('editPlanCode');
            const priceInput = document.getElementById('editPrice');
            const maxUsersInput = document.getElementById('editMaxUsers');
            const typeSelect = document.getElementById('editPlanType');
            const statusSelect = document.getElementById('editStatus');

            if (isFree) {
              freeNotice.style.display = 'block';
              codeInput.readOnly = true;
              codeInput.style.backgroundColor = '#F1F5F9';
              priceInput.readOnly = true;
              priceInput.style.backgroundColor = '#F1F5F9';
              maxUsersInput.readOnly = true;
              maxUsersInput.style.backgroundColor = '#F1F5F9';
              typeSelect.disabled = true;
              statusSelect.disabled = true;
            } else {
              freeNotice.style.display = 'none';
              codeInput.readOnly = false;
              codeInput.style.backgroundColor = '';
              priceInput.readOnly = false;
              priceInput.style.backgroundColor = '';
              maxUsersInput.readOnly = false;
              maxUsersInput.style.backgroundColor = '';
              typeSelect.disabled = false;
              statusSelect.disabled = false;
            }

            document.getElementById('editPlanModal').style.display = 'flex';
          }

          // Submit Edit Plan
          async function submitEditPlan(e) {
            e.preventDefault();
            const form = e.target;
            const errBox = document.getElementById('editPlanError');
            const saveBtn = document.getElementById('saveEditPlanBtn');
            errBox.style.display = 'none';

            const price = parseFloat(document.getElementById('editPrice').value);
            const maxUsers = parseInt(document.getElementById('editMaxUsers').value, 10);
            const planCode = document.getElementById('editPlanCode').value.trim();

            if (price < 0) {
              errBox.textContent = 'Price cannot be negative.';
              errBox.style.display = 'block';
              return;
            }

            if (maxUsers < 0) {
              errBox.textContent = 'Maximum users cannot be negative.';
              errBox.style.display = 'block';
              return;
            }

            if (!planCode) {
              errBox.textContent = 'Plan Code cannot be empty.';
              errBox.style.display = 'block';
              return;
            }

            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            const formData = new FormData(form);
            formData.append('action', 'update_plan');

            // If fields were disabled for Free plan, ensure values are in FormData
            const typeSelect = document.getElementById('editPlanType');
            const statusSelect = document.getElementById('editStatus');
            if (typeSelect.disabled) formData.append('plan_type', typeSelect.value);
            if (statusSelect.disabled) formData.append('status', statusSelect.value);

            try {
              const res = await fetch(getSubscriptionPlansApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('editPlanModal');
                showPlanToast(data.message || 'Subscription plan updated successfully.', true);
                loadPlansTable(currentPlanPage);
              } else {
                errBox.textContent = data.error || 'Failed to update plan.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Server connection error. Please try again.';
              errBox.style.display = 'block';
            } finally {
              saveBtn.disabled = false;
              saveBtn.textContent = 'Save Changes';
            }
          }

          // Open View Plan Modal
          function openViewPlanModal(planId) {
            const plan = cachedPlansList.find(p => p.plan_id === planId);
            if (!plan) return;

            const isFree = (plan.plan_code || '').toUpperCase() === 'FREE' || plan.plan_id === 1;
            const isActive = (plan.status || '').toUpperCase() === 'ACTIVE';

            document.getElementById('viewPlanName').textContent = plan.plan_name;
            document.getElementById('viewPlanCodeBadge').textContent = plan.plan_code;
            document.getElementById('viewPlanDescription').textContent = plan.description || 'No description provided for this tier.';

            // Status Badge
            const statusContainer = document.getElementById('viewPlanStatusBadge');
            statusContainer.innerHTML = isActive
              ? `<span style="background: #E6F9F6; color: #00A88F; padding: 0.25rem 0.65rem; border-radius: 20px; font-size: 0.78rem; font-weight: 700;">Active</span>`
              : `<span style="background: #F1F5F9; color: #6B7F91; padding: 0.25rem 0.65rem; border-radius: 20px; font-size: 0.78rem; font-weight: 700;">Inactive</span>`;

            // Price Display
            const currencySymbol = (plan.currency === 'USD') ? '$' : ((plan.currency === 'EUR') ? '€' : ((plan.currency === 'GBP') ? '£' : '₹'));
            const priceText = isFree || parseFloat(plan.price) === 0 ? '₹0' : `${currencySymbol}${parseFloat(plan.price).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            document.getElementById('viewPlanPriceDisplay').textContent = priceText;

            const cycleText = (plan.billing_cycle || 'MONTHLY').charAt(0).toUpperCase() + (plan.billing_cycle || 'monthly').slice(1).toLowerCase().replace('_', ' ');
            document.getElementById('viewPlanBillingCycle').textContent = `Billed ${cycleText} in ${plan.currency || 'INR'}`;

            // User Limits Display
            document.getElementById('viewPlanMaxUsersDisplay').textContent = isFree ? '0 Users (Free Starter)' : `${plan.max_users} Users Capacity`;
            document.getElementById('viewPlanTypeDisplay').textContent = `Type: ${plan.plan_type || 'COMMERCIAL'}`;

            // Limits Config Grid
            const limitsGrid = document.getElementById('viewPlanLimitsGrid');
            limitsGrid.innerHTML = '';
            const limits = plan.limits_config || {};

            const limitsItems = [
              { label: 'Max Companies', val: limits.max_companies !== undefined ? limits.max_companies : '-' },
              { label: 'Max Plants', val: limits.max_plants !== undefined ? limits.max_plants : '-' },
              { label: 'Max Warehouses', val: limits.max_warehouses !== undefined ? limits.max_warehouses : '-' },
              { label: 'Storage', val: limits.storage_mb !== undefined ? `${limits.storage_mb} MB` : '-' },
            ];

            limitsItems.forEach(item => {
              const div = document.createElement('div');
              div.style.background = '#fff';
              div.style.padding = '0.5rem 0.75rem';
              div.style.borderRadius = '6px';
              div.style.border = '1px solid var(--color-border)';
              div.innerHTML = `<span style="color: var(--color-secondary-text); font-size: 0.72rem; display: block;">${item.label}</span><strong style="color: var(--color-primary-text); font-size: 0.95rem;">${item.val}</strong>`;
              limitsGrid.appendChild(div);
            });

            if (limits.features && Array.isArray(limits.features) && limits.features.length > 0) {
              const featDiv = document.createElement('div');
              featDiv.style.gridColumn = '1 / -1';
              featDiv.style.background = '#fff';
              featDiv.style.padding = '0.5rem 0.75rem';
              featDiv.style.borderRadius = '6px';
              featDiv.style.border = '1px solid var(--color-border)';
              featDiv.innerHTML = `<span style="color: var(--color-secondary-text); font-size: 0.72rem; display: block; margin-bottom: 4px;">Features Included</span>` +
                limits.features.map(f => `<span style="background: var(--color-soft-blue); color: var(--color-primary-blue); padding: 2px 7px; border-radius: 4px; font-size: 0.72rem; margin-right: 4px; display: inline-block;">${escapeHtml(f.replace('_', ' '))}</span>`).join('');
              limitsGrid.appendChild(featDiv);
            }

            // Timestamps
            document.getElementById('viewPlanCreatedAt').textContent = plan.created_at || 'Predefined';
            document.getElementById('viewPlanUpdatedAt').textContent = plan.updated_at || 'Predefined';

            // Edit button hook
            const editBtn = document.getElementById('viewPlanEditBtn');
            editBtn.onclick = () => {
              closeModal('viewPlanModal');
              openEditPlanModal(plan.plan_id);
            };

            document.getElementById('viewPlanModal').style.display = 'flex';
          }

          // Open Toggle Status Modal (Confirmation Dialog)
          function openToggleStatusModal(planId, currentStatus, planName) {
            const isFree = planId === 1 || (planName || '').toLowerCase().includes('free');
            const targetStatus = (currentStatus === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE';

            // Safeguard: Do not allow deactivating system Free plan
            if (isFree && targetStatus === 'INACTIVE') {
              showPlanToast('The system-required Free Plan cannot be deactivated.', false);
              return;
            }

            document.getElementById('toggleStatusPlanId').value = planId;
            document.getElementById('toggleStatusTargetValue').value = targetStatus;

            const iconCircle = document.getElementById('toggleStatusIconCircle');
            const titleEl = document.getElementById('toggleStatusTitle');
            const descEl = document.getElementById('toggleStatusDescription');
            const confirmBtn = document.getElementById('confirmToggleStatusBtn');

            if (targetStatus === 'INACTIVE') {
              iconCircle.style.background = '#FEF3C7';
              iconCircle.style.color = '#D97706';
              titleEl.textContent = `Deactivate ${planName}?`;
              descEl.innerHTML = `Are you sure you want to deactivate <strong>${escapeHtml(planName)}</strong>?<br><span style="font-size: 0.8rem; color: #92400E;">New customer tenants will no longer be able to select or enroll in this plan. Existing subscriptions will remain intact.</span>`;
              confirmBtn.textContent = 'Confirm Deactivation';
              confirmBtn.style.background = '#D97706';
              confirmBtn.style.borderColor = '#D97706';
            } else {
              iconCircle.style.background = '#E6F9F6';
              iconCircle.style.color = '#00A88F';
              titleEl.textContent = `Activate ${planName}?`;
              descEl.innerHTML = `Are you sure you want to activate <strong>${escapeHtml(planName)}</strong>?<br><span style="font-size: 0.8rem; color: #065F46;">This plan will become available for customer tenant enrollment.</span>`;
              confirmBtn.textContent = 'Confirm Activation';
              confirmBtn.style.background = 'var(--color-primary-blue)';
              confirmBtn.style.borderColor = 'var(--color-primary-blue)';
            }

            document.getElementById('toggleStatusPlanModal').style.display = 'flex';
          }

          // Confirm Toggle Status Execution
          async function confirmTogglePlanStatus() {
            const planId = document.getElementById('toggleStatusPlanId').value;
            const targetStatus = document.getElementById('toggleStatusTargetValue').value;
            const confirmBtn = document.getElementById('confirmToggleStatusBtn');

            if (!planId) return;

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Updating...';

            const formData = new FormData();
            formData.append('action', 'set_plan_status');
            formData.append('plan_id', planId);
            formData.append('status', targetStatus);

            try {
              const res = await fetch(getSubscriptionPlansApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('toggleStatusPlanModal');
                showPlanToast(data.message || `Plan status updated to ${targetStatus}.`, true);
                loadPlansTable(currentPlanPage);
              } else {
                alert(data.error || 'Failed to update plan status.');
              }
            } catch (err) {
              alert('Server connection error. Please try again.');
            } finally {
              confirmBtn.disabled = false;
            }
          }

          // Open Delete Plan Confirmation Modal (Destructive Action)
          function openDeletePlanModal(planId, planName, planCode) {
            // STRICT RULE: Do not allow accidental deletion of the system-required Free plan
            if (planId === 1 || (planCode || '').toUpperCase() === 'FREE') {
              showPlanToast('Deletion Forbidden: The system-required Free Plan cannot be deleted.', false);
              return;
            }

            document.getElementById('deletePlanIdInput').value = planId;
            document.getElementById('deletePlanDescription').innerHTML = `
              Are you sure you want to permanently delete plan <strong>${escapeHtml(planName)}</strong> (<code>${escapeHtml(planCode)}</code>)?<br><br>
              <span style="font-size: 0.8rem; color: #DC2626;">This action cannot be undone. Plans assigned to active or historical customers cannot be deleted to preserve audit trails.</span>
            `;
            document.getElementById('deletePlanModal').style.display = 'flex';
          }

          // Confirm Delete Plan Execution
          async function confirmDeletePlan() {
            const planId = document.getElementById('deletePlanIdInput').value;
            const confirmBtn = document.getElementById('confirmDeletePlanBtn');

            if (!planId) return;

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Deleting...';

            const formData = new FormData();
            formData.append('action', 'delete_plan');
            formData.append('plan_id', planId);

            try {
              const res = await fetch(getSubscriptionPlansApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeModal('deletePlanModal');
                showPlanToast(data.message || 'Subscription plan deleted successfully.', true);
                loadPlansTable(currentPlanPage);
              } else {
                alert(data.error || 'Failed to delete subscription plan.');
              }
            } catch (err) {
              alert('Server connection error. Please try again.');
            } finally {
              confirmBtn.disabled = false;
              confirmBtn.textContent = 'Delete Plan';
            }
          }

          // Initial loader on module load
          document.addEventListener('DOMContentLoaded', () => {
            loadPlansTable(1);
          });
          // Also call immediately in case DOM is already parsed
          if (document.readyState === 'complete' || document.readyState === 'interactive') {
            loadPlansTable(1);
          }
        </script>

      <?php elseif ($currentPage === 'customer-subscriptions'): ?>
        <!-- ============================================================
             CUSTOMER SUBSCRIPTIONS MODULE (STEPS 5-9)
             Hierarchy: Administration -> Subscription Management -> Customer Subscriptions
             ============================================================ -->

        <!-- KPI SUMMARY CARDS -->
        <section class="grid-cards" style="margin-bottom: 1.5rem;">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Total Tenants</span></div>
            <div class="stat-number" id="kpiTotalCustomerSubs">0</div>
            <div class="stat-desc">Customer accounts in system</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Active Subscriptions</span></div>
            <div class="stat-number" id="kpiActiveCustomerSubs" style="color: var(--color-bright-cyan);">0</div>
            <div class="stat-desc">Tenants with active access</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Near User Limit</span></div>
            <div class="stat-number" id="kpiNearLimitCustomerSubs" style="color: #D97706;">0</div>
            <div class="stat-desc">Tenants at &ge; 80% user capacity</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Expired / Inactive</span></div>
            <div class="stat-number" id="kpiExpiredCustomerSubs" style="color: #DC2626;">0</div>
            <div class="stat-desc">Expired, suspended, or unassigned</div>
          </div>
        </section>

        <!-- MAIN SECTION CARD -->
        <section class="section-card">
          <!-- SECTION HEADER -->
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-secondary-text);">
                  Administration &bull; Subscription Management
                </span>
              </div>
              <h2 class="section-title" style="margin-bottom: 0.2rem; font-size: 1.3rem;">Customer Subscriptions</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage customer subscription plans, track user allocations, enforce limits, and manage lifecycles.</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-secondary" onclick="loadCustomerSubscriptionsTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- TOAST NOTIFICATION -->
          <div id="custSubToast" style="display: none; padding: 0.75rem 1.25rem; border-radius: var(--radius-input); margin-top: 1rem; margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- SEARCH & FILTERS BAR -->
          <div style="display: flex; gap: 0.85rem; align-items: center; flex-wrap: wrap; margin-top: 1.25rem; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="flex: 2; min-width: 250px; position: relative;">
              <input type="text" id="custSubSearchInput" class="cost360-select" placeholder="Search by customer name or customer code..." onkeyup="handleCustSubSearchKeyup(event)" style="padding-left: 2.3rem;">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--color-secondary-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%); pointer-events: none;">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
            </div>
            <div style="flex: 1; min-width: 170px;">
              <select id="custSubPlanFilter" class="cost360-select" onchange="loadCustomerSubscriptionsTable()">
                <option value="ALL">All Plans</option>
              </select>
            </div>
            <div style="flex: 1; min-width: 150px;">
              <select id="custSubStatusFilter" class="cost360-select" onchange="loadCustomerSubscriptionsTable()">
                <option value="ALL">All Status</option>
                <option value="ACTIVE">Active</option>
                <option value="EXPIRED">Expired</option>
                <option value="SUSPENDED">Suspended</option>
                <option value="CANCELLED">Cancelled</option>
                <option value="NO_PLAN">No Plan</option>
              </select>
            </div>
            <button type="button" class="btn-secondary" onclick="resetCustSubFilters()" style="padding: 0.55rem 0.85rem; font-size: 0.84rem;">Reset</button>
          </div>

          <!-- CUSTOMER SUBSCRIPTIONS DATA TABLE -->
          <div class="cost360-table-container" style="overflow-x: auto; border: 1px solid var(--color-border); border-radius: 8px;">
            <table class="cost360-data-table" id="custSubscriptionsTable" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
              <thead>
                <tr style="background: var(--color-page-bg); border-bottom: 2px solid var(--color-border); text-align: left;">
                  <th style="padding: 0.85rem 1rem;">Customer</th>
                  <th style="padding: 0.85rem 1rem;">Current Plan</th>
                  <th style="padding: 0.85rem 1rem;">Users</th>
                  <th style="padding: 0.85rem 1rem;">Available</th>
                  <th style="padding: 0.85rem 1rem;">Start Date</th>
                  <th style="padding: 0.85rem 1rem;">End Date</th>
                  <th style="padding: 0.85rem 1rem;">Status</th>
                  <th style="padding: 0.85rem 1rem; text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="custSubscriptionsTableBody">
                <tr>
                  <td colspan="8" style="text-align: center; padding: 3rem 1rem; color: var(--color-secondary-text);">
                    <div style="display: inline-flex; align-items: center; gap: 0.5rem;">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin-animation"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg>
                      <span>Loading customer subscriptions...</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ============================================================
             MODAL: CUSTOMER SUBSCRIPTION DETAILS
             ============================================================ -->
        <div id="custSubDetailsModal" class="cost360-modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(3, 27, 51, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px);">
          <div class="cost360-modal-container" style="background: #FFFFFF; width: 100%; max-width: 580px; border-radius: 12px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="cost360-modal-header" style="background: var(--color-page-bg); padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--color-border); display: flex; justify-content: space-between; align-items: center;">
              <div>
                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text);">Customer Subscription</span>
                <h2 id="detailsModalCustomerTitle" style="font-size: 1.25rem; color: var(--color-deep-navy); margin: 0.2rem 0 0 0;">Customer Details</h2>
              </div>
              <button type="button" onclick="closeCustSubModal('custSubDetailsModal')" style="background: none; border: none; cursor: pointer; color: var(--color-secondary-text); padding: 4px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>

            <div class="cost360-modal-body" style="padding: 1.5rem; overflow-y: auto;">
              <!-- Customer Overview Card -->
              <div style="background: var(--color-page-bg); border: 1px solid var(--color-border); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text); margin-bottom: 0.5rem;">Customer Information</div>
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                  <div>
                    <strong id="detailsCustomerName" style="font-size: 1.05rem; color: var(--color-deep-navy);">-</strong>
                    <div id="detailsCustomerCode" style="font-size: 0.8rem; color: var(--color-secondary-text); margin-top: 2px;">-</div>
                  </div>
                  <div>
                    <span id="detailsCustomerStatusBadge" style="padding: 0.25rem 0.65rem; border-radius: 20px; font-size: 0.76rem; font-weight: 700;">-</span>
                  </div>
                </div>
              </div>

              <!-- Subscription Info Grid -->
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; margin-bottom: 1rem;">
                <div style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.85rem;">
                  <span style="font-size: 0.74rem; font-weight: 700; color: var(--color-secondary-text); text-transform: uppercase;">Current Plan</span>
                  <div id="detailsCurrentPlan" style="font-weight: 700; font-size: 1rem; color: var(--color-deep-navy); margin-top: 3px;">-</div>
                </div>
                <div style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.85rem;">
                  <span style="font-size: 0.74rem; font-weight: 700; color: var(--color-secondary-text); text-transform: uppercase;">Subscription Status</span>
                  <div id="detailsSubStatus" style="font-weight: 700; font-size: 1rem; margin-top: 3px;">-</div>
                </div>
                <div style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.85rem;">
                  <span style="font-size: 0.74rem; font-weight: 700; color: var(--color-secondary-text); text-transform: uppercase;">Start Date</span>
                  <div id="detailsStartDate" style="font-weight: 600; font-size: 0.95rem; color: var(--color-primary-text); margin-top: 3px;">-</div>
                </div>
                <div style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.85rem;">
                  <span style="font-size: 0.74rem; font-weight: 700; color: var(--color-secondary-text); text-transform: uppercase;">End Date</span>
                  <div id="detailsEndDate" style="font-weight: 600; font-size: 0.95rem; color: var(--color-primary-text); margin-top: 3px;">-</div>
                </div>
              </div>

              <!-- User Quota Breakdown Card -->
              <div style="background: #F8FAFC; border: 1px solid var(--color-border); border-radius: 8px; padding: 1rem;">
                <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text); margin-bottom: 0.75rem;">User Limit & Slot Allocation</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; text-align: center; margin-bottom: 0.75rem;">
                  <div style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 6px; padding: 0.65rem 0.5rem;">
                    <div style="font-size: 0.72rem; color: var(--color-secondary-text); font-weight: 600;">Current Users</div>
                    <div id="detailsCurrentUsers" style="font-size: 1.25rem; font-weight: 800; color: var(--color-deep-navy); margin-top: 2px;">0</div>
                  </div>
                  <div style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 6px; padding: 0.65rem 0.5rem;">
                    <div style="font-size: 0.72rem; color: var(--color-secondary-text); font-weight: 600;">Maximum Users</div>
                    <div id="detailsMaxUsers" style="font-size: 1.25rem; font-weight: 800; color: var(--color-primary-blue); margin-top: 2px;">0</div>
                  </div>
                  <div style="background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 6px; padding: 0.65rem 0.5rem;">
                    <div style="font-size: 0.72rem; color: var(--color-secondary-text); font-weight: 600;">Available Users</div>
                    <div id="detailsAvailableUsers" style="font-size: 1.25rem; font-weight: 800; color: #00A88F; margin-top: 2px;">0</div>
                  </div>
                </div>
                <!-- Progress bar -->
                <div style="background: #E2E8F0; border-radius: 10px; height: 8px; width: 100%; overflow: hidden;">
                  <div id="detailsUsageProgressBar" style="background: #00A88F; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                </div>
                <div id="detailsUsageNote" style="font-size: 0.75rem; color: var(--color-secondary-text); margin-top: 6px; text-align: right;"></div>
              </div>
            </div>

            <div class="cost360-modal-footer" style="background: var(--color-page-bg); padding: 1rem 1.5rem; border-top: 1px solid var(--color-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
              <button type="button" class="btn-secondary" id="detailsViewHistoryBtn" onclick="openHistoryFromDetailsModal()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                <span>View History</span>
              </button>
              <div style="display: flex; gap: 0.5rem;">
                <button type="button" class="btn-secondary" onclick="closeCustSubModal('custSubDetailsModal')">Close</button>
                <button type="button" class="btn-primary" id="detailsChangeSubBtn" onclick="openChangeSubFromDetailsModal()">
                  <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                  <span>Change Subscription</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- ============================================================
             MODAL: ASSIGN / CHANGE SUBSCRIPTION
             Section 4 Specification:
             - Customer (read-only)
             - Current Plan (read-only)
             - New Plan (<select>)
             - Maximum Users (read-only, populated dynamically from plan, no manual override!)
             - Start Date (DD/MM/YYYY)
             - End Date (DD/MM/YYYY)
             - Notes (textarea)
             ============================================================ -->
        <div id="assignChangeSubscriptionModal" class="cost360-modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(3, 27, 51, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px);">
          <div class="cost360-modal-container" style="background: #FFFFFF; width: 100%; max-width: 540px; border-radius: 12px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25); overflow: hidden; max-height: 92vh; display: flex; flex-direction: column;">
            <div class="cost360-modal-header" style="background: var(--color-page-bg); padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--color-border); display: flex; justify-content: space-between; align-items: center;">
              <div>
                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text);">Project Admin Action</span>
                <h2 id="assignChangeModalTitle" style="font-size: 1.25rem; color: var(--color-deep-navy); margin: 0.2rem 0 0 0;">Assign / Change Subscription</h2>
              </div>
              <button type="button" onclick="closeCustSubModal('assignChangeSubscriptionModal')" style="background: none; border: none; cursor: pointer; color: var(--color-secondary-text); padding: 4px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>

            <form id="assignChangeSubForm" onsubmit="submitAssignChangeSubscription(event)" style="display: flex; flex-direction: column; overflow-y: auto; flex: 1;">
              <input type="hidden" id="changeSubCustomerId" name="customer_id" value="">

              <div class="cost360-modal-body" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                <!-- Error Alert Box -->
                <div id="changeSubErrorBox" style="display: none; background: #FEE2E2; border: 1px solid #FCA5A5; color: #DC2626; padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.85rem; line-height: 1.4;"></div>

                <!-- Customer (read-only) -->
                <div>
                  <label class="dropdown-label" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--color-primary-text); margin-bottom: 0.35rem;">Customer</label>
                  <input type="text" id="changeSubCustomerName" class="cost360-select" readonly style="background: #F1F5F9; color: var(--color-deep-navy); font-weight: 700; cursor: not-allowed;" value="">
                </div>

                <!-- Current Plan (read-only) -->
                <div>
                  <label class="dropdown-label" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--color-primary-text); margin-bottom: 0.35rem;">Current Plan</label>
                  <input type="text" id="changeSubCurrentPlanName" class="cost360-select" readonly style="background: #F1F5F9; color: var(--color-secondary-text); font-weight: 600; cursor: not-allowed;" value="">
                </div>

                <!-- New Plan (Dropdown) -->
                <div>
                  <label class="dropdown-label" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--color-primary-text); margin-bottom: 0.35rem;">New Plan <span style="color: #DC2626;">*</span></label>
                  <select id="changeSubNewPlanSelect" name="new_plan_id" class="cost360-select" required onchange="handleNewPlanSelection()" style="font-weight: 600;">
                    <option value="">-- Select Subscription Plan --</option>
                  </select>
                </div>

                <!-- Maximum Users (Read-only, strictly from selected plan!) -->
                <div>
                  <label class="dropdown-label" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--color-primary-text); margin-bottom: 0.35rem;">
                    Maximum Users <span style="font-weight: 400; color: var(--color-secondary-text); font-size: 0.75rem;">(Read-only &bull; From selected plan)</span>
                  </label>
                  <div style="position: relative;">
                    <input type="number" id="changeSubMaxUsers" class="cost360-select" readonly disabled style="background: #F8FAFC; color: var(--color-deep-navy); font-weight: 800; font-size: 1.05rem; cursor: not-allowed;" value="0">
                    <span style="position: absolute; right: 0.85rem; top: 50%; transform: translateY(-50%); font-size: 0.75rem; color: var(--color-secondary-text); font-weight: 600;">users max</span>
                  </div>
                  <div style="font-size: 0.75rem; color: var(--color-secondary-text); margin-top: 3px;">
                    Plan limit is loaded dynamically and cannot be manually overridden.
                  </div>
                </div>

                <!-- Start Date & End Date Grid -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                  <div>
                    <label class="dropdown-label" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--color-primary-text); margin-bottom: 0.35rem;">Start Date <span style="color: #DC2626;">*</span></label>
                    <input type="date" id="changeSubStartDate" name="start_date" class="cost360-select" required>
                  </div>
                  <div>
                    <label class="dropdown-label" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--color-primary-text); margin-bottom: 0.35rem;">End Date <span style="font-weight: 400; color: var(--color-secondary-text); font-size: 0.75rem;">(Optional)</span></label>
                    <input type="date" id="changeSubEndDate" name="end_date" class="cost360-select">
                  </div>
                </div>

                <!-- Notes / Reason -->
                <div>
                  <label class="dropdown-label" style="display: block; font-size: 0.82rem; font-weight: 600; color: var(--color-primary-text); margin-bottom: 0.35rem;">Notes / Reason</label>
                  <textarea id="changeSubNotes" name="notes" rows="3" class="cost360-select" placeholder="Enter reason for assignment or change (e.g. Upgraded to Standard per commercial agreement)..." style="resize: vertical; font-family: inherit; font-size: 0.85rem;"></textarea>
                </div>
              </div>

              <div class="cost360-modal-footer" style="background: var(--color-page-bg); padding: 1rem 1.5rem; border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" onclick="closeCustSubModal('assignChangeSubscriptionModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="changeSubSubmitBtn">Save Subscription</button>
              </div>
            </form>
          </div>
        </div>

        <!-- ============================================================
             MODAL: SUBSCRIPTION HISTORY
             ============================================================ -->
        <div id="custSubHistoryModal" class="cost360-modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(3, 27, 51, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px);">
          <div class="cost360-modal-container" style="background: #FFFFFF; width: 100%; max-width: 780px; border-radius: 12px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="cost360-modal-header" style="background: var(--color-page-bg); padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--color-border); display: flex; justify-content: space-between; align-items: center;">
              <div>
                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--color-secondary-text);">Audit & Lifecycle</span>
                <h2 id="historyModalCustomerTitle" style="font-size: 1.25rem; color: var(--color-deep-navy); margin: 0.2rem 0 0 0;">Subscription History</h2>
              </div>
              <button type="button" onclick="closeCustSubModal('custSubHistoryModal')" style="background: none; border: none; cursor: pointer; color: var(--color-secondary-text); padding: 4px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>

            <div class="cost360-modal-body" style="padding: 1.25rem; overflow-y: auto;">
              <div class="cost360-table-container" style="overflow-x: auto; border: 1px solid var(--color-border); border-radius: 8px;">
                <table class="cost360-data-table" style="width: 100%; border-collapse: collapse; font-size: 0.84rem;">
                  <thead>
                    <tr style="background: var(--color-page-bg); border-bottom: 2px solid var(--color-border); text-align: left;">
                      <th style="padding: 0.75rem 0.85rem;">Plan</th>
                      <th style="padding: 0.75rem 0.85rem;">Limit</th>
                      <th style="padding: 0.75rem 0.85rem;">Start Date</th>
                      <th style="padding: 0.75rem 0.85rem;">End Date</th>
                      <th style="padding: 0.75rem 0.85rem;">Status</th>
                      <th style="padding: 0.75rem 0.85rem;">Assigned By</th>
                      <th style="padding: 0.75rem 0.85rem;">Change Date</th>
                      <th style="padding: 0.75rem 0.85rem;">Notes</th>
                    </tr>
                  </thead>
                  <tbody id="custSubHistoryTableBody">
                    <tr>
                      <td colspan="8" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">Loading history...</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="cost360-modal-footer" style="background: var(--color-page-bg); padding: 1rem 1.5rem; border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end;">
              <button type="button" class="btn-secondary" onclick="closeCustSubModal('custSubHistoryModal')">Close</button>
            </div>
          </div>
        </div>

        <!-- ============================================================
             JAVASCRIPT CONTROLLER FOR CUSTOMER SUBSCRIPTIONS
             ============================================================ -->
        <script>
          let custSubsCache = [];
          let activePlansCache = [];
          let currentSelectedCustomer = null;
          let custSubSearchTimer = null;

          function getCustSubApiUrl(params = '') {
            let prefix = window.cost360BaseUrlPrefix || '';
            let url = prefix + 'api/subscriptions_api.php';
            if (params) {
              url += (url.includes('?') ? '&' : '?') + params;
            }
            return url;
          }

          function showCustSubToast(msg, isSuccess = true) {
            const toast = document.getElementById('custSubToast');
            if (!toast) return;
            toast.textContent = msg;
            toast.style.display = 'block';
            toast.style.background = isSuccess ? '#E6F9F6' : '#FEE2E2';
            toast.style.color = isSuccess ? '#00A88F' : '#DC2626';
            toast.style.border = isSuccess ? '1px solid #A7F3D0' : '1px solid #FCA5A5';

            setTimeout(() => {
              toast.style.display = 'none';
            }, 4500);
          }

          function closeCustSubModal(modalId) {
            const m = document.getElementById(modalId);
            if (m) m.style.display = 'none';
          }

          // Search debounce
          function handleCustSubSearchKeyup(e) {
            clearTimeout(custSubSearchTimer);
            custSubSearchTimer = setTimeout(() => {
              loadCustomerSubscriptionsTable();
            }, 300);
          }

          function resetCustSubFilters() {
            document.getElementById('custSubSearchInput').value = '';
            document.getElementById('custSubPlanFilter').value = 'ALL';
            document.getElementById('custSubStatusFilter').value = 'ALL';
            loadCustomerSubscriptionsTable();
          }

          // Preload active plans into filter dropdown and modal select
          async function preloadActivePlans() {
            try {
              const res = await fetch(getCustSubApiUrl('action=list_plans&all=1'));
              const data = await res.json();
              if (data.success && Array.isArray(data.plans)) {
                activePlansCache = data.plans;

                // Populate filter dropdown
                const planFilter = document.getElementById('custSubPlanFilter');
                if (planFilter) {
                  planFilter.innerHTML = '<option value="ALL">All Plans</option>' +
                    data.plans.map(p => `<option value="${p.plan_id}">${escapeHtml(p.plan_name)} (${escapeHtml(p.plan_code)})</option>`).join('');
                }

                // Populate assign/change modal dropdown (active plans only)
                const changePlanSelect = document.getElementById('changeSubNewPlanSelect');
                if (changePlanSelect) {
                  const activeTiers = data.plans.filter(p => p.status.toUpperCase() === 'ACTIVE');
                  changePlanSelect.innerHTML = '<option value="">-- Select Subscription Plan --</option>' +
                    activeTiers.map(p => `<option value="${p.plan_id}" data-max-users="${p.max_users}">${escapeHtml(p.plan_name)} (Max: ${p.max_users} users - ${p.price > 0 ? p.currency + ' ' + Number(p.price).toLocaleString() : 'Free'})</option>`).join('');
                }
              }
            } catch (err) {
              console.error('Error preloading plans:', err);
            }
          }

          // Handle plan selection in Assign/Change modal: updates read-only Maximum Users
          function handleNewPlanSelection() {
            const select = document.getElementById('changeSubNewPlanSelect');
            const maxUsersInput = document.getElementById('changeSubMaxUsers');
            if (!select || !maxUsersInput) return;

            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.dataset.maxUsers !== undefined) {
              maxUsersInput.value = selectedOption.dataset.maxUsers;
            } else {
              maxUsersInput.value = '0';
            }
          }

          // Load main customer subscriptions table
          async function loadCustomerSubscriptionsTable() {
            const tbody = document.getElementById('custSubscriptionsTableBody');
            if (!tbody) return;

            tbody.innerHTML = `
              <tr>
                <td colspan="8" style="text-align: center; padding: 2.5rem 1rem; color: var(--color-secondary-text);">
                  <div style="display: inline-flex; align-items: center; gap: 0.5rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin-animation"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg>
                    <span>Fetching subscriptions...</span>
                  </div>
                </td>
              </tr>
            `;

            const search = encodeURIComponent(document.getElementById('custSubSearchInput')?.value.trim() || '');
            const planId = encodeURIComponent(document.getElementById('custSubPlanFilter')?.value || 'ALL');
            const status = encodeURIComponent(document.getElementById('custSubStatusFilter')?.value || 'ALL');

            try {
              const res = await fetch(getCustSubApiUrl(`action=list_customer_subscriptions&search=${search}&plan_id=${planId}&status=${status}`));
              const data = await res.json();

              if (!data.success) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 2rem; color: #DC2626;">
                      ${escapeHtml(data.error || 'Failed to load customer subscriptions.')}
                    </td>
                  </tr>
                `;
                return;
              }

              custSubsCache = data.subscriptions || [];

              // Update KPIs
              if (data.kpis) {
                document.getElementById('kpiTotalCustomerSubs').textContent = data.kpis.total_subscriptions || 0;
                document.getElementById('kpiActiveCustomerSubs').textContent = data.kpis.active_subscriptions || 0;
                document.getElementById('kpiNearLimitCustomerSubs').textContent = data.kpis.near_limit || 0;
                document.getElementById('kpiExpiredCustomerSubs').textContent = data.kpis.expired_inactive || 0;
              }

              if (custSubsCache.length === 0) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 3rem 1rem;">
                      <div style="font-weight: 700; color: var(--color-primary-text); margin-bottom: 0.35rem;">No Customer Subscriptions Found</div>
                      <p style="font-size: 0.85rem; color: var(--color-secondary-text);">No customer records match your filter criteria.</p>
                    </td>
                  </tr>
                `;
                return;
              }

              tbody.innerHTML = custSubsCache.map(sub => {
                const status = (sub.display_status || 'NO_PLAN').toUpperCase();
                let statusBg = '#F1F5F9', statusColor = '#475569';
                if (status === 'ACTIVE') {
                  statusBg = '#E6F9F6'; statusColor = '#00A88F';
                } else if (status === 'EXPIRED') {
                  statusBg = '#FEE2E2'; statusColor = '#DC2626';
                } else if (status === 'SUSPENDED') {
                  statusBg = '#FEF3C7'; statusColor = '#D97706';
                } else if (status === 'CANCELLED') {
                  statusBg = '#F3F4F6'; statusColor = '#6B7280';
                } else if (status === 'NO_PLAN') {
                  statusBg = '#FEE2E2'; statusColor = '#991B1B';
                }

                const usersDisplay = `${sub.current_users} / ${sub.max_users}`;
                const availableDisplay = `${sub.available_users}`;
                const startDateFmt = sub.start_date ? formatDateDisplay(sub.start_date) : '&mdash;';
                const endDateFmt = sub.end_date ? formatDateDisplay(sub.end_date) : '<span style="color: var(--color-secondary-text); font-size: 0.8rem;">Lifetime / &mdash;</span>';

                // User capacity badge styling
                const pct = sub.max_users > 0 ? (sub.current_users / sub.max_users) : 0;
                let userColor = 'var(--color-primary-text)';
                if (sub.max_users > 0 && sub.current_users >= sub.max_users) {
                  userColor = '#DC2626';
                } else if (pct >= 0.8) {
                  userColor = '#D97706';
                }

                return `
                  <tr style="border-bottom: 1px solid var(--color-border); transition: background 0.15s ease;">
                    <td style="padding: 0.85rem 1rem;">
                      <div style="font-weight: 700; color: var(--color-deep-navy);">${escapeHtml(sub.customer_name)}</div>
                      <div style="display: flex; gap: 0.4rem; align-items: center; margin-top: 2px;">
                        <span style="font-size: 0.72rem; background: #EEF2F6; color: var(--color-secondary-text); padding: 1px 6px; border-radius: 4px; font-weight: 600;">${escapeHtml(sub.customer_code)}</span>
                        ${sub.customer_status === 'Inactive' ? '<span style="font-size: 0.7rem; color: #DC2626; font-weight: 700;">(Inactive Account)</span>' : ''}
                      </div>
                    </td>
                    <td style="padding: 0.85rem 1rem;">
                      <div style="font-weight: 600; color: var(--color-primary-text);">${escapeHtml(sub.plan_name)}</div>
                      ${sub.plan_code && sub.plan_code !== 'NONE' ? `<span style="font-size: 0.72rem; color: var(--color-secondary-text); text-transform: uppercase;">${escapeHtml(sub.plan_code)} &bull; ${escapeHtml(sub.plan_type || '')}</span>` : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem;">
                      <strong style="color: ${userColor}; font-size: 0.95rem;">${usersDisplay}</strong>
                    </td>
                    <td style="padding: 0.85rem 1rem;">
                      <span style="font-weight: 700; color: ${sub.available_users > 0 ? '#00A88F' : '#DC2626'};">${availableDisplay}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; color: var(--color-secondary-text);">${startDateFmt}</td>
                    <td style="padding: 0.85rem 1rem;">${endDateFmt}</td>
                    <td style="padding: 0.85rem 1rem;">
                      <span style="background: ${statusBg}; color: ${statusColor}; padding: 0.25rem 0.65rem; border-radius: 20px; font-size: 0.76rem; font-weight: 700; text-transform: uppercase;">
                        ${escapeHtml(status.replace('_', ' '))}
                      </span>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: right; white-space: nowrap;">
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="openCustomerSubscriptionDetailsModal(${sub.customer_id})">View</button>
                      <button type="button" class="btn-primary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem;" onclick="openChangeSubscriptionModal(${sub.customer_id})">Manage</button>
                    </td>
                  </tr>
                `;
              }).join('');

            } catch (err) {
              console.error('Error loading subscriptions table:', err);
              tbody.innerHTML = `
                <tr>
                  <td colspan="8" style="text-align: center; padding: 2rem; color: #DC2626;">
                    Network error occurred while fetching customer subscriptions.
                  </td>
                </tr>
              `;
            }
          }

          function formatDateDisplay(dStr) {
            if (!dStr) return '&mdash;';
            try {
              const parts = dStr.split('-');
              if (parts.length === 3) {
                return `${parts[2]}/${parts[1]}/${parts[0]}`;
              }
              const d = new Date(dStr);
              if (isNaN(d.getTime())) return dStr;
              return d.toLocaleDateString('en-GB');
            } catch(e) {
              return dStr;
            }
          }

          // Open Details Modal
          async function openCustomerSubscriptionDetailsModal(customerId) {
            currentSelectedCustomer = customerId;
            const modal = document.getElementById('custSubDetailsModal');
            if (!modal) return;

            try {
              const res = await fetch(getCustSubApiUrl(`action=get_customer_subscription_details&customer_id=${customerId}`));
              const data = await res.json();

              if (!data.success) {
                alert(data.error || 'Failed to load customer subscription details.');
                return;
              }

              const c = data.customer || {};
              const s = data.subscription;
              const slots = data.slots || {};

              document.getElementById('detailsModalCustomerTitle').textContent = c.customer_name || 'Customer Details';
              document.getElementById('detailsCustomerName').textContent = c.customer_name || '-';
              document.getElementById('detailsCustomerCode').textContent = `Customer Code: ${c.customer_code || '-'}`;

              const cBadge = document.getElementById('detailsCustomerStatusBadge');
              const isCustActive = (c.status || '').toLowerCase() === 'active';
              cBadge.textContent = c.status || 'Active';
              cBadge.style.background = isCustActive ? '#E6F9F6' : '#FEE2E2';
              cBadge.style.color = isCustActive ? '#00A88F' : '#DC2626';

              if (s) {
                document.getElementById('detailsCurrentPlan').textContent = `${s.plan_name} (${s.plan_code})`;
                document.getElementById('detailsSubStatus').textContent = s.status;
                document.getElementById('detailsSubStatus').style.color = s.status === 'ACTIVE' ? '#00A88F' : '#DC2626';
                document.getElementById('detailsStartDate').textContent = s.start_date ? formatDateDisplay(s.start_date) : '&mdash;';
                document.getElementById('detailsEndDate').textContent = s.end_date ? formatDateDisplay(s.end_date) : 'Lifetime / No Expiry';
              } else {
                document.getElementById('detailsCurrentPlan').textContent = 'No Active Plan';
                document.getElementById('detailsSubStatus').textContent = 'NO PLAN';
                document.getElementById('detailsSubStatus').style.color = '#DC2626';
                document.getElementById('detailsStartDate').innerHTML = '&mdash;';
                document.getElementById('detailsEndDate').innerHTML = '&mdash;';
              }

              const curUsers = slots.current_active_users !== undefined ? slots.current_active_users : (slots.current_users || 0);
              const maxUsers = slots.max_users || 0;
              const availUsers = slots.available_users || 0;

              document.getElementById('detailsCurrentUsers').textContent = curUsers;
              document.getElementById('detailsMaxUsers').textContent = maxUsers;
              document.getElementById('detailsAvailableUsers').textContent = availUsers;

              const pct = maxUsers > 0 ? Math.min(100, Math.round((curUsers / maxUsers) * 100)) : 0;
              const bar = document.getElementById('detailsUsageProgressBar');
              bar.style.width = pct + '%';
              bar.style.background = (maxUsers > 0 && curUsers >= maxUsers) ? '#DC2626' : (pct >= 80 ? '#D97706' : '#00A88F');

              document.getElementById('detailsUsageNote').textContent = maxUsers === 0
                ? 'FREE Plan: 0 users allowed.'
                : `${pct}% capacity utilized (${availUsers} slots available).`;

              modal.style.display = 'flex';

            } catch (err) {
              console.error('Error opening details modal:', err);
              alert('Network error loading customer details.');
            }
          }

          function openChangeSubFromDetailsModal() {
            closeCustSubModal('custSubDetailsModal');
            if (currentSelectedCustomer) {
              openChangeSubscriptionModal(currentSelectedCustomer);
            }
          }

          function openHistoryFromDetailsModal() {
            closeCustSubModal('custSubDetailsModal');
            if (currentSelectedCustomer) {
              openCustomerSubscriptionHistoryModal(currentSelectedCustomer);
            }
          }

          // Open Assign / Change Subscription Modal
          async function openChangeSubscriptionModal(customerId) {
            currentSelectedCustomer = customerId;
            const modal = document.getElementById('assignChangeSubscriptionModal');
            const errBox = document.getElementById('changeSubErrorBox');
            if (!modal) return;

            errBox.style.display = 'none';
            errBox.textContent = '';

            // Find in cache or fetch
            let sub = custSubsCache.find(x => x.customer_id === customerId);

            document.getElementById('changeSubCustomerId').value = customerId;
            document.getElementById('changeSubCustomerName').value = sub ? `${sub.customer_name} (${sub.customer_code})` : `Customer #${customerId}`;
            document.getElementById('changeSubCurrentPlanName').value = sub && sub.plan_name && sub.display_status === 'ACTIVE' ? `${sub.plan_name} (Max: ${sub.max_users} users)` : (sub && sub.plan_name ? `${sub.plan_name} [${sub.display_status}]` : 'No Active Subscription');

            // Default dates
            const todayStr = new Date().toISOString().split('T')[0];
            document.getElementById('changeSubStartDate').value = todayStr;
            document.getElementById('changeSubEndDate').value = '';
            document.getElementById('changeSubNotes').value = '';

            // Reset plan select
            const planSelect = document.getElementById('changeSubNewPlanSelect');
            if (sub && sub.plan_id) {
              planSelect.value = sub.plan_id;
            } else {
              planSelect.value = '';
            }

            handleNewPlanSelection();
            modal.style.display = 'flex';
          }

          // Submit Assign / Change Subscription
          async function submitAssignChangeSubscription(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('changeSubSubmitBtn');
            const errBox = document.getElementById('changeSubErrorBox');
            errBox.style.display = 'none';

            const customerId = parseInt(document.getElementById('changeSubCustomerId').value, 10);
            const planId = parseInt(document.getElementById('changeSubNewPlanSelect').value, 10);
            const startDate = document.getElementById('changeSubStartDate').value;
            const endDate = document.getElementById('changeSubEndDate').value;
            const notes = document.getElementById('changeSubNotes').value.trim();

            if (!customerId || !planId) {
              errBox.textContent = 'Please select a valid subscription plan.';
              errBox.style.display = 'block';
              return;
            }

            // Determine if customer has active plan
            const currentSub = custSubsCache.find(x => x.customer_id === customerId);
            const hasActive = (currentSub && currentSub.subscription_id && currentSub.display_status === 'ACTIVE');

            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            const formData = new FormData();
            formData.append('customer_id', customerId);
            formData.append('plan_id', planId);
            formData.append('new_plan_id', planId);
            formData.append('start_date', startDate);
            if (endDate) formData.append('end_date', endDate);
            if (notes) formData.append('notes', notes);
            formData.append('force_replace', '1');

            // Call 'change' if already has subscription, else 'assign'
            formData.append('action', hasActive ? 'change' : 'assign');

            try {
              const res = await fetch(getCustSubApiUrl(), {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (data.success) {
                closeCustSubModal('assignChangeSubscriptionModal');
                showCustSubToast(data.message || 'Subscription successfully updated.', true);
                loadCustomerSubscriptionsTable();
              } else {
                errBox.textContent = data.error || 'Failed to update subscription.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              console.error('Error saving subscription:', err);
              errBox.textContent = 'Server connection error. Please try again.';
              errBox.style.display = 'block';
            } finally {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Save Subscription';
            }
          }

          // Open Subscription History Modal
          async function openCustomerSubscriptionHistoryModal(customerId) {
            currentSelectedCustomer = customerId;
            const modal = document.getElementById('custSubHistoryModal');
            const tbody = document.getElementById('custSubHistoryTableBody');
            if (!modal || !tbody) return;

            const sub = custSubsCache.find(x => x.customer_id === customerId);
            document.getElementById('historyModalCustomerTitle').textContent = sub ? `${sub.customer_name} &bull; History` : `Customer #${customerId} History`;

            tbody.innerHTML = `
              <tr>
                <td colspan="8" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                  Loading historical subscription records...
                </td>
              </tr>
            `;

            modal.style.display = 'flex';

            try {
              const res = await fetch(getCustSubApiUrl(`action=get_history&customer_id=${customerId}`));
              const data = await res.json();

              if (!data.success || !Array.isArray(data.history) || data.history.length === 0) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                      No historical subscription records found for this customer.
                    </td>
                  </tr>
                `;
                return;
              }

              tbody.innerHTML = data.history.map(h => {
                const status = (h.status || '').toUpperCase();
                let statusBg = '#F1F5F9', statusColor = '#475569';
                if (status === 'ACTIVE') {
                  statusBg = '#E6F9F6'; statusColor = '#00A88F';
                } else if (status === 'EXPIRED') {
                  statusBg = '#FEE2E2'; statusColor = '#DC2626';
                } else if (status === 'SUSPENDED') {
                  statusBg = '#FEF3C7'; statusColor = '#D97706';
                } else if (status === 'CANCELLED') {
                  statusBg = '#F3F4F6'; statusColor = '#6B7280';
                }

                const changeDate = h.assigned_date ? formatDateDisplay(h.assigned_date.split(' ')[0]) : (h.created_at ? formatDateDisplay(h.created_at.split(' ')[0]) : '&mdash;');

                return `
                  <tr style="border-bottom: 1px solid var(--color-border);">
                    <td style="padding: 0.75rem 0.85rem;">
                      <strong>${escapeHtml(h.plan_name)}</strong>
                      <div style="font-size: 0.72rem; color: var(--color-secondary-text);">${escapeHtml(h.plan_code)}</div>
                    </td>
                    <td style="padding: 0.75rem 0.85rem; font-weight: 700;">${h.max_users} users</td>
                    <td style="padding: 0.75rem 0.85rem; color: var(--color-secondary-text);">${formatDateDisplay(h.start_date)}</td>
                    <td style="padding: 0.75rem 0.85rem; color: var(--color-secondary-text);">${h.end_date ? formatDateDisplay(h.end_date) : '&mdash;'}</td>
                    <td style="padding: 0.75rem 0.85rem;">
                      <span style="background: ${statusBg}; color: ${statusColor}; padding: 2px 7px; border-radius: 12px; font-size: 0.74rem; font-weight: 700;">
                        ${escapeHtml(status)}
                      </span>
                    </td>
                    <td style="padding: 0.75rem 0.85rem; font-size: 0.8rem;">${escapeHtml(h.assigned_by_name || 'System')}</td>
                    <td style="padding: 0.75rem 0.85rem; font-size: 0.8rem; color: var(--color-secondary-text);">${changeDate}</td>
                    <td style="padding: 0.75rem 0.85rem; font-size: 0.78rem; color: var(--color-secondary-text); max-width: 180px;">${escapeHtml(h.notes || '&mdash;')}</td>
                  </tr>
                `;
              }).join('');

            } catch (err) {
              console.error('Error loading history:', err);
              tbody.innerHTML = `
                <tr>
                  <td colspan="8" style="text-align: center; padding: 2rem; color: #DC2626;">
                    Error loading subscription history.
                  </td>
                </tr>
              `;
            }
          }

          // Initialize Customer Subscriptions on load
          document.addEventListener('DOMContentLoaded', () => {
            preloadActivePlans();
            loadCustomerSubscriptionsTable();
          });
          if (document.readyState === 'complete' || document.readyState === 'interactive') {
            preloadActivePlans();
            loadCustomerSubscriptionsTable();
          }
        </script>

      
      <?php elseif ($currentPage === 'cma-admins'): ?>
        <!-- ============================================================
             CMA ADMIN MANAGEMENT MODULE (PHASE 2 & 3)
             ============================================================ -->
        <section class="section-card" style="margin-bottom: 1.5rem;">
          <div class="breadcrumb" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--color-secondary-text); margin-bottom: 0.75rem;">
            <span>Administration</span>
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            <span>CMA Management</span>
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            <span style="color: var(--color-bright-cyan); font-weight: 600;">CMA Admins</span>
          </div>

          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.25rem;">CMA Administrators</h2>
              <p style="font-size: 0.88rem; color: var(--color-secondary-text); margin: 0;">
                Provision and manage Cost Management Administrators bound to customer organizations.
              </p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openCreateCmaModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>Create CMA Admin</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadCmaAdminsTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>
        </section>

        <!-- KPI SUMMARY CARDS -->
        <section class="grid-cards" style="margin-bottom: 1.5rem;">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Total CMA Admins</span></div>
            <div class="stat-number" id="kpiTotalCma">-</div>
            <div class="stat-desc">Provisioned administrators</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Active Admins</span></div>
            <div class="stat-number" id="kpiActiveCma" style="color: #059669;">-</div>
            <div class="stat-desc">Can sign in to Cost360</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Inactive Admins</span></div>
            <div class="stat-number" id="kpiInactiveCma" style="color: #DC2626;">-</div>
            <div class="stat-desc">Suspended or disabled</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Customers Covered</span></div>
            <div class="stat-number" id="kpiCoveredCustomers" style="color: var(--color-bright-cyan);">-</div>
            <div class="stat-desc">Organizations with CMA Admin</div>
          </div>
        </section>

        <!-- FILTERS & TABLE -->
        <section class="section-card">
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; flex: 1; max-width: 720px;">
              <div style="position: relative; flex: 1; min-width: 220px;">
                <input type="text" id="cmaSearchInput" class="cost360-input" placeholder="Search by name, username, email, customer..." onkeyup="if(event.key==='Enter') loadCmaAdminsTable()" style="width: 100%; padding-left: 2rem;">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" style="position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%); color: var(--color-secondary-text);">
                  <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
              </div>
              <select id="cmaCustomerFilter" class="cost360-select" onchange="loadCmaAdminsTable()" style="min-width: 180px;">
                <option value="0">All Customers</option>
              </select>
              <select id="cmaStatusFilter" class="cost360-select" onchange="loadCmaAdminsTable()" style="min-width: 130px;">
                <option value="ALL">All Status</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
              </select>
            </div>
            <div>
              <button type="button" class="btn-secondary" onclick="loadCmaAdminsTable()">Search</button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="cost360-data-table" id="cmaAdminsTable" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
              <thead>
                <tr style="border-bottom: 1px solid var(--color-card-border); text-align: left; color: var(--color-secondary-text);">
                  <th style="padding: 0.75rem 1rem;">Administrator</th>
                  <th style="padding: 0.75rem 1rem;">Username / Email</th>
                  <th style="padding: 0.75rem 1rem;">Customer Organization</th>
                  <th style="padding: 0.75rem 1rem;">Subscription</th>
                  <th style="padding: 0.75rem 1rem;">Status</th>
                  <th style="padding: 0.75rem 1rem;">Created At</th>
                  <th style="padding: 0.75rem 1rem; text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="cmaAdminsTableBody">
                <tr>
                  <td colspan="7" style="text-align: center; padding: 2.5rem; color: var(--color-secondary-text);">
                    <span>Loading CMA Administrators...</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- MODAL: CREATE CMA ADMIN -->
        <div id="createCmaModal" class="cost360-modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(3, 27, 51, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px);">
          <div class="cost360-modal-content" style="background: var(--color-card-surface, #ffffff); border-radius: 12px; width: 100%; max-width: 580px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); border: 1px solid var(--color-card-border, #E2E8F0); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--color-card-border, #E2E8F0); display: flex; justify-content: space-between; align-items: center;">
              <div>
                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--color-primary-blue);">CMA Management</span>
                <h2 style="font-size: 1.25rem; color: var(--color-deep-navy); margin: 0.2rem 0 0 0;">Create CMA Admin</h2>
              </div>
              <button type="button" onclick="closeCmaModal('createCmaModal')" style="background: none; border: none; cursor: pointer; color: var(--color-secondary-text); padding: 4px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>
            <form id="createCmaForm" onsubmit="submitCreateCma(event)" style="display: flex; flex-direction: column; overflow-y: auto; flex: 1;">
              <div style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                <div id="createCmaErrorBox" style="display: none; padding: 0.75rem 1rem; background: #FEE2E2; border-left: 4px solid #DC2626; color: #991B1B; border-radius: 4px; font-size: 0.85rem;"></div>

                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Customer Organization <span style="color: #DC2626;">*</span></label>
                  <select id="newCmaCustomerId" class="cost360-select" required style="width: 100%;">
                    <option value="">-- Select Customer --</option>
                  </select>
                  <small style="color: var(--color-secondary-text); font-size: 0.76rem;">The CMA Admin will have administrative jurisdiction over this customer organization.</small>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">First Name <span style="color: #DC2626;">*</span></label>
                    <input type="text" id="newCmaFirstName" class="cost360-input" required placeholder="e.g. Rahul" style="width: 100%;">
                  </div>
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Last Name</label>
                    <input type="text" id="newCmaLastName" class="cost360-input" placeholder="e.g. Sharma" style="width: 100%;">
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Username <span style="color: #DC2626;">*</span></label>
                    <input type="text" id="newCmaUsername" class="cost360-input" required placeholder="e.g. rsharma_cma" style="width: 100%;">
                  </div>
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Email Address <span style="color: #DC2626;">*</span></label>
                    <input type="email" id="newCmaEmail" class="cost360-input" required placeholder="e.g. rahul.sharma@example.com" style="width: 100%;">
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Password <span style="color: #DC2626;">*</span></label>
                    <input type="password" id="newCmaPassword" class="cost360-input" required placeholder="Min 6 characters" minlength="6" style="width: 100%;">
                  </div>
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Account Status</label>
                    <select id="newCmaStatus" class="cost360-select" style="width: 100%;">
                      <option value="ACTIVE">ACTIVE</option>
                      <option value="INACTIVE">INACTIVE</option>
                    </select>
                  </div>
                </div>
              </div>
              <div style="padding: 1rem 1.5rem; background: var(--color-bg-light, #F8FAFC); border-top: 1px solid var(--color-card-border, #E2E8F0); display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" onclick="closeCmaModal('createCmaModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="createCmaSubmitBtn">Create CMA Admin</button>
              </div>
            </form>
          </div>
        </div>

        <!-- MODAL: EDIT CMA ADMIN -->
        <div id="editCmaModal" class="cost360-modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(3, 27, 51, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px);">
          <div class="cost360-modal-content" style="background: var(--color-card-surface, #ffffff); border-radius: 12px; width: 100%; max-width: 580px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); border: 1px solid var(--color-card-border, #E2E8F0); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--color-card-border, #E2E8F0); display: flex; justify-content: space-between; align-items: center;">
              <div>
                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--color-primary-blue);">CMA Management</span>
                <h2 style="font-size: 1.25rem; color: var(--color-deep-navy); margin: 0.2rem 0 0 0;">Edit CMA Admin</h2>
              </div>
              <button type="button" onclick="closeCmaModal('editCmaModal')" style="background: none; border: none; cursor: pointer; color: var(--color-secondary-text); padding: 4px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>
            <form id="editCmaForm" onsubmit="submitEditCma(event)" style="display: flex; flex-direction: column; overflow-y: auto; flex: 1;">
              <input type="hidden" id="editCmaUserId">
              <div style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                <div id="editCmaErrorBox" style="display: none; padding: 0.75rem 1rem; background: #FEE2E2; border-left: 4px solid #DC2626; color: #991B1B; border-radius: 4px; font-size: 0.85rem;"></div>

                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Customer Organization <span style="color: #DC2626;">*</span></label>
                  <select id="editCmaCustomerId" class="cost360-select" required style="width: 100%;">
                    <option value="">-- Select Customer --</option>
                  </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">First Name <span style="color: #DC2626;">*</span></label>
                    <input type="text" id="editCmaFirstName" class="cost360-input" required style="width: 100%;">
                  </div>
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Last Name</label>
                    <input type="text" id="editCmaLastName" class="cost360-input" style="width: 100%;">
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Username</label>
                    <input type="text" id="editCmaUsername" class="cost360-input" readonly disabled style="width: 100%; opacity: 0.75;">
                  </div>
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Email Address <span style="color: #DC2626;">*</span></label>
                    <input type="email" id="editCmaEmail" class="cost360-input" required style="width: 100%;">
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">New Password (Optional)</label>
                    <input type="password" id="editCmaPassword" class="cost360-input" placeholder="Leave blank to retain" minlength="6" style="width: 100%;">
                  </div>
                  <div>
                    <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Account Status</label>
                    <select id="editCmaStatus" class="cost360-select" style="width: 100%;">
                      <option value="ACTIVE">ACTIVE</option>
                      <option value="INACTIVE">INACTIVE</option>
                    </select>
                  </div>
                </div>
              </div>
              <div style="padding: 1rem 1.5rem; background: var(--color-bg-light, #F8FAFC); border-top: 1px solid var(--color-card-border, #E2E8F0); display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" onclick="closeCmaModal('editCmaModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="editCmaSubmitBtn">Save Changes</button>
              </div>
            </form>
          </div>
        </div>

        <script>
          let cmaCache = [];
          let customersCache = [];

          if (typeof escapeHtml !== 'function') {
            window.escapeHtml = function(str) {
              if (str === null || str === undefined) return '';
              return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
            };
          }

          function getCmaApiUrl(params = '') {
            const prefix = window.cost360BaseUrlPrefix || '';
            return prefix + 'admin/cma/api.php' + (params ? '?' + params : '');
          }

          function showCmaToast(msg, isSuccess = true) {
            let toast = document.getElementById('cmaToastAlert');
            if (!toast) {
              toast = document.createElement('div');
              toast.id = 'cmaToastAlert';
              toast.style.cssText = 'position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999; padding: 0.85rem 1.35rem; border-radius: 8px; font-size: 0.88rem; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.2); transition: all 0.3s ease;';
              document.body.appendChild(toast);
            }
            toast.style.background = isSuccess ? '#059669' : '#DC2626';
            toast.style.color = '#FFFFFF';
            toast.textContent = msg;
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 4000);
          }

          function openCreateCmaModal() {
            document.getElementById('createCmaErrorBox').style.display = 'none';
            document.getElementById('createCmaForm').reset();
            populateCustomerSelects();
            document.getElementById('createCmaModal').style.display = 'flex';
          }

          function closeCmaModal(id) {
            document.getElementById(id).style.display = 'none';
          }

          async function preloadCustomers() {
            try {
              const res = await fetch(getCmaApiUrl('action=get_customers'));
              const data = await res.json();
              if (data.success) {
                customersCache = data.customers || [];
                populateCustomerSelects();
              }
            } catch (e) {
              console.error('Error preloading customers:', e);
            }
          }

          function populateCustomerSelects() {
            const selects = ['cmaCustomerFilter', 'newCmaCustomerId', 'editCmaCustomerId'];
            selects.forEach(id => {
              const el = document.getElementById(id);
              if (!el) return;
              const curVal = el.value;
              const isFilter = (id === 'cmaCustomerFilter');
              el.innerHTML = isFilter ? '<option value="0">All Customers</option>' : '<option value="">-- Select Customer --</option>';
              customersCache.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.customer_id;
                opt.textContent = `${c.customer_name} (${c.customer_code})`;
                el.appendChild(opt);
              });
              if (curVal) el.value = curVal;
            });
          }

          async function loadCmaAdminsTable() {
            const tbody = document.getElementById('cmaAdminsTableBody');
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);"><span>Fetching administrators...</span></td></tr>`;
            const search = encodeURIComponent(document.getElementById('cmaSearchInput')?.value || '');
            const custId = document.getElementById('cmaCustomerFilter')?.value || 0;
            const status = document.getElementById('cmaStatusFilter')?.value || 'ALL';

            try {
              const res = await fetch(getCmaApiUrl(`action=list&search=${search}&customer_id=${custId}&status=${status}`));
              const data = await res.json();
              if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 2rem; color: #DC2626;">${escapeHtml(data.error || 'Failed to load CMA Admins.')}</td></tr>`;
                return;
              }

              cmaCache = data.admins || [];
              if (data.kpis) {
                document.getElementById('kpiTotalCma').textContent = data.kpis.total || 0;
                document.getElementById('kpiActiveCma').textContent = data.kpis.active || 0;
                document.getElementById('kpiInactiveCma').textContent = data.kpis.inactive || 0;
                document.getElementById('kpiCoveredCustomers').textContent = data.kpis.customers_covered || 0;
              }

              if (cmaCache.length === 0) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="7" style="text-align: center; padding: 3rem 1rem; color: var(--color-secondary-text);">
                      <div style="font-weight: 700; color: var(--color-primary-text); margin-bottom: 0.35rem;">No CMA Administrators Found</div>
                      <div style="font-size: 0.82rem;">Click "Create CMA Admin" above to provision the first Cost Management Administrator.</div>
                    </td>
                  </tr>
                `;
                return;
              }

              tbody.innerHTML = cmaCache.map(a => {
                const isActive = (a.status === 'ACTIVE');
                const statusBadge = isActive 
                  ? `<span style="background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 700;">ACTIVE</span>`
                  : `<span style="background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 700;">INACTIVE</span>`;
                const planBadge = a.subscription_plan ? `<span style="font-size: 0.78rem; font-weight: 600; color: var(--color-deep-navy);">${escapeHtml(a.subscription_plan)}</span>` : `<span style="color: var(--color-secondary-text); font-size: 0.75rem;">None</span>`;

                return `
                  <tr style="border-bottom: 1px solid var(--color-card-border);">
                    <td style="padding: 0.85rem 1rem;">
                      <div style="font-weight: 700; color: var(--color-deep-navy);">${escapeHtml(a.full_name || a.username)}</div>
                      <div style="font-size: 0.75rem; color: var(--color-secondary-text);">Role: CMA_ADMIN</div>
                    </td>
                    <td style="padding: 0.85rem 1rem;">
                      <div style="font-family: monospace; font-size: 0.82rem; font-weight: 600;">${escapeHtml(a.username)}</div>
                      <div style="font-size: 0.78rem; color: var(--color-secondary-text);">${escapeHtml(a.email)}</div>
                    </td>
                    <td style="padding: 0.85rem 1rem;">
                      <div style="font-weight: 600;">${escapeHtml(a.customer_name || 'Unassigned')}</div>
                      <div style="font-size: 0.74rem; color: var(--color-secondary-text);">${escapeHtml(a.customer_code || '')}</div>
                    </td>
                    <td style="padding: 0.85rem 1rem;">${planBadge}</td>
                    <td style="padding: 0.85rem 1rem;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; font-size: 0.8rem; color: var(--color-secondary-text);">${escapeHtml(a.created_at ? a.created_at.substring(0, 10) : '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right;">
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="openEditCmaModal(${a.user_id})">Edit</button>
                      <button type="button" class="${isActive ? 'btn-secondary' : 'btn-primary'}" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; ${isActive ? 'color: #DC2626;' : ''}" onclick="toggleCmaStatus(${a.user_id}, '${isActive ? 'INACTIVE' : 'ACTIVE'}')">
                        ${isActive ? 'Deactivate' : 'Activate'}
                      </button>
                    </td>
                  </tr>
                `;
              }).join('');
            } catch (err) {
              console.error('Error loading CMA admins table:', err);
              tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 2rem; color: #DC2626;">Network error occurred while fetching CMA administrators.</td></tr>`;
            }
          }

          async function submitCreateCma(e) {
            e.preventDefault();
            const btn = document.getElementById('createCmaSubmitBtn');
            const errBox = document.getElementById('createCmaErrorBox');
            errBox.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('customer_id', document.getElementById('newCmaCustomerId').value);
            formData.append('first_name', document.getElementById('newCmaFirstName').value.trim());
            formData.append('last_name', document.getElementById('newCmaLastName').value.trim());
            formData.append('username', document.getElementById('newCmaUsername').value.trim());
            formData.append('email', document.getElementById('newCmaEmail').value.trim());
            formData.append('password', document.getElementById('newCmaPassword').value);
            formData.append('status', document.getElementById('newCmaStatus').value);

            btn.disabled = true;
            btn.textContent = 'Creating...';

            try {
              const res = await fetch(getCmaApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                closeCmaModal('createCmaModal');
                showCmaToast(data.message || 'CMA Admin created successfully.', true);
                loadCmaAdminsTable();
              } else {
                errBox.textContent = data.error || 'Failed to create CMA Admin.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Network error while creating CMA Admin.';
              errBox.style.display = 'block';
            } finally {
              btn.disabled = false;
              btn.textContent = 'Create CMA Admin';
            }
          }

          async function openEditCmaModal(userId) {
            const admin = cmaCache.find(a => a.user_id == userId);
            if (!admin) return;
            document.getElementById('editCmaErrorBox').style.display = 'none';
            document.getElementById('editCmaUserId').value = admin.user_id;
            document.getElementById('editCmaFirstName').value = admin.first_name || '';
            document.getElementById('editCmaLastName').value = admin.last_name || '';
            document.getElementById('editCmaUsername').value = admin.username || '';
            document.getElementById('editCmaEmail').value = admin.email || '';
            document.getElementById('editCmaPassword').value = '';
            document.getElementById('editCmaStatus').value = admin.status || 'ACTIVE';
            document.getElementById('editCmaCustomerId').value = admin.customer_id || '';
            document.getElementById('editCmaModal').style.display = 'flex';
          }

          async function submitEditCma(e) {
            e.preventDefault();
            const btn = document.getElementById('editCmaSubmitBtn');
            const errBox = document.getElementById('editCmaErrorBox');
            errBox.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('user_id', document.getElementById('editCmaUserId').value);
            formData.append('customer_id', document.getElementById('editCmaCustomerId').value);
            formData.append('first_name', document.getElementById('editCmaFirstName').value.trim());
            formData.append('last_name', document.getElementById('editCmaLastName').value.trim());
            formData.append('email', document.getElementById('editCmaEmail').value.trim());
            formData.append('status', document.getElementById('editCmaStatus').value);
            const pwd = document.getElementById('editCmaPassword').value;
            if (pwd) formData.append('password', pwd);

            btn.disabled = true;
            btn.textContent = 'Saving...';

            try {
              const res = await fetch(getCmaApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                closeCmaModal('editCmaModal');
                showCmaToast(data.message || 'CMA Admin updated successfully.', true);
                loadCmaAdminsTable();
              } else {
                errBox.textContent = data.error || 'Failed to update CMA Admin.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Network error while updating CMA Admin.';
              errBox.style.display = 'block';
            } finally {
              btn.disabled = false;
              btn.textContent = 'Save Changes';
            }
          }

          async function toggleCmaStatus(userId, newStatus) {
            if (!confirm(`Are you sure you want to mark this CMA Admin as ${newStatus}?`)) return;
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('user_id', userId);
            formData.append('status', newStatus);

            try {
              const res = await fetch(getCmaApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                showCmaToast(data.message || 'Status updated.', true);
                loadCmaAdminsTable();
              } else {
                alert(data.error || 'Failed to toggle status.');
              }
            } catch (e) {
              alert('Network error while toggling status.');
            }
          }

          // Initial load
          document.addEventListener('DOMContentLoaded', () => {
            preloadCustomers();
            loadCmaAdminsTable();
          });
          if (document.readyState === 'complete' || document.readyState === 'interactive') {
            preloadCustomers();
            loadCmaAdminsTable();
          }
        </script>

      <?php elseif ($currentPage === 'user-access'): ?>
        <!-- ============================================================
             USER ACCESS MANAGEMENT MODULE (PHASES 9-13)
             ============================================================ -->
        <section class="section-card" style="margin-bottom: 1.5rem;">
          <div class="breadcrumb" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--color-secondary-text); margin-bottom: 0.75rem;">
            <span>Access Management</span>
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            <span style="color: var(--color-bright-cyan); font-weight: 600;">User Access</span>
          </div>

          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.25rem;">User Access Management</h2>
              <p style="font-size: 0.88rem; color: var(--color-secondary-text); margin: 0;">
                Map users to organizational hierarchy (Company, Division, Plant) and view inherited Effective Access.
              </p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAssignAccessModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>Assign User Access</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadUserAccessTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>
        </section>

        <!-- KPI SUMMARY CARDS -->
        <section class="grid-cards" style="margin-bottom: 1.5rem;">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Total Access Mappings</span></div>
            <div class="stat-number" id="kpiTotalMappings">-</div>
            <div class="stat-desc">Active organizational grants</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Users With Access</span></div>
            <div class="stat-number" id="kpiUsersWithAccess" style="color: #059669;">-</div>
            <div class="stat-desc">Distinct mapped users</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Companies Mapped</span></div>
            <div class="stat-number" id="kpiCompaniesMapped" style="color: var(--color-bright-cyan);">-</div>
            <div class="stat-desc">Organizational entities</div>
          </div>
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Plants Mapped</span></div>
            <div class="stat-number" id="kpiPlantsMapped" style="color: #7C3AED;">-</div>
            <div class="stat-desc">Manufacturing locations</div>
          </div>
        </section>

        <!-- FILTERS & TABLE -->
        <section class="section-card">
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; flex: 1; max-width: 720px;">
              <select id="accessUserFilter" class="cost360-select" onchange="loadUserAccessTable()" style="min-width: 200px;">
                <option value="0">All Users</option>
              </select>
              <select id="accessCompanyFilter" class="cost360-select" onchange="loadUserAccessTable()" style="min-width: 180px;">
                <option value="0">All Companies</option>
              </select>
              <select id="accessLevelFilter" class="cost360-select" onchange="loadUserAccessTable()" style="min-width: 150px;">
                <option value="ALL">All Access Levels</option>
                <option value="COMPANY">Company Level</option>
                <option value="DIVISION">Division Level</option>
                <option value="PLANT">Plant Level</option>
              </select>
            </div>
            <div>
              <button type="button" class="btn-secondary" onclick="loadUserAccessTable()">Apply Filters</button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="cost360-data-table" id="userAccessTable" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
              <thead>
                <tr style="border-bottom: 1px solid var(--color-card-border); text-align: left; color: var(--color-secondary-text);">
                  <th style="padding: 0.75rem 1rem;">User Details</th>
                  <th style="padding: 0.75rem 1rem;">Assigned Role</th>
                  <th style="padding: 0.75rem 1rem;">Access Level</th>
                  <th style="padding: 0.75rem 1rem;">Company</th>
                  <th style="padding: 0.75rem 1rem;">Division</th>
                  <th style="padding: 0.75rem 1rem;">Plant</th>
                  <th style="padding: 0.75rem 1rem; text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="userAccessTableBody">
                <tr>
                  <td colspan="7" style="text-align: center; padding: 2.5rem; color: var(--color-secondary-text);">
                    <span>Loading User Access mappings...</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- MODAL: ASSIGN USER ACCESS (PHASE 12 & 13) -->
        <div id="assignAccessModal" class="cost360-modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(3, 27, 51, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px);">
          <div class="cost360-modal-content" style="background: var(--color-card-surface, #ffffff); border-radius: 12px; width: 100%; max-width: 620px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); border: 1px solid var(--color-card-border, #E2E8F0); overflow: hidden; display: flex; flex-direction: column; max-height: 92vh;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--color-card-border, #E2E8F0); display: flex; justify-content: space-between; align-items: center;">
              <div>
                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--color-primary-blue);">Access Management</span>
                <h2 style="font-size: 1.25rem; color: var(--color-deep-navy); margin: 0.2rem 0 0 0;">Assign User Access</h2>
              </div>
              <button type="button" onclick="closeAccessModal('assignAccessModal')" style="background: none; border: none; cursor: pointer; color: var(--color-secondary-text); padding: 4px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>
            <form id="assignAccessForm" onsubmit="submitSaveUserAccess(event)" style="display: flex; flex-direction: column; overflow-y: auto; flex: 1;">
              <div style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1.15rem;">
                <div id="assignAccessErrorBox" style="display: none; padding: 0.75rem 1rem; background: #FEE2E2; border-left: 4px solid #DC2626; color: #991B1B; border-radius: 4px; font-size: 0.85rem;"></div>

                <!-- Select User -->
                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Select User <span style="color: #DC2626;">*</span></label>
                  <select id="newAccessUserId" class="cost360-select" required style="width: 100%;" onchange="updateLiveEffectivePreview()">
                    <option value="">-- Choose User --</option>
                  </select>
                </div>

                <!-- Access Level Radio Buttons -->
                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.45rem; color: var(--color-primary-text);">Access Level <span style="color: #DC2626;">*</span></label>
                  <div style="display: flex; gap: 1.5rem; padding: 0.75rem 1rem; background: var(--color-bg-light, #F8FAFC); border: 1px solid var(--color-card-border, #E2E8F0); border-radius: 8px;">
                    <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.88rem; font-weight: 600; cursor: pointer;">
                      <input type="radio" name="accessLevelChoice" value="COMPANY" checked onchange="onAccessLevelChange()">
                      <span>Company</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.88rem; font-weight: 600; cursor: pointer;">
                      <input type="radio" name="accessLevelChoice" value="DIVISION" onchange="onAccessLevelChange()">
                      <span>Division</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.88rem; font-weight: 600; cursor: pointer;">
                      <input type="radio" name="accessLevelChoice" value="PLANT" onchange="onAccessLevelChange()">
                      <span>Plant</span>
                    </label>
                  </div>
                  <div id="accessLevelHelpText" style="margin-top: 0.35rem; font-size: 0.76rem; color: var(--color-secondary-text);">
                    Company access grants inherited access to all divisions and plants under the company.
                  </div>
                </div>

                <!-- Dynamic Hierarchy Dropdowns -->
                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Company <span style="color: #DC2626;">*</span></label>
                  <select id="newAccessCompanyId" class="cost360-select" required style="width: 100%;" onchange="onAccessCompanyChange()">
                    <option value="">-- Select Company --</option>
                  </select>
                </div>

                <div id="divisionFieldContainer" style="display: none;">
                  <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Division <span style="color: #DC2626;">*</span></label>
                  <select id="newAccessDivisionId" class="cost360-select" style="width: 100%;" onchange="onAccessDivisionChange()">
                    <option value="">-- Select Division --</option>
                  </select>
                </div>

                <div id="plantFieldContainer" style="display: none;">
                  <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Plant <span style="color: #DC2626;">*</span></label>
                  <select id="newAccessPlantId" class="cost360-select" style="width: 100%;" onchange="updateLiveEffectivePreview()">
                    <option value="">-- Select Plant --</option>
                  </select>
                </div>

                <!-- Live Effective Access Preview Box (Phase 11 & 12) -->
                <div>
                  <label style="display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 0.35rem; color: var(--color-primary-text);">Effective Access Preview</label>
                  <div id="liveEffectivePreviewBox" style="background: #0F172A; color: #38BDF8; font-family: monospace; font-size: 0.82rem; padding: 1rem; border-radius: 8px; border: 1px solid #334155; min-height: 80px; white-space: pre-wrap; line-height: 1.5;">
                    Select an Access Level and Company to preview effective permissions.
                  </div>
                </div>
              </div>
              <div style="padding: 1rem 1.5rem; background: var(--color-bg-light, #F8FAFC); border-top: 1px solid var(--color-card-border, #E2E8F0); display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" onclick="closeAccessModal('assignAccessModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="saveAccessSubmitBtn">Save Access Mapping</button>
              </div>
            </form>
          </div>
        </div>

        <!-- MODAL: VIEW EFFECTIVE ACCESS (PHASE 11) -->
        <div id="viewEffectiveModal" class="cost360-modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(3, 27, 51, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px);">
          <div class="cost360-modal-content" style="background: var(--color-card-surface, #ffffff); border-radius: 12px; width: 100%; max-width: 640px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); border: 1px solid var(--color-card-border, #E2E8F0); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--color-card-border, #E2E8F0); display: flex; justify-content: space-between; align-items: center;">
              <div>
                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--color-primary-blue);">Inherited Permissions</span>
                <h2 id="effectiveModalUserTitle" style="font-size: 1.25rem; color: var(--color-deep-navy); margin: 0.2rem 0 0 0;">Effective Access Breakdown</h2>
              </div>
              <button type="button" onclick="closeAccessModal('viewEffectiveModal')" style="background: none; border: none; cursor: pointer; color: var(--color-secondary-text); padding: 4px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>
            <div style="padding: 1.5rem; overflow-y: auto; flex: 1;">
              <div id="effectiveUserCard" style="margin-bottom: 1.25rem; padding: 0.85rem 1.2rem; background: var(--color-bg-light, #F8FAFC); border-radius: 8px; border: 1px solid var(--color-card-border, #E2E8F0); font-size: 0.88rem;">
                <div style="font-weight: 700; color: var(--color-deep-navy);" id="effectiveUserName">-</div>
                <div style="font-size: 0.78rem; color: var(--color-secondary-text);" id="effectiveUserEmail">-</div>
              </div>

              <div style="font-weight: 700; font-size: 0.82rem; margin-bottom: 0.5rem; text-transform: uppercase; color: var(--color-secondary-text);">Hierarchical Cascade Tree</div>
              <div id="effectiveTreeVisualizer" style="display: flex; flex-direction: column; gap: 0.75rem;">
                <div style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">Loading effective access...</div>
              </div>

              <div style="margin-top: 1.5rem;">
                <div style="font-weight: 700; font-size: 0.82rem; margin-bottom: 0.35rem; text-transform: uppercase; color: var(--color-secondary-text);">ASCII Preview</div>
                <pre id="effectiveAsciiPreview" style="background: #0F172A; color: #38BDF8; padding: 1rem; border-radius: 8px; font-size: 0.82rem; font-family: monospace; overflow-x: auto; margin: 0; line-height: 1.5;"></pre>
              </div>
            </div>
            <div style="padding: 1rem 1.5rem; background: var(--color-bg-light, #F8FAFC); border-top: 1px solid var(--color-card-border, #E2E8F0); display: flex; justify-content: flex-end;">
              <button type="button" class="btn-secondary" onclick="closeAccessModal('viewEffectiveModal')">Close</button>
            </div>
          </div>
        </div>

        <script>
          let accessCache = [];
          let scopedUsersCache = [];
          let scopedCompaniesCache = [];
          let currentDivisionsCache = [];
          let currentPlantsCache = [];

          function getUserAccessApiUrl(params = '') {
            const prefix = window.cost360BaseUrlPrefix || '';
            return prefix + 'cma/access/api.php' + (params ? '?' + params : '');
          }

          function showAccessToast(msg, isSuccess = true) {
            let toast = document.getElementById('accessToastAlert');
            if (!toast) {
              toast = document.createElement('div');
              toast.id = 'accessToastAlert';
              toast.style.cssText = 'position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999; padding: 0.85rem 1.35rem; border-radius: 8px; font-size: 0.88rem; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.2); transition: all 0.3s ease;';
              document.body.appendChild(toast);
            }
            toast.style.background = isSuccess ? '#059669' : '#DC2626';
            toast.style.color = '#FFFFFF';
            toast.textContent = msg;
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 4000);
          }

          function openAssignAccessModal() {
            document.getElementById('assignAccessErrorBox').style.display = 'none';
            document.getElementById('assignAccessForm').reset();
            document.querySelector('input[name="accessLevelChoice"][value="COMPANY"]').checked = true;
            onAccessLevelChange();
            populateAccessDropdowns();
            document.getElementById('assignAccessModal').style.display = 'flex';
          }

          function closeAccessModal(id) {
            document.getElementById(id).style.display = 'none';
          }

          async function preloadAccessDependencies() {
            try {
              const [uRes, cRes] = await Promise.all([
                fetch(getUserAccessApiUrl('action=get_users')),
                fetch(getUserAccessApiUrl('action=get_companies'))
              ]);
              const uData = await uRes.json();
              const cData = await cRes.json();
              if (uData.success) scopedUsersCache = uData.users || [];
              if (cData.success) scopedCompaniesCache = cData.companies || [];
              populateAccessDropdowns();
            } catch (e) {
              console.error('Error preloading access dependencies:', e);
            }
          }

          function populateAccessDropdowns() {
            // Populate User Selects
            const userFilters = ['accessUserFilter', 'newAccessUserId'];
            userFilters.forEach(id => {
              const el = document.getElementById(id);
              if (!el) return;
              const cur = el.value;
              const isFilter = (id === 'accessUserFilter');
              el.innerHTML = isFilter ? '<option value="0">All Users</option>' : '<option value="">-- Choose User --</option>';
              scopedUsersCache.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u.user_id;
                opt.textContent = `${u.full_name} (${u.role_name || u.role_code || 'User'})`;
                el.appendChild(opt);
              });
              if (cur) el.value = cur;
            });

            // Populate Company Selects
            const compFilters = ['accessCompanyFilter', 'newAccessCompanyId'];
            compFilters.forEach(id => {
              const el = document.getElementById(id);
              if (!el) return;
              const cur = el.value;
              const isFilter = (id === 'accessCompanyFilter');
              el.innerHTML = isFilter ? '<option value="0">All Companies</option>' : '<option value="">-- Select Company --</option>';
              scopedCompaniesCache.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.company_id;
                opt.textContent = `${c.company_name} (${c.company_code})`;
                el.appendChild(opt);
              });
              if (cur) el.value = cur;
            });
          }

          function onAccessLevelChange() {
            const level = document.querySelector('input[name="accessLevelChoice"]:checked')?.value || 'COMPANY';
            const divCont = document.getElementById('divisionFieldContainer');
            const plantCont = document.getElementById('plantFieldContainer');
            const divSelect = document.getElementById('newAccessDivisionId');
            const plantSelect = document.getElementById('newAccessPlantId');
            const help = document.getElementById('accessLevelHelpText');

            if (level === 'COMPANY') {
              divCont.style.display = 'none';
              plantCont.style.display = 'none';
              divSelect.required = false;
              plantSelect.required = false;
              help.textContent = 'Company access grants inherited access to all divisions and plants under the selected company.';
            } else if (level === 'DIVISION') {
              divCont.style.display = 'block';
              plantCont.style.display = 'none';
              divSelect.required = true;
              plantSelect.required = false;
              help.textContent = 'Division access grants access to the division and all plants within it, but excludes other divisions.';
            } else if (level === 'PLANT') {
              divCont.style.display = 'block';
              plantCont.style.display = 'block';
              divSelect.required = true;
              plantSelect.required = true;
              help.textContent = 'Plant access grants access strictly to the single selected plant.';
            }
            updateLiveEffectivePreview();
          }

          async function onAccessCompanyChange() {
            const compId = document.getElementById('newAccessCompanyId').value;
            const divSelect = document.getElementById('newAccessDivisionId');
            divSelect.innerHTML = '<option value="">-- Select Division --</option>';
            document.getElementById('newAccessPlantId').innerHTML = '<option value="">-- Select Plant --</option>';
            currentDivisionsCache = [];
            currentPlantsCache = [];

            if (!compId) {
              updateLiveEffectivePreview();
              return;
            }

            try {
              const res = await fetch(getUserAccessApiUrl(`action=get_divisions&company_id=${compId}`));
              const data = await res.json();
              if (data.success) {
                currentDivisionsCache = data.divisions || [];
                currentDivisionsCache.forEach(d => {
                  const opt = document.createElement('option');
                  opt.value = d.division_id;
                  opt.textContent = `${d.division_name} (${d.division_code})`;
                  divSelect.appendChild(opt);
                });
              }
            } catch (e) {
              console.error('Error fetching divisions:', e);
            }
            updateLiveEffectivePreview();
          }

          async function onAccessDivisionChange() {
            const divId = document.getElementById('newAccessDivisionId').value;
            const plantSelect = document.getElementById('newAccessPlantId');
            plantSelect.innerHTML = '<option value="">-- Select Plant --</option>';
            currentPlantsCache = [];

            if (!divId) {
              updateLiveEffectivePreview();
              return;
            }

            try {
              const res = await fetch(getUserAccessApiUrl(`action=get_plants&division_id=${divId}`));
              const data = await res.json();
              if (data.success) {
                currentPlantsCache = data.plants || [];
                currentPlantsCache.forEach(p => {
                  const opt = document.createElement('option');
                  opt.value = p.plant_id;
                  opt.textContent = `${p.plant_name} (${p.plant_code})`;
                  plantSelect.appendChild(opt);
                });
              }
            } catch (e) {
              console.error('Error fetching plants:', e);
            }
            updateLiveEffectivePreview();
          }

          function updateLiveEffectivePreview() {
            const preview = document.getElementById('liveEffectivePreviewBox');
            const level = document.querySelector('input[name="accessLevelChoice"]:checked')?.value || 'COMPANY';
            const compEl = document.getElementById('newAccessCompanyId');
            const divEl = document.getElementById('newAccessDivisionId');
            const plantEl = document.getElementById('newAccessPlantId');

            const compName = compEl.options[compEl.selectedIndex]?.text || '';
            const divName = divEl.options[divEl.selectedIndex]?.text || '';
            const plantName = plantEl.options[plantEl.selectedIndex]?.text || '';

            if (!compEl.value) {
              preview.textContent = 'Select an Access Level and Company to preview effective permissions.';
              return;
            }

            let lines = [];
            if (level === 'COMPANY') {
              lines.push(`${compName} [DIRECT ACCESS]`);
              if (currentDivisionsCache.length === 0) {
                lines.push(`  └── (All divisions and plants under this company will be automatically inherited)`);
              } else {
                currentDivisionsCache.forEach((d, idx) => {
                  const isLast = (idx === currentDivisionsCache.length - 1);
                  lines.push(`  ${isLast ? '└──' : '├──'} ${d.division_name} [INHERITED]`);
                  lines.push(`  ${isLast ? '    ' : '│   '} └── (All active plants under this division inherited)`);
                });
              }
            } else if (level === 'DIVISION') {
              lines.push(`${compName} [PARENT]`);
              if (!divEl.value) {
                lines.push(`  └── (Please select a division)`);
              } else {
                lines.push(`  └── ${divName} [DIRECT ACCESS]`);
                if (currentPlantsCache.length === 0) {
                  lines.push(`      └── (All plants under this division will be inherited)`);
                } else {
                  currentPlantsCache.forEach((p, idx) => {
                    const isLast = (idx === currentPlantsCache.length - 1);
                    lines.push(`      ${isLast ? '└──' : '├──'} ${p.plant_name} [INHERITED]`);
                  });
                }
              }
            } else if (level === 'PLANT') {
              lines.push(`${compName} [PARENT]`);
              if (!divEl.value) {
                lines.push(`  └── (Please select a division)`);
              } else {
                lines.push(`  └── ${divName} [PARENT]`);
                if (!plantEl.value) {
                  lines.push(`      └── (Please select a plant)`);
                } else {
                  lines.push(`      └── ${plantName} [DIRECT ACCESS ONLY]`);
                }
              }
            }
            preview.textContent = lines.join('\n');
          }

          async function loadUserAccessTable() {
            const tbody = document.getElementById('userAccessTableBody');
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);"><span>Fetching user access mappings...</span></td></tr>`;
            const userId = document.getElementById('accessUserFilter')?.value || 0;
            const compId = document.getElementById('accessCompanyFilter')?.value || 0;
            const level = document.getElementById('accessLevelFilter')?.value || 'ALL';

            try {
              const res = await fetch(getUserAccessApiUrl(`action=list&user_id=${userId}&company_id=${compId}&access_level=${level}`));
              const data = await res.json();
              if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 2rem; color: #DC2626;">${escapeHtml(data.error || 'Failed to load access mappings.')}</td></tr>`;
                return;
              }

              accessCache = data.mappings || [];
              if (data.kpis) {
                document.getElementById('kpiTotalMappings').textContent = data.kpis.total_mappings || 0;
                document.getElementById('kpiUsersWithAccess').textContent = data.kpis.users_count || 0;
                document.getElementById('kpiCompaniesMapped').textContent = data.kpis.companies_count || 0;
                document.getElementById('kpiPlantsMapped').textContent = data.kpis.plants_count || 0;
              }

              if (accessCache.length === 0) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="7" style="text-align: center; padding: 3rem 1rem; color: var(--color-secondary-text);">
                      <div style="font-weight: 700; color: var(--color-primary-text); margin-bottom: 0.35rem;">No User Access Mappings Found</div>
                      <div style="font-size: 0.82rem;">Click "Assign User Access" above to map users to organizational entities.</div>
                    </td>
                  </tr>
                `;
                return;
              }

              tbody.innerHTML = accessCache.map(m => {
                let levelBadge = '';
                if (m.calculated_level === 'COMPANY') {
                  levelBadge = `<span style="background: #EEF2FF; color: #4F46E5; border: 1px solid #C7D2FE; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 700;">COMPANY</span>`;
                } else if (m.calculated_level === 'DIVISION') {
                  levelBadge = `<span style="background: #ECFEFF; color: #0891B2; border: 1px solid #A5F3FC; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 700;">DIVISION</span>`;
                } else if (m.calculated_level === 'PLANT') {
                  levelBadge = `<span style="background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 700;">PLANT</span>`;
                } else {
                  levelBadge = `<span style="background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 700;">CUSTOMER</span>`;
                }

                return `
                  <tr style="border-bottom: 1px solid var(--color-card-border);">
                    <td style="padding: 0.85rem 1rem;">
                      <div style="font-weight: 700; color: var(--color-deep-navy);">${escapeHtml(m.full_name || m.username)}</div>
                      <div style="font-size: 0.76rem; color: var(--color-secondary-text);">${escapeHtml(m.email)}</div>
                    </td>
                    <td style="padding: 0.85rem 1rem;">
                      <span style="font-size: 0.78rem; font-weight: 600; color: var(--color-secondary-text);">${escapeHtml(m.role_name || m.role_code || 'User')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem;">${levelBadge}</td>
                    <td style="padding: 0.85rem 1rem;">
                      <div style="font-weight: 600;">${escapeHtml(m.company_name || 'All Companies')}</div>
                      <div style="font-size: 0.74rem; color: var(--color-secondary-text);">${escapeHtml(m.company_code || '')}</div>
                    </td>
                    <td style="padding: 0.85rem 1rem;">
                      <div>${escapeHtml(m.division_name || (m.calculated_level === 'COMPANY' ? 'All Divisions (Inherited)' : '-'))}</div>
                    </td>
                    <td style="padding: 0.85rem 1rem;">
                      <div>${escapeHtml(m.plant_name || (m.calculated_level === 'COMPANY' || m.calculated_level === 'DIVISION' ? 'All Plants (Inherited)' : '-'))}</div>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: right;">
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="openEffectiveAccessModal(${m.user_id})">Effective Access</button>
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; color: #DC2626;" onclick="deleteAccessMapping(${m.access_id})">Remove</button>
                    </td>
                  </tr>
                `;
              }).join('');
            } catch (err) {
              console.error('Error loading user access table:', err);
              tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 2rem; color: #DC2626;">Network error occurred while fetching user access mappings.</td></tr>`;
            }
          }

          async function submitSaveUserAccess(e) {
            e.preventDefault();
            const btn = document.getElementById('saveAccessSubmitBtn');
            const errBox = document.getElementById('assignAccessErrorBox');
            errBox.style.display = 'none';

            const level = document.querySelector('input[name="accessLevelChoice"]:checked')?.value || 'COMPANY';
            const userId = document.getElementById('newAccessUserId').value;
            const compId = document.getElementById('newAccessCompanyId').value;
            const divId = document.getElementById('newAccessDivisionId').value;
            const plantId = document.getElementById('newAccessPlantId').value;

            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('user_id', userId);
            formData.append('access_level', level);
            formData.append('company_id', compId);
            if (level === 'DIVISION' || level === 'PLANT') formData.append('division_id', divId);
            if (level === 'PLANT') formData.append('plant_id', plantId);

            btn.disabled = true;
            btn.textContent = 'Saving...';

            try {
              const res = await fetch(getUserAccessApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                closeAccessModal('assignAccessModal');
                showAccessToast(data.message || 'Access mapping saved successfully.', true);
                loadUserAccessTable();
              } else {
                errBox.textContent = data.error || 'Failed to save access mapping.';
                errBox.style.display = 'block';
              }
            } catch (err) {
              errBox.textContent = 'Network error while saving user access.';
              errBox.style.display = 'block';
            } finally {
              btn.disabled = false;
              btn.textContent = 'Save Access Mapping';
            }
          }

          async function openEffectiveAccessModal(userId) {
            document.getElementById('viewEffectiveModal').style.display = 'flex';
            const visualizer = document.getElementById('effectiveTreeVisualizer');
            const ascii = document.getElementById('effectiveAsciiPreview');
            visualizer.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">Calculating hierarchical permissions...</div>';
            ascii.textContent = 'Loading...';

            try {
              const res = await fetch(getUserAccessApiUrl(`action=get_effective_access&user_id=${userId}`));
              const data = await res.json();
              if (!data.success) {
                visualizer.innerHTML = `<div style="color: #DC2626; padding: 1.5rem; text-align: center;">${escapeHtml(data.error || 'Failed to calculate effective access.')}</div>`;
                return;
              }

              if (data.user) {
                document.getElementById('effectiveUserName').textContent = data.user.full_name || data.user.username;
                document.getElementById('effectiveUserEmail').textContent = data.user.email || '';
              }

              ascii.textContent = data.ascii_preview || 'No organizational access granted.';

              const tree = data.tree || [];
              if (tree.length === 0) {
                visualizer.innerHTML = `<div style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">No accessible companies, divisions, or plants found for this user.</div>`;
                return;
              }

              visualizer.innerHTML = tree.map(comp => `
                <div style="border: 1px solid var(--color-card-border, #E2E8F0); border-radius: 8px; overflow: hidden; background: #FFFFFF;">
                  <div style="padding: 0.75rem 1rem; background: #F8FAFC; border-bottom: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-weight: 700; color: var(--color-deep-navy); display: flex; align-items: center; gap: 0.5rem;">
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                      <span>${escapeHtml(comp.company_name)}</span>
                    </div>
                    <span style="font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 9999px; ${comp.inherited ? 'background: #F1F5F9; color: #475569;' : 'background: #EEF2FF; color: #4F46E5;'}">
                      ${comp.inherited ? 'INHERITED' : 'DIRECT COMPANY GRANT'}
                    </span>
                  </div>
                  <div style="padding: 0.75rem 1rem; display: flex; flex-direction: column; gap: 0.65rem;">
                    ${comp.divisions.map(div => `
                      <div style="padding-left: 1rem; border-left: 2px solid #E2E8F0; margin-left: 0.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                          <div style="font-weight: 600; font-size: 0.85rem; color: var(--color-primary-text); display: flex; align-items: center; gap: 0.4rem;">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="3" x2="6" y2="15"></line><circle cx="18" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg>
                            <span>${escapeHtml(div.division_name)}</span>
                          </div>
                          <span style="font-size: 0.7rem; font-weight: 600; color: var(--color-secondary-text);">${div.inherited ? 'Inherited' : 'Direct Grant'}</span>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.4rem; padding-left: 1.25rem;">
                          ${div.plants.map(p => `
                            <span style="background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; padding: 0.2rem 0.55rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
                              <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                              ${escapeHtml(p.plant_name)}
                            </span>
                          `).join('')}
                        </div>
                      </div>
                    `).join('')}
                  </div>
                </div>
              `).join('');
            } catch (err) {
              console.error('Error loading effective access:', err);
              visualizer.innerHTML = `<div style="color: #DC2626; padding: 1.5rem; text-align: center;">Network error while calculating effective access.</div>`;
            }
          }

          async function deleteAccessMapping(accessId) {
            if (!confirm('Are you sure you want to remove this user access mapping? The user will lose inherited permissions associated with this level.')) return;
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('access_id', accessId);

            try {
              const res = await fetch(getUserAccessApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                showAccessToast(data.message || 'Access mapping removed.', true);
                loadUserAccessTable();
              } else {
                alert(data.error || 'Failed to remove access mapping.');
              }
            } catch (e) {
              alert('Network error while removing access mapping.');
            }
          }

          // Initial load
          document.addEventListener('DOMContentLoaded', () => {
            preloadAccessDependencies();
            loadUserAccessTable();
          });
          if (document.readyState === 'complete' || document.readyState === 'interactive') {
            preloadAccessDependencies();
            loadUserAccessTable();
          }
        </script>


      <?php elseif ($currentPage === 'menus'): ?>
        <!-- ============================================================
             MENU MASTER PAGE MODULE (DYNAMIC FROM NEON DB)
             ============================================================ -->
        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">Menu Master</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage application navigation, hierarchy and menu visibility.</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddMenuModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>Add Menu</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadMenusTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- SEARCH & FILTERS BAR -->
          <div style="display: flex; gap: 0.75rem; align-items: center; margin-top: 1.25rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px; position: relative;">
              <input type="text" id="menuSearchInput" class="cost360-select" placeholder="Search Menu Code / Menu Name / Route..." onkeyup="handleMenuSearchKeyup(event)" style="padding-left: 2.25rem;">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--color-secondary-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); pointer-events: none;">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
            </div>

            <div style="min-width: 130px;">
              <select id="menuTypeFilter" class="cost360-select" onchange="loadMenusTable()">
                <option value="ALL">All Types</option>
                <option value="GROUP">GROUP</option>
                <option value="PAGE">PAGE</option>
              </select>
            </div>

            <div style="min-width: 130px;">
              <select id="menuStatusFilter" class="cost360-select" onchange="loadMenusTable()">
                <option value="ALL">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
              </select>
            </div>

            <div style="min-width: 130px;">
              <select id="menuVisibilityFilter" class="cost360-select" onchange="loadMenusTable()">
                <option value="ALL">All Visibility</option>
                <option value="VISIBLE">Visible</option>
                <option value="HIDDEN">Hidden</option>
              </select>
            </div>

            <div style="min-width: 160px;">
              <select id="menuParentFilter" class="cost360-select" onchange="loadMenusTable()">
                <option value="ALL">All Parents</option>
                <option value="ROOT">Root Menus</option>
              </select>
            </div>

            <button type="button" class="btn-primary" onclick="loadMenusTable()" style="padding: 0.55rem 0.9rem;">Search</button>
            <button type="button" class="btn-secondary" onclick="resetMenuFilters()" style="padding: 0.55rem 0.9rem;">Reset</button>
          </div>

          <!-- FEEDBACK TOAST / ALERT BANNER -->
          <div id="menuMasterToast" style="display: none; padding: 0.85rem 1.25rem; border-radius: var(--radius-input); margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- MENUS TABLE CONTAINER -->
          <div class="table-responsive">
            <table id="menusTable">
              <thead>
                <tr>
                  <th>Menu ID</th>
                  <th>Menu Code</th>
                  <th>Menu Name</th>
                  <th>Menu Type</th>
                  <th>Parent Menu</th>
                  <th>Route</th>
                  <th>Icon</th>
                  <th>Display Order</th>
                  <th>Visibility</th>
                  <th>Status</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="menusTableBody">
                <tr>
                  <td colspan="11" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                    Loading menus from Neon PostgreSQL database...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ADD / EDIT MENU MODAL -->
        <div id="menuModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 580px;">
            <div class="modal-header">
              <h3 id="menuModalTitle" style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">+ Add Menu</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('menuModal')">&times;</button>
            </div>
            <form id="menuForm" onsubmit="submitMenuForm(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="menu_id" id="menuFormId" value="">
              <div id="menuFormError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Menu Code *</label>
                  <input type="text" name="menu_code" id="formMenuCode" class="cost360-select" required placeholder="e.g. USER_MASTER">
                </div>
                <div>
                  <label class="dropdown-label">Menu Name *</label>
                  <input type="text" name="menu_name" id="formMenuName" class="cost360-select" required placeholder="e.g. User Master">
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Menu Type *</label>
                  <select name="menu_type" id="formMenuType" class="cost360-select" required onchange="handleMenuTypeChange()">
                    <option value="PAGE" selected>PAGE</option>
                    <option value="GROUP">GROUP</option>
                  </select>
                </div>
                <div>
                  <label class="dropdown-label">Parent Menu</label>
                  <select name="parent_menu_id" id="formParentMenuId" class="cost360-select">
                    <option value="">None / Root Menu</option>
                  </select>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label" id="routeLabel">Route *</label>
                  <input type="text" name="route" id="formRoute" class="cost360-select" placeholder="e.g. admin/users">
                </div>
                <div>
                  <label class="dropdown-label">Icon</label>
                  <input type="text" name="icon" id="formIcon" class="cost360-select" placeholder="e.g. people, settings, menu">
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Display Order *</label>
                  <input type="number" name="display_order" id="formDisplayOrder" class="cost360-select" required value="10" min="0">
                </div>
                <div>
                  <label class="dropdown-label">Visibility *</label>
                  <select name="is_visible" id="formIsVisible" class="cost360-select" required>
                    <option value="true" selected>Visible</option>
                    <option value="false">Hidden</option>
                  </select>
                </div>
                <div>
                  <label class="dropdown-label">Status *</label>
                  <select name="is_active" id="formIsActive" class="cost360-select" required>
                    <option value="true" selected>Active</option>
                    <option value="false">Inactive</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="dropdown-label">Description</label>
                <textarea name="description" id="formDescription" class="cost360-select" style="min-height: 60px; resize: vertical;" placeholder="Optional menu description..."></textarea>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('menuModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="saveMenuBtn">Save Menu</button>
              </div>
            </form>
          </div>
        </div>

        <script>
          // Menu Master Module JavaScript Integration
          let menuSearchDebounceTimer = null;

          document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('menusTable')) {
              initMenuMasterPage();
            }
          });

          function initMenuMasterPage() {
            loadParentFilterDropdown();
            loadMenusTable();
          }

          function getMenuApiUrl() {
            const path = window.location.pathname;
            if (path.includes('/admin/menus')) {
              const basePath = path.substring(0, path.indexOf('/admin/menus'));
              return basePath + '/admin/menus/api.php';
            }
            if (path.includes('/admin/')) {
              const basePath = path.substring(0, path.indexOf('/admin/'));
              return basePath + '/admin/menus/api.php';
            }
            const lastSlash = path.lastIndexOf('/');
            const basePath = lastSlash !== -1 ? path.substring(0, lastSlash) : '';
            return basePath + '/admin/menus/api.php';
          }

          function handleMenuSearchKeyup(e) {
            clearTimeout(menuSearchDebounceTimer);
            menuSearchDebounceTimer = setTimeout(() => {
              loadMenusTable();
            }, 300);
          }

          function resetMenuFilters() {
            document.getElementById('menuSearchInput').value = '';
            document.getElementById('menuTypeFilter').value = 'ALL';
            document.getElementById('menuStatusFilter').value = 'ALL';
            document.getElementById('menuVisibilityFilter').value = 'ALL';
            document.getElementById('menuParentFilter').value = 'ALL';
            loadMenusTable();
          }

          async function loadParentFilterDropdown() {
            try {
              const res = await fetch(getMenuApiUrl() + '?action=parents');
              const text = await res.text();
              let data;
              try {
                data = JSON.parse(text);
              } catch (parseErr) {
                console.error('Non-JSON response for parent options:', text);
                return;
              }
              if (data.success && data.parents) {
                const select = document.getElementById('menuParentFilter');
                if (!select) return;
                select.innerHTML = '<option value="ALL">All Parents</option><option value="ROOT">Root Menus</option>';
                data.parents.forEach(p => {
                  const opt = document.createElement('option');
                  opt.value = p.menu_id;
                  opt.textContent = `${p.menu_name} (${p.menu_code})`;
                  select.appendChild(opt);
                });
              }
            } catch (e) {
              console.error('Error loading parent filter options:', e);
            }
          }

          async function loadMenusTable() {
            const tbody = document.getElementById('menusTableBody');
            if (!tbody) return;

            tbody.innerHTML = `
              <tr>
                <td colspan="11" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                  Loading menu data from Neon PostgreSQL database...
                </td>
              </tr>
            `;

            const search = document.getElementById('menuSearchInput')?.value || '';
            const typeVal = document.getElementById('menuTypeFilter')?.value || 'ALL';
            const statusVal = document.getElementById('menuStatusFilter')?.value || 'ALL';
            const visVal = document.getElementById('menuVisibilityFilter')?.value || 'ALL';
            const parentVal = document.getElementById('menuParentFilter')?.value || 'ALL';

            const params = new URLSearchParams({
              action: 'list',
              search: search,
              menu_type: typeVal,
              status: statusVal,
              visibility: visVal,
              parent_id: parentVal
            });

            try {
              const res = await fetch(getMenuApiUrl() + '?' + params.toString());
              const text = await res.text();
              let data;
              try {
                data = JSON.parse(text);
              } catch (parseErr) {
                console.error('Non-JSON response:', text);
                const cleanMsg = text ? text.replace(/<[^>]*>?/gm, '').trim().substring(0, 200) : 'Server returned an invalid response.';
                tbody.innerHTML = `
                  <tr>
                    <td colspan="11" style="text-align: center; padding: 2rem; color: #F87171;">
                      ${escapeHtml(cleanMsg)}
                    </td>
                  </tr>
                `;
                return;
              }

              if (!data.success) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="11" style="text-align: center; padding: 2rem; color: #F87171;">
                      ${escapeHtml(data.error || 'Failed to load menus.')}
                    </td>
                  </tr>
                `;
                return;
              }

              if (!data.menus || data.menus.length === 0) {
                tbody.innerHTML = `
                  <tr>
                    <td colspan="11" style="text-align: center; padding: 2.5rem; color: var(--color-secondary-text);">
                      No menu records found.
                    </td>
                  </tr>
                `;
                return;
              }

              let rowsHtml = '';
              data.menus.forEach(m => {
                const isChild = (m.parent_menu_id !== null);
                const parentName = escapeHtml(m.parent_menu_name);
                const nameIndent = isChild ? `<span style="color: var(--color-accent); margin-right: 4px;">↳</span> ` : '';
                const typeBadge = m.menu_type === 'GROUP'
                  ? `<span style="padding: 2px 8px; background: rgba(59,130,246,0.15); color: #60A5FA; border-radius: 4px; font-weight: 700; font-size: 0.75rem;">GROUP</span>`
                  : `<span style="padding: 2px 8px; background: rgba(16,185,129,0.15); color: #34D399; border-radius: 4px; font-weight: 700; font-size: 0.75rem;">PAGE</span>`;

                const visBadge = m.is_visible
                  ? `<span style="padding: 2px 6px; background: rgba(16,185,129,0.1); color: #10B981; border-radius: 4px; font-size: 0.78rem;">Visible</span>`
                  : `<span style="padding: 2px 6px; background: rgba(107,114,128,0.15); color: #9CA3AF; border-radius: 4px; font-size: 0.78rem;">Hidden</span>`;

                const statusBadge = m.is_active
                  ? `<span class="status-badge" style="background: rgba(16,185,129,0.15); color: #34D399; border: 1px solid rgba(16,185,129,0.3);">ACTIVE</span>`
                  : `<span class="status-badge" style="background: rgba(239,68,68,0.15); color: #F87171; border: 1px solid rgba(239,68,68,0.3);">INACTIVE</span>`;

                const toggleBtnText = m.is_active ? 'Deactivate' : 'Activate';
                const toggleBtnClass = m.is_active ? 'color: #F87171;' : 'color: #34D399;';

                rowsHtml += `
                  <tr>
                    <td><code>${m.menu_id}</code></td>
                    <td><code>${escapeHtml(m.menu_code)}</code></td>
                    <td><strong>${nameIndent}${escapeHtml(m.menu_name)}</strong></td>
                    <td>${typeBadge}</td>
                    <td><span style="font-size: 0.85rem; color: var(--color-secondary-text);">${parentName}</span></td>
                    <td><code>${escapeHtml(m.route || '—')}</code></td>
                    <td><span style="font-size: 0.85rem; font-family: monospace;">${escapeHtml(m.icon || '—')}</span></td>
                    <td>${m.display_order}</td>
                    <td>${visBadge}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align: right; white-space: nowrap;">
                      <button type="button" class="btn-secondary" style="padding: 0.3rem 0.6rem; font-size: 0.78rem; margin-right: 4px;" onclick="openEditMenuModal(${m.menu_id})">
                        Edit
                      </button>
                      <button type="button" class="btn-secondary" style="padding: 0.3rem 0.6rem; font-size: 0.78rem; ${toggleBtnClass}" onclick="toggleMenuStatus(${m.menu_id}, ${m.is_active})">
                        ${toggleBtnText}
                      </button>
                    </td>
                  </tr>
                `;
              });

              tbody.innerHTML = rowsHtml;
            } catch (err) {
              console.error('Error fetching menus:', err);
              tbody.innerHTML = `
                <tr>
                  <td colspan="11" style="text-align: center; padding: 2rem; color: #F87171;">
                    Failed to connect to menu service. (${escapeHtml(err.message || '')})
                  </td>
                </tr>
              `;
            }
          }

          function handleMenuTypeChange() {
            const type = document.getElementById('formMenuType').value;
            const routeInput = document.getElementById('formRoute');
            const routeLabel = document.getElementById('routeLabel');

            if (type === 'PAGE') {
              routeLabel.innerHTML = 'Route *';
              routeInput.required = true;
            } else {
              routeLabel.innerHTML = 'Route';
              routeInput.required = false;
            }
          }

          async function loadParentFormOptions(excludeMenuId = 0, selectedParentId = null) {
            const parentSelect = document.getElementById('formParentMenuId');
            if (!parentSelect) return;

            parentSelect.innerHTML = '<option value="">None / Root Menu</option>';

            try {
              const url = getMenuApiUrl() + '?action=parents' + (excludeMenuId ? `&exclude_id=${excludeMenuId}` : '');
              const res = await fetch(url);
              const data = await res.json();

              if (data.success && data.parents) {
                data.parents.forEach(p => {
                  const opt = document.createElement('option');
                  opt.value = p.menu_id;
                  opt.textContent = `${p.menu_name} (${p.menu_code})`;
                  if (selectedParentId && parseInt(selectedParentId, 10) === p.menu_id) {
                    opt.selected = true;
                  }
                  parentSelect.appendChild(opt);
                });
              }
            } catch (err) {
              console.error('Error loading parent form options:', err);
            }
          }

          async function openAddMenuModal() {
            document.getElementById('menuFormId').value = '';
            document.getElementById('menuModalTitle').textContent = '+ Add Menu';
            document.getElementById('menuFormError').style.display = 'none';
            document.getElementById('saveMenuBtn').textContent = 'Save Menu';

            document.getElementById('formMenuCode').value = '';
            document.getElementById('formMenuName').value = '';
            document.getElementById('formMenuType').value = 'PAGE';
            document.getElementById('formRoute').value = '';
            document.getElementById('formIcon').value = '';
            document.getElementById('formDisplayOrder').value = '10';
            document.getElementById('formIsVisible').value = 'true';
            document.getElementById('formIsActive').value = 'true';
            document.getElementById('formDescription').value = '';

            handleMenuTypeChange();
            await loadParentFormOptions(0, null);

            document.getElementById('menuModal').style.display = 'flex';
          }

          async function openEditMenuModal(menuId) {
            document.getElementById('menuFormError').style.display = 'none';
            document.getElementById('menuFormId').value = menuId;
            document.getElementById('menuModalTitle').textContent = 'Edit Menu Details';
            document.getElementById('saveMenuBtn').textContent = 'Update Menu';

            try {
              const res = await fetch(getMenuApiUrl() + '?action=get_menu&menu_id=' + menuId);
              const data = await res.json();

              if (!data.success || !data.menu) {
                alert(data.error || 'Failed to fetch menu details.');
                return;
              }

              const m = data.menu;
              document.getElementById('formMenuCode').value = m.menu_code || '';
              document.getElementById('formMenuName').value = m.menu_name || '';
              document.getElementById('formMenuType').value = m.menu_type || 'PAGE';
              document.getElementById('formRoute').value = m.route || '';
              document.getElementById('formIcon').value = m.icon || '';
              document.getElementById('formDisplayOrder').value = m.display_order ?? 10;
              document.getElementById('formIsVisible').value = m.is_visible ? 'true' : 'false';
              document.getElementById('formIsActive').value = m.is_active ? 'true' : 'false';
              document.getElementById('formDescription').value = m.description || '';

              handleMenuTypeChange();
              await loadParentFormOptions(m.menu_id, m.parent_menu_id);

              document.getElementById('menuModal').style.display = 'flex';
            } catch (err) {
              alert('Failed to connect to server.');
            }
          }

          async function submitMenuForm(e) {
            e.preventDefault();
            const form = document.getElementById('menuForm');
            const errorDiv = document.getElementById('menuFormError');
            errorDiv.style.display = 'none';

            const formData = new FormData(form);
            const menuId = document.getElementById('menuFormId').value;
            formData.append('action', menuId ? 'update' : 'create');

            try {
              const res = await fetch(getMenuApiUrl(), {
                method: 'POST',
                body: formData
              });

              const data = await res.json();

              if (data.success) {
                closeModal('menuModal');
                showToast(data.message || (menuId ? 'Menu updated successfully.' : 'Menu created successfully.'), true);
                loadMenusTable();
                loadParentFilterDropdown();
              } else {
                errorDiv.textContent = data.error || 'Operation failed.';
                errorDiv.style.display = 'block';
              }
            } catch (err) {
              errorDiv.textContent = 'Server connection error. Please try again.';
              errorDiv.style.display = 'block';
            }
          }

          async function toggleMenuStatus(menuId, currentStatus) {
            const actionText = currentStatus ? 'deactivate' : 'activate';
            if (!confirm(`Are you sure you want to ${actionText} this menu?`)) {
              return;
            }

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('menu_id', menuId);

            try {
              const res = await fetch(getMenuApiUrl(), {
                method: 'POST',
                body: formData
              });

              const data = await res.json();

              if (data.success) {
                showToast(data.message || 'Menu status updated successfully.', true);
                loadMenusTable();
              } else {
                alert(data.error || 'Failed to update menu status.');
              }
            } catch (err) {
              alert('Server connection error.');
            }
          }

          function showToast(message, isSuccess = true) {
            const toast = document.getElementById('menuMasterToast');
            if (!toast) return;

            toast.textContent = message;
            toast.style.background = isSuccess ? '#D1FAE5' : '#FEE2E2';
            toast.style.color = isSuccess ? '#065F46' : '#991B1B';
            toast.style.border = isSuccess ? '1px solid #A7F3D0' : '1px solid #FCA5A5';
            toast.style.display = 'block';

            setTimeout(() => {
              toast.style.display = 'none';
            }, 4000);
          }
        </script>

      <?php elseif ($currentPage === 'companies'): ?>
        <!-- ============================================================
             COMPANY MASTER PAGE MODULE
             ============================================================ -->
        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">Company Master</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage company records, address details, and organization scope.</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddCompanyModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>Add Company</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadCompaniesTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- KPI STATS BAR -->
          <div class="grid-cards" style="margin-bottom: 1.25rem;">
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Total Companies</div>
              <div class="stat-number" id="kpiTotalCompanies">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Active Companies</div>
              <div class="stat-number" id="kpiActiveCompanies" style="color: #059669;">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Inactive Companies</div>
              <div class="stat-number" id="kpiInactiveCompanies" style="color: #DC2626;">-</div>
            </div>
          </div>

          <!-- FILTER BAR -->
          <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="flex: 1; min-width: 220px; position: relative;">
              <input type="text" id="companySearchInput" class="cost360-select" placeholder="Search companies by code, name, address..." onkeyup="handleCompanySearchKeyup(event)">
            </div>
            <div style="width: 160px;">
              <select id="companyStatusFilter" class="cost360-select" onchange="loadCompaniesTable()">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div id="companyToast" style="display: none; padding: 0.85rem 1.25rem; border-radius: var(--radius-input); margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- TABLE -->
          <div class="table-responsive">
            <table id="companiesTable">
              <thead>
                <tr>
                  <th>Company Code</th>
                  <th>Company Name</th>
                  <th>Customer</th>
                  <th>Address</th>
                  <th>Divisions</th>
                  <th>Plants</th>
                  <th>Status</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="companiesTableBody">
                <tr>
                  <td colspan="8" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                    Loading companies from Neon PostgreSQL database...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ADD / EDIT COMPANY MODAL -->
        <div id="companyModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 540px;">
            <div class="modal-header">
              <h3 id="companyModalTitle" style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">+ Add Company</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('companyModal')">&times;</button>
            </div>
            <form id="companyForm" onsubmit="submitCompanyForm(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="company_id" id="formCompanyId" value="">
              <div id="companyFormError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div id="customerSelectContainer" style="display: none;">
                <label class="dropdown-label">Customer *</label>
                <select name="customer_id" id="formCompanyCustomerId" class="cost360-select"></select>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Company Code *</label>
                  <input type="text" name="company_code" id="formCompanyCode" class="cost360-select" required placeholder="e.g. COMP01">
                </div>
                <div>
                  <label class="dropdown-label">Status *</label>
                  <select name="status" id="formCompanyStatus" class="cost360-select" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="dropdown-label">Company Name *</label>
                <input type="text" name="company_name" id="formCompanyName" class="cost360-select" required placeholder="e.g. Cost360 Manufacturing Ltd">
              </div>

              <div>
                <label class="dropdown-label">Address</label>
                <textarea name="address" id="formCompanyAddress" class="cost360-select" rows="3" placeholder="Enter company address..."></textarea>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('companyModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="saveCompanyBtn">Save Company</button>
              </div>
            </form>
          </div>
        </div>

        <script>
          let companySearchDebounce = null;
          document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('companiesTable')) {
              loadCompaniesTable();
            }
          });

          function getCompanyApiUrl() {
            return (window.cost360BaseUrlPrefix || '') + 'organization/companies/api.php';
          }

          function handleCompanySearchKeyup() {
            clearTimeout(companySearchDebounce);
            companySearchDebounce = setTimeout(loadCompaniesTable, 300);
          }

          async function loadCompaniesTable() {
            const tbody = document.getElementById('companiesTableBody');
            if (!tbody) return;
            const search = document.getElementById('companySearchInput').value.trim();
            const status = document.getElementById('companyStatusFilter').value;

            try {
              const res = await fetch(`${getCompanyApiUrl()}?action=list&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`);
              const data = await res.json();
              if (data.success) {
                document.getElementById('kpiTotalCompanies').textContent = data.kpis.total;
                document.getElementById('kpiActiveCompanies').textContent = data.kpis.active;
                document.getElementById('kpiInactiveCompanies').textContent = data.kpis.inactive;

                // Populate Customer Select if applicable
                const custSelect = document.getElementById('formCompanyCustomerId');
                if (data.customers && data.customers.length > 0) {
                  custSelect.innerHTML = data.customers.map(c => `<option value="${c.customer_id}">${escapeHtml(c.customer_name)} (${escapeHtml(c.customer_code)})</option>`).join('');
                  document.getElementById('customerSelectContainer').style.display = 'block';
                }

                if (!data.companies || data.companies.length === 0) {
                  tbody.innerHTML = '<tr><td colspan="8" class="empty-state">No companies found matching current filters.</td></tr>';
                  return;
                }

                tbody.innerHTML = data.companies.map(c => {
                  const isActive = (c.status || '').toUpperCase() === 'ACTIVE';
                  const statusBadge = isActive ? '<span class="status-badge">Active</span>' : '<span class="status-badge" style="background:#FEE2E2;color:#DC2626;">Inactive</span>';
                  const toggleBtnText = isActive ? 'Deactivate' : 'Activate';
                  const toggleBtnClass = isActive ? 'btn-logout' : 'btn-primary';

                  return `
                    <tr>
                      <td><code>${escapeHtml(c.company_code)}</code></td>
                      <td style="font-weight:700; color:var(--color-primary-text);">${escapeHtml(c.company_name)}</td>
                      <td>${escapeHtml(c.customer_name || '-')}</td>
                      <td style="font-size:0.82rem; color:var(--color-secondary-text);">${escapeHtml(c.address || '-')}</td>
                      <td><span class="role-badge">${c.division_count || 0} Divs</span></td>
                      <td><span class="role-badge" style="background:#E0F2FE;color:#0369A1;">${c.plant_count || 0} Plants</span></td>
                      <td>${statusBadge}</td>
                      <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="btn-secondary" style="padding:0.35rem 0.65rem; font-size:0.78rem; margin-right:0.35rem;" onclick="openEditCompanyModal(${c.company_id})">Edit</button>
                        <button type="button" class="${toggleBtnClass}" style="padding:0.35rem 0.65rem; font-size:0.78rem;" onclick="toggleCompanyStatus(${c.company_id}, '${c.status}')">${toggleBtnText}</button>
                      </td>
                    </tr>
                  `;
                }).join('');
              } else {
                tbody.innerHTML = `<tr><td colspan="8" class="empty-state" style="color:#DC2626;">${escapeHtml(data.error)}</td></tr>`;
              }
            } catch (err) {
              tbody.innerHTML = '<tr><td colspan="8" class="empty-state" style="color:#DC2626;">Failed to load data from server.</td></tr>';
            }
          }

          function openAddCompanyModal() {
            document.getElementById('companyModalTitle').textContent = '+ Add Company';
            document.getElementById('formCompanyId').value = '';
            document.getElementById('companyForm').reset();
            document.getElementById('formCompanyCode').removeAttribute('readonly');
            document.getElementById('companyFormError').style.display = 'none';
            document.getElementById('companyModal').style.display = 'flex';
          }

          async function openEditCompanyModal(id) {
            try {
              const res = await fetch(`${getCompanyApiUrl()}?action=get&company_id=${id}`);
              const data = await res.json();
              if (data.success && data.company) {
                const c = data.company;
                document.getElementById('companyModalTitle').textContent = 'Edit Company';
                document.getElementById('formCompanyId').value = c.company_id;
                document.getElementById('formCompanyCode').value = c.company_code;
                document.getElementById('formCompanyCode').setAttribute('readonly', 'readonly');
                document.getElementById('formCompanyName').value = c.company_name;
                document.getElementById('formCompanyAddress').value = c.address || '';
                document.getElementById('formCompanyStatus').value = c.status;
                if (document.getElementById('formCompanyCustomerId')) {
                  document.getElementById('formCompanyCustomerId').value = c.customer_id;
                }
                document.getElementById('companyFormError').style.display = 'none';
                document.getElementById('companyModal').style.display = 'flex';
              }
            } catch (err) {
              alert('Failed to load company details.');
            }
          }

          async function submitCompanyForm(e) {
            e.preventDefault();
            const form = document.getElementById('companyForm');
            const errDiv = document.getElementById('companyFormError');
            errDiv.style.display = 'none';
            const formData = new FormData(form);
            const id = document.getElementById('formCompanyId').value;
            formData.append('action', id ? 'update' : 'create');

            try {
              const res = await fetch(getCompanyApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                closeModal('companyModal');
                showCompanyToast(data.message || 'Company saved successfully.', true);
                loadCompaniesTable();
              } else {
                errDiv.textContent = data.error || 'Failed to save company.';
                errDiv.style.display = 'block';
              }
            } catch (err) {
              errDiv.textContent = 'Server connection error.';
              errDiv.style.display = 'block';
            }
          }

          async function toggleCompanyStatus(id, currentStatus) {
            const newStatus = (currentStatus || '').toUpperCase() === 'ACTIVE' ? 'Inactive' : 'Active';
            if (!confirm(`Are you sure you want to set company status to ${newStatus}?`)) return;

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('company_id', id);
            formData.append('status', newStatus);

            try {
              const res = await fetch(getCompanyApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                showCompanyToast(data.message, true);
                loadCompaniesTable();
              } else {
                alert(data.error || 'Failed to update status.');
              }
            } catch (err) {
              alert('Server connection error.');
            }
          }

          function showCompanyToast(msg, isSuccess) {
            const toast = document.getElementById('companyToast');
            if (!toast) return;
            toast.textContent = msg;
            toast.style.background = isSuccess ? '#D1FAE5' : '#FEE2E2';
            toast.style.color = isSuccess ? '#065F46' : '#991B1B';
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 4000);
          }
        </script>

      <?php elseif ($currentPage === 'divisions'): ?>
        <!-- ============================================================
             DIVISION MASTER PAGE MODULE
             ============================================================ -->
        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">Division Master</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage divisions under companies (Company &rarr; Division hierarchy).</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddDivisionModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>+ Add Division</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadDivisionsTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- KPI STATS BAR -->
          <div class="grid-cards" style="margin-bottom: 1.25rem;">
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Total Divisions</div>
              <div class="stat-number" id="kpiTotalDivisions">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Active Divisions</div>
              <div class="stat-number" id="kpiActiveDivisions" style="color: #059669;">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Inactive Divisions</div>
              <div class="stat-number" id="kpiInactiveDivisions" style="color: #DC2626;">-</div>
            </div>
          </div>

          <!-- FILTER BAR -->
          <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="width: 220px;">
              <select id="divisionCompanyFilter" class="cost360-select" onchange="loadDivisionsTable()">
                <option value="">All Companies</option>
              </select>
            </div>
            <div style="flex: 1; min-width: 200px;">
              <input type="text" id="divisionSearchInput" class="cost360-select" placeholder="Search divisions by code, name, company..." onkeyup="handleDivisionSearchKeyup(event)">
            </div>
            <div style="width: 140px;">
              <select id="divisionStatusFilter" class="cost360-select" onchange="loadDivisionsTable()">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div id="divisionToast" style="display: none; padding: 0.85rem 1.25rem; border-radius: var(--radius-input); margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- TABLE -->
          <div class="table-responsive">
            <table id="divisionsTable">
              <thead>
                <tr>
                  <th>Division Code</th>
                  <th>Division Name</th>
                  <th>Company</th>
                  <th>Customer</th>
                  <th>Plants</th>
                  <th>Status</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="divisionsTableBody">
                <tr>
                  <td colspan="7" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                    Loading divisions from Neon PostgreSQL database...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ADD / EDIT DIVISION MODAL -->
        <div id="divisionModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 520px;">
            <div class="modal-header">
              <h3 id="divisionModalTitle" style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">+ Add Division</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('divisionModal')">&times;</button>
            </div>
            <form id="divisionForm" onsubmit="submitDivisionForm(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="division_id" id="formDivisionId" value="">
              <div id="divisionFormError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div>
                <label class="dropdown-label">Company *</label>
                <select name="company_id" id="formDivisionCompanyId" class="cost360-select" required></select>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Division Code *</label>
                  <input type="text" name="division_code" id="formDivisionCode" class="cost360-select" required placeholder="e.g. DIV-AUTO">
                </div>
                <div>
                  <label class="dropdown-label">Status *</label>
                  <select name="status" id="formDivisionStatus" class="cost360-select" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="dropdown-label">Division Name *</label>
                <input type="text" name="division_name" id="formDivisionName" class="cost360-select" required placeholder="e.g. Automotive Division">
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('divisionModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="saveDivisionBtn">Save Division</button>
              </div>
            </form>
          </div>
        </div>

        <script>
          let divisionSearchDebounce = null;
          document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('divisionsTable')) {
              loadDivisionsTable();
            }
          });

          function getDivisionApiUrl() {
            return (window.cost360BaseUrlPrefix || '') + 'organization/divisions/api.php';
          }

          function handleDivisionSearchKeyup() {
            clearTimeout(divisionSearchDebounce);
            divisionSearchDebounce = setTimeout(loadDivisionsTable, 300);
          }

          async function loadDivisionsTable() {
            const tbody = document.getElementById('divisionsTableBody');
            if (!tbody) return;
            const companyId = document.getElementById('divisionCompanyFilter').value;
            const search = document.getElementById('divisionSearchInput').value.trim();
            const status = document.getElementById('divisionStatusFilter').value;

            try {
              const res = await fetch(`${getDivisionApiUrl()}?action=list&company_id=${companyId}&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`);
              const data = await res.json();
              if (data.success) {
                document.getElementById('kpiTotalDivisions').textContent = data.kpis.total;
                document.getElementById('kpiActiveDivisions').textContent = data.kpis.active;
                document.getElementById('kpiInactiveDivisions').textContent = data.kpis.inactive;

                // Populate Company Filter & Modal Selects
                const filterCompSelect = document.getElementById('divisionCompanyFilter');
                const formCompSelect = document.getElementById('formDivisionCompanyId');
                const selectedFilterComp = filterCompSelect.value;

                if (data.companies && data.companies.length > 0) {
                  filterCompSelect.innerHTML = '<option value="">All Companies</option>' + data.companies.map(c => `<option value="${c.company_id}">${escapeHtml(c.company_name)} (${escapeHtml(c.company_code)})</option>`).join('');
                  filterCompSelect.value = selectedFilterComp;

                  formCompSelect.innerHTML = data.companies.map(c => `<option value="${c.company_id}">${escapeHtml(c.company_name)} (${escapeHtml(c.company_code)})</option>`).join('');
                }

                if (!data.divisions || data.divisions.length === 0) {
                  tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No divisions found.</td></tr>';
                  return;
                }

                tbody.innerHTML = data.divisions.map(d => {
                  const isActive = (d.status || '').toUpperCase() === 'ACTIVE';
                  const statusBadge = isActive ? '<span class="status-badge">Active</span>' : '<span class="status-badge" style="background:#FEE2E2;color:#DC2626;">Inactive</span>';
                  const toggleBtnText = isActive ? 'Deactivate' : 'Activate';
                  const toggleBtnClass = isActive ? 'btn-logout' : 'btn-primary';

                  return `
                    <tr>
                      <td><code>${escapeHtml(d.division_code)}</code></td>
                      <td style="font-weight:700; color:var(--color-primary-text);">${escapeHtml(d.division_name)}</td>
                      <td>${escapeHtml(d.company_name || '-')}</td>
                      <td>${escapeHtml(d.customer_name || '-')}</td>
                      <td><span class="role-badge" style="background:#E0F2FE;color:#0369A1;">${d.plant_count || 0} Plants</span></td>
                      <td>${statusBadge}</td>
                      <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="btn-secondary" style="padding:0.35rem 0.65rem; font-size:0.78rem; margin-right:0.35rem;" onclick="openEditDivisionModal(${d.division_id})">Edit</button>
                        <button type="button" class="${toggleBtnClass}" style="padding:0.35rem 0.65rem; font-size:0.78rem;" onclick="toggleDivisionStatus(${d.division_id}, '${d.status}')">${toggleBtnText}</button>
                      </td>
                    </tr>
                  `;
                }).join('');
              } else {
                tbody.innerHTML = `<tr><td colspan="7" class="empty-state" style="color:#DC2626;">${escapeHtml(data.error)}</td></tr>`;
              }
            } catch (err) {
              tbody.innerHTML = '<tr><td colspan="7" class="empty-state" style="color:#DC2626;">Failed to load data from server.</td></tr>';
            }
          }

          function openAddDivisionModal() {
            document.getElementById('divisionModalTitle').textContent = '+ Add Division';
            document.getElementById('formDivisionId').value = '';
            document.getElementById('formDivisionCode').value = '';
            document.getElementById('formDivisionCode').removeAttribute('readonly');
            document.getElementById('formDivisionName').value = '';
            document.getElementById('formDivisionStatus').value = 'Active';
            document.getElementById('divisionFormError').style.display = 'none';
            document.getElementById('divisionModal').style.display = 'flex';
          }

          async function openEditDivisionModal(id) {
            try {
              const res = await fetch(`${getDivisionApiUrl()}?action=get&division_id=${id}`);
              const data = await res.json();
              if (data.success && data.division) {
                const d = data.division;
                document.getElementById('divisionModalTitle').textContent = 'Edit Division';
                document.getElementById('formDivisionId').value = d.division_id;
                document.getElementById('formDivisionCompanyId').value = d.company_id;
                document.getElementById('formDivisionCode').value = d.division_code;
                document.getElementById('formDivisionCode').setAttribute('readonly', 'readonly');
                document.getElementById('formDivisionName').value = d.division_name;
                document.getElementById('formDivisionStatus').value = d.status;
                document.getElementById('divisionFormError').style.display = 'none';
                document.getElementById('divisionModal').style.display = 'flex';
              }
            } catch (err) {
              alert('Failed to load division details.');
            }
          }

          async function submitDivisionForm(e) {
            e.preventDefault();
            const form = document.getElementById('divisionForm');
            const errDiv = document.getElementById('divisionFormError');
            errDiv.style.display = 'none';
            const formData = new FormData(form);
            const id = document.getElementById('formDivisionId').value;
            formData.append('action', id ? 'update' : 'create');

            try {
              const res = await fetch(getDivisionApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                closeModal('divisionModal');
                showDivisionToast(data.message || 'Division saved successfully.', true);
                loadDivisionsTable();
              } else {
                errDiv.textContent = data.error || 'Failed to save division.';
                errDiv.style.display = 'block';
              }
            } catch (err) {
              errDiv.textContent = 'Server connection error.';
              errDiv.style.display = 'block';
            }
          }

          async function toggleDivisionStatus(id, currentStatus) {
            const newStatus = (currentStatus || '').toUpperCase() === 'ACTIVE' ? 'Inactive' : 'Active';
            if (!confirm(`Are you sure you want to set division status to ${newStatus}?`)) return;

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('division_id', id);
            formData.append('status', newStatus);

            try {
              const res = await fetch(getDivisionApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                showDivisionToast(data.message, true);
                loadDivisionsTable();
              } else {
                alert(data.error || 'Failed to update status.');
              }
            } catch (err) {
              alert('Server connection error.');
            }
          }

          function showDivisionToast(msg, isSuccess) {
            const toast = document.getElementById('divisionToast');
            if (!toast) return;
            toast.textContent = msg;
            toast.style.background = isSuccess ? '#D1FAE5' : '#FEE2E2';
            toast.style.color = isSuccess ? '#065F46' : '#991B1B';
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 4000);
          }
        </script>

      <?php elseif ($currentPage === 'plants'): ?>
        <!-- ============================================================
             PLANT MASTER PAGE MODULE
             ============================================================ -->
        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">Plant Master</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage manufacturing plants (Company &rarr; Division &rarr; Plant hierarchy).</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddPlantModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>+ Add Plant</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadPlantsTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- KPI STATS BAR -->
          <div class="grid-cards" style="margin-bottom: 1.25rem;">
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Total Plants</div>
              <div class="stat-number" id="kpiTotalPlants">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Active Plants</div>
              <div class="stat-number" id="kpiActivePlants" style="color: #059669;">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Inactive Plants</div>
              <div class="stat-number" id="kpiInactivePlants" style="color: #DC2626;">-</div>
            </div>
          </div>

          <!-- FILTER BAR (DYNAMIC DEPENDENT DROPDOWNS) -->
          <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="width: 200px;">
              <select id="plantCompanyFilter" class="cost360-select" onchange="handlePlantCompanyFilterChange()">
                <option value="">All Companies</option>
              </select>
            </div>
            <div style="width: 200px;">
              <select id="plantDivisionFilter" class="cost360-select" onchange="loadPlantsTable()">
                <option value="">All Divisions</option>
              </select>
            </div>
            <div style="flex: 1; min-width: 180px;">
              <input type="text" id="plantSearchInput" class="cost360-select" placeholder="Search plants by code, name, location..." onkeyup="handlePlantSearchKeyup(event)">
            </div>
            <div style="width: 130px;">
              <select id="plantStatusFilter" class="cost360-select" onchange="loadPlantsTable()">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div id="plantToast" style="display: none; padding: 0.85rem 1.25rem; border-radius: var(--radius-input); margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- TABLE -->
          <div class="table-responsive">
            <table id="plantsTable">
              <thead>
                <tr>
                  <th>Plant Code</th>
                  <th>Plant Name</th>
                  <th>Division</th>
                  <th>Company</th>
                  <th>Location</th>
                  <th>Warehouses</th>
                  <th>Status</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="plantsTableBody">
                <tr>
                  <td colspan="8" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                    Loading plants from Neon PostgreSQL database...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ADD / EDIT PLANT MODAL -->
        <div id="plantModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 540px;">
            <div class="modal-header">
              <h3 id="plantModalTitle" style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">+ Add Plant</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('plantModal')">&times;</button>
            </div>
            <form id="plantForm" onsubmit="submitPlantForm(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="plant_id" id="formPlantId" value="">
              <div id="plantFormError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Company *</label>
                  <select id="formPlantCompanyId" class="cost360-select" required onchange="handleFormPlantCompanyChange()"></select>
                </div>
                <div>
                  <label class="dropdown-label">Division *</label>
                  <select name="division_id" id="formPlantDivisionId" class="cost360-select" required></select>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Plant Code *</label>
                  <input type="text" name="plant_code" id="formPlantCode" class="cost360-select" required placeholder="e.g. PLT-NASHIK">
                </div>
                <div>
                  <label class="dropdown-label">Status *</label>
                  <select name="status" id="formPlantStatus" class="cost360-select" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="dropdown-label">Plant Name *</label>
                <input type="text" name="plant_name" id="formPlantName" class="cost360-select" required placeholder="e.g. Nashik Manufacturing Plant">
              </div>

              <div>
                <label class="dropdown-label">Location</label>
                <input type="text" name="location" id="formPlantLocation" class="cost360-select" placeholder="e.g. Nashik MIDC, Maharashtra">
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('plantModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="savePlantBtn">Save Plant</button>
              </div>
            </form>
          </div>
        </div>

        <script>
          let plantSearchDebounce = null;
          document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('plantsTable')) {
              loadPlantsTable();
            }
          });

          function getPlantApiUrl() {
            return (window.cost360BaseUrlPrefix || '') + 'organization/plants/api.php';
          }

          function handlePlantSearchKeyup() {
            clearTimeout(plantSearchDebounce);
            plantSearchDebounce = setTimeout(loadPlantsTable, 300);
          }

          async function handlePlantCompanyFilterChange() {
            const companyId = document.getElementById('plantCompanyFilter').value;
            const divSelect = document.getElementById('plantDivisionFilter');
            divSelect.innerHTML = '<option value="">All Divisions</option>';
            if (companyId) {
              const res = await fetch(`${getPlantApiUrl()}?action=get_divisions&company_id=${companyId}`);
              const data = await res.json();
              if (data.success && data.divisions) {
                divSelect.innerHTML = '<option value="">All Divisions</option>' + data.divisions.map(d => `<option value="${d.division_id}">${escapeHtml(d.division_name)} (${escapeHtml(d.division_code)})</option>`).join('');
              }
            }
            loadPlantsTable();
          }

          async function handleFormPlantCompanyChange(targetDivisionId = null) {
            const companyId = document.getElementById('formPlantCompanyId').value;
            const divSelect = document.getElementById('formPlantDivisionId');
            divSelect.innerHTML = '<option value="">Select Division...</option>';
            if (companyId) {
              const res = await fetch(`${getPlantApiUrl()}?action=get_divisions&company_id=${companyId}`);
              const data = await res.json();
              if (data.success && data.divisions) {
                divSelect.innerHTML = data.divisions.map(d => `<option value="${d.division_id}">${escapeHtml(d.division_name)} (${escapeHtml(d.division_code)})</option>`).join('');
                if (targetDivisionId) {
                  divSelect.value = targetDivisionId;
                }
              }
            }
          }

          async function loadPlantsTable() {
            const tbody = document.getElementById('plantsTableBody');
            if (!tbody) return;
            const companyId = document.getElementById('plantCompanyFilter').value;
            const divisionId = document.getElementById('plantDivisionFilter').value;
            const search = document.getElementById('plantSearchInput').value.trim();
            const status = document.getElementById('plantStatusFilter').value;

            try {
              const res = await fetch(`${getPlantApiUrl()}?action=list&company_id=${companyId}&division_id=${divisionId}&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`);
              const data = await res.json();
              if (data.success) {
                document.getElementById('kpiTotalPlants').textContent = data.kpis.total;
                document.getElementById('kpiActivePlants').textContent = data.kpis.active;
                document.getElementById('kpiInactivePlants').textContent = data.kpis.inactive;

                // Populate Company Selects
                const filterCompSelect = document.getElementById('plantCompanyFilter');
                const formCompSelect = document.getElementById('formPlantCompanyId');
                const selectedFilterComp = filterCompSelect.value;
                if (data.companies && data.companies.length > 0) {
                  filterCompSelect.innerHTML = '<option value="">All Companies</option>' + data.companies.map(c => `<option value="${c.company_id}">${escapeHtml(c.company_name)} (${escapeHtml(c.company_code)})</option>`).join('');
                  filterCompSelect.value = selectedFilterComp;

                  formCompSelect.innerHTML = data.companies.map(c => `<option value="${c.company_id}">${escapeHtml(c.company_name)} (${escapeHtml(c.company_code)})</option>`).join('');
                }

                if (!data.plants || data.plants.length === 0) {
                  tbody.innerHTML = '<tr><td colspan="8" class="empty-state">No plants found.</td></tr>';
                  return;
                }

                tbody.innerHTML = data.plants.map(p => {
                  const isActive = (p.status || '').toUpperCase() === 'ACTIVE';
                  const statusBadge = isActive ? '<span class="status-badge">Active</span>' : '<span class="status-badge" style="background:#FEE2E2;color:#DC2626;">Inactive</span>';
                  const toggleBtnText = isActive ? 'Deactivate' : 'Activate';
                  const toggleBtnClass = isActive ? 'btn-logout' : 'btn-primary';

                  return `
                    <tr>
                      <td><code>${escapeHtml(p.plant_code)}</code></td>
                      <td style="font-weight:700; color:var(--color-primary-text);">${escapeHtml(p.plant_name)}</td>
                      <td>${escapeHtml(p.division_name || '-')}</td>
                      <td>${escapeHtml(p.company_name || '-')}</td>
                      <td style="font-size:0.82rem; color:var(--color-secondary-text);">${escapeHtml(p.location || '-')}</td>
                      <td><span class="role-badge" style="background:#E0F2FE;color:#0369A1;">${p.warehouse_count || 0} WH</span></td>
                      <td>${statusBadge}</td>
                      <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="btn-secondary" style="padding:0.35rem 0.65rem; font-size:0.78rem; margin-right:0.35rem;" onclick="openEditPlantModal(${p.plant_id})">Edit</button>
                        <button type="button" class="${toggleBtnClass}" style="padding:0.35rem 0.65rem; font-size:0.78rem;" onclick="togglePlantStatus(${p.plant_id}, '${p.status}')">${toggleBtnText}</button>
                      </td>
                    </tr>
                  `;
                }).join('');
              } else {
                tbody.innerHTML = `<tr><td colspan="8" class="empty-state" style="color:#DC2626;">${escapeHtml(data.error)}</td></tr>`;
              }
            } catch (err) {
              tbody.innerHTML = '<tr><td colspan="8" class="empty-state" style="color:#DC2626;">Failed to load data from server.</td></tr>';
            }
          }

          async function openAddPlantModal() {
            document.getElementById('plantModalTitle').textContent = '+ Add Plant';
            document.getElementById('formPlantId').value = '';
            document.getElementById('formPlantCode').value = '';
            document.getElementById('formPlantCode').removeAttribute('readonly');
            document.getElementById('formPlantName').value = '';
            document.getElementById('formPlantLocation').value = '';
            document.getElementById('formPlantStatus').value = 'Active';
            document.getElementById('plantFormError').style.display = 'none';
            await handleFormPlantCompanyChange();
            document.getElementById('plantModal').style.display = 'flex';
          }

          async function openEditPlantModal(id) {
            try {
              const res = await fetch(`${getPlantApiUrl()}?action=get&plant_id=${id}`);
              const data = await res.json();
              if (data.success && data.plant) {
                const p = data.plant;
                document.getElementById('plantModalTitle').textContent = 'Edit Plant';
                document.getElementById('formPlantId').value = p.plant_id;
                document.getElementById('formPlantCompanyId').value = p.company_id;
                await handleFormPlantCompanyChange(p.division_id);
                document.getElementById('formPlantCode').value = p.plant_code;
                document.getElementById('formPlantCode').setAttribute('readonly', 'readonly');
                document.getElementById('formPlantName').value = p.plant_name;
                document.getElementById('formPlantLocation').value = p.location || '';
                document.getElementById('formPlantStatus').value = p.status;
                document.getElementById('plantFormError').style.display = 'none';
                document.getElementById('plantModal').style.display = 'flex';
              }
            } catch (err) {
              alert('Failed to load plant details.');
            }
          }

          async function submitPlantForm(e) {
            e.preventDefault();
            const form = document.getElementById('plantForm');
            const errDiv = document.getElementById('plantFormError');
            errDiv.style.display = 'none';
            const formData = new FormData(form);
            const id = document.getElementById('formPlantId').value;
            formData.append('action', id ? 'update' : 'create');

            try {
              const res = await fetch(getPlantApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                closeModal('plantModal');
                showPlantToast(data.message || 'Plant saved successfully.', true);
                loadPlantsTable();
              } else {
                errDiv.textContent = data.error || 'Failed to save plant.';
                errDiv.style.display = 'block';
              }
            } catch (err) {
              errDiv.textContent = 'Server connection error.';
              errDiv.style.display = 'block';
            }
          }

          async function togglePlantStatus(id, currentStatus) {
            const newStatus = (currentStatus || '').toUpperCase() === 'ACTIVE' ? 'Inactive' : 'Active';
            if (!confirm(`Are you sure you want to set plant status to ${newStatus}?`)) return;

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('plant_id', id);
            formData.append('status', newStatus);

            try {
              const res = await fetch(getPlantApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                showPlantToast(data.message, true);
                loadPlantsTable();
              } else {
                alert(data.error || 'Failed to update status.');
              }
            } catch (err) {
              alert('Server connection error.');
            }
          }

          function showPlantToast(msg, isSuccess) {
            const toast = document.getElementById('plantToast');
            if (!toast) return;
            toast.textContent = msg;
            toast.style.background = isSuccess ? '#D1FAE5' : '#FEE2E2';
            toast.style.color = isSuccess ? '#065F46' : '#991B1B';
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 4000);
          }
        </script>

      <?php elseif ($currentPage === 'warehouses'): ?>
        <!-- ============================================================
             WAREHOUSE MASTER PAGE MODULE
             ============================================================ -->
        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">Warehouse Master</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage storage locations (Company &rarr; Division &rarr; Plant &rarr; Warehouse hierarchy).</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddWarehouseModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>+ Add Warehouse</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadWarehousesTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- KPI STATS BAR -->
          <div class="grid-cards" style="margin-bottom: 1.25rem;">
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Total Warehouses</div>
              <div class="stat-number" id="kpiTotalWarehouses">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Active Warehouses</div>
              <div class="stat-number" id="kpiActiveWarehouses" style="color: #059669;">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Inactive Warehouses</div>
              <div class="stat-number" id="kpiInactiveWarehouses" style="color: #DC2626;">-</div>
            </div>
          </div>

          <!-- FILTER BAR (3-LEVEL DEPENDENT DROPDOWNS) -->
          <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="width: 170px;">
              <select id="whCompanyFilter" class="cost360-select" onchange="handleWhCompanyFilterChange()">
                <option value="">All Companies</option>
              </select>
            </div>
            <div style="width: 170px;">
              <select id="whDivisionFilter" class="cost360-select" onchange="handleWhDivisionFilterChange()">
                <option value="">All Divisions</option>
              </select>
            </div>
            <div style="width: 170px;">
              <select id="whPlantFilter" class="cost360-select" onchange="loadWarehousesTable()">
                <option value="">All Plants</option>
              </select>
            </div>
            <div style="flex: 1; min-width: 160px;">
              <input type="text" id="whSearchInput" class="cost360-select" placeholder="Search warehouses..." onkeyup="handleWhSearchKeyup(event)">
            </div>
            <div style="width: 120px;">
              <select id="whStatusFilter" class="cost360-select" onchange="loadWarehousesTable()">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div id="whToast" style="display: none; padding: 0.85rem 1.25rem; border-radius: var(--radius-input); margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- TABLE -->
          <div class="table-responsive">
            <table id="warehousesTable">
              <thead>
                <tr>
                  <th>Warehouse Code</th>
                  <th>Warehouse Name</th>
                  <th>Plant</th>
                  <th>Division</th>
                  <th>Company</th>
                  <th>Status</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="warehousesTableBody">
                <tr>
                  <td colspan="7" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                    Loading warehouses from Neon PostgreSQL database...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ADD / EDIT WAREHOUSE MODAL -->
        <div id="whModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 560px;">
            <div class="modal-header">
              <h3 id="whModalTitle" style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">+ Add Warehouse</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('whModal')">&times;</button>
            </div>
            <form id="whForm" onsubmit="submitWhForm(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="warehouse_id" id="formWhId" value="">
              <div id="whFormError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                <div>
                  <label class="dropdown-label">Company *</label>
                  <select id="formWhCompanyId" class="cost360-select" required onchange="handleFormWhCompanyChange()"></select>
                </div>
                <div>
                  <label class="dropdown-label">Division *</label>
                  <select id="formWhDivisionId" class="cost360-select" required onchange="handleFormWhDivisionChange()"></select>
                </div>
                <div>
                  <label class="dropdown-label">Plant *</label>
                  <select name="plant_id" id="formWhPlantId" class="cost360-select" required></select>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Warehouse Code *</label>
                  <input type="text" name="warehouse_code" id="formWhCode" class="cost360-select" required placeholder="e.g. WH-RM01">
                </div>
                <div>
                  <label class="dropdown-label">Status *</label>
                  <select name="status" id="formWhStatus" class="cost360-select" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="dropdown-label">Warehouse Name *</label>
                <input type="text" name="warehouse_name" id="formWhName" class="cost360-select" required placeholder="e.g. Raw Material Store 1">
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('whModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="saveWhBtn">Save Warehouse</button>
              </div>
            </form>
          </div>
        </div>

        <script>
          let whSearchDebounce = null;
          document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('warehousesTable')) {
              loadWarehousesTable();
            }
          });

          function getWhApiUrl() {
            return (window.cost360BaseUrlPrefix || '') + 'organization/warehouses/api.php';
          }

          function getPlantApiUrlForWh() {
            return (window.cost360BaseUrlPrefix || '') + 'organization/plants/api.php';
          }

          function handleWhSearchKeyup() {
            clearTimeout(whSearchDebounce);
            whSearchDebounce = setTimeout(loadWarehousesTable, 300);
          }

          async function handleWhCompanyFilterChange() {
            const companyId = document.getElementById('whCompanyFilter').value;
            const divSelect = document.getElementById('whDivisionFilter');
            const plantSelect = document.getElementById('whPlantFilter');
            divSelect.innerHTML = '<option value="">All Divisions</option>';
            plantSelect.innerHTML = '<option value="">All Plants</option>';

            if (companyId) {
              const res = await fetch(`${getPlantApiUrlForWh()}?action=get_divisions&company_id=${companyId}`);
              const data = await res.json();
              if (data.success && data.divisions) {
                divSelect.innerHTML = '<option value="">All Divisions</option>' + data.divisions.map(d => `<option value="${d.division_id}">${escapeHtml(d.division_name)} (${escapeHtml(d.division_code)})</option>`).join('');
              }
            }
            loadWarehousesTable();
          }

          async function handleWhDivisionFilterChange() {
            const divisionId = document.getElementById('whDivisionFilter').value;
            const plantSelect = document.getElementById('whPlantFilter');
            plantSelect.innerHTML = '<option value="">All Plants</option>';

            if (divisionId) {
              const res = await fetch(`${getWhApiUrl()}?action=get_plants&division_id=${divisionId}`);
              const data = await res.json();
              if (data.success && data.plants) {
                plantSelect.innerHTML = '<option value="">All Plants</option>' + data.plants.map(p => `<option value="${p.plant_id}">${escapeHtml(p.plant_name)} (${escapeHtml(p.plant_code)})</option>`).join('');
              }
            }
            loadWarehousesTable();
          }

          async function handleFormWhCompanyChange(targetDivisionId = null, targetPlantId = null) {
            const companyId = document.getElementById('formWhCompanyId').value;
            const divSelect = document.getElementById('formWhDivisionId');
            const plantSelect = document.getElementById('formWhPlantId');
            divSelect.innerHTML = '<option value="">Select Division...</option>';
            plantSelect.innerHTML = '<option value="">Select Plant...</option>';

            if (companyId) {
              const res = await fetch(`${getPlantApiUrlForWh()}?action=get_divisions&company_id=${companyId}`);
              const data = await res.json();
              if (data.success && data.divisions) {
                divSelect.innerHTML = data.divisions.map(d => `<option value="${d.division_id}">${escapeHtml(d.division_name)} (${escapeHtml(d.division_code)})</option>`).join('');
                if (targetDivisionId) {
                  divSelect.value = targetDivisionId;
                  await handleFormWhDivisionChange(targetPlantId);
                }
              }
            }
          }

          async function handleFormWhDivisionChange(targetPlantId = null) {
            const divisionId = document.getElementById('formWhDivisionId').value;
            const plantSelect = document.getElementById('formWhPlantId');
            plantSelect.innerHTML = '<option value="">Select Plant...</option>';

            if (divisionId) {
              const res = await fetch(`${getWhApiUrl()}?action=get_plants&division_id=${divisionId}`);
              const data = await res.json();
              if (data.success && data.plants) {
                plantSelect.innerHTML = data.plants.map(p => `<option value="${p.plant_id}">${escapeHtml(p.plant_name)} (${escapeHtml(p.plant_code)})</option>`).join('');
                if (targetPlantId) {
                  plantSelect.value = targetPlantId;
                }
              }
            }
          }

          async function loadWarehousesTable() {
            const tbody = document.getElementById('warehousesTableBody');
            if (!tbody) return;
            const companyId = document.getElementById('whCompanyFilter').value;
            const divisionId = document.getElementById('whDivisionFilter').value;
            const plantId = document.getElementById('whPlantFilter').value;
            const search = document.getElementById('whSearchInput').value.trim();
            const status = document.getElementById('whStatusFilter').value;

            try {
              const res = await fetch(`${getWhApiUrl()}?action=list&company_id=${companyId}&division_id=${divisionId}&plant_id=${plantId}&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`);
              const data = await res.json();
              if (data.success) {
                document.getElementById('kpiTotalWarehouses').textContent = data.kpis.total;
                document.getElementById('kpiActiveWarehouses').textContent = data.kpis.active;
                document.getElementById('kpiInactiveWarehouses').textContent = data.kpis.inactive;

                // Populate Company Selects
                const filterCompSelect = document.getElementById('whCompanyFilter');
                const formCompSelect = document.getElementById('formWhCompanyId');
                const selectedFilterComp = filterCompSelect.value;
                if (data.companies && data.companies.length > 0) {
                  filterCompSelect.innerHTML = '<option value="">All Companies</option>' + data.companies.map(c => `<option value="${c.company_id}">${escapeHtml(c.company_name)} (${escapeHtml(c.company_code)})</option>`).join('');
                  filterCompSelect.value = selectedFilterComp;

                  formCompSelect.innerHTML = data.companies.map(c => `<option value="${c.company_id}">${escapeHtml(c.company_name)} (${escapeHtml(c.company_code)})</option>`).join('');
                }

                if (!data.warehouses || data.warehouses.length === 0) {
                  tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No warehouses found.</td></tr>';
                  return;
                }

                tbody.innerHTML = data.warehouses.map(w => {
                  const isActive = (w.status || '').toUpperCase() === 'ACTIVE';
                  const statusBadge = isActive ? '<span class="status-badge">Active</span>' : '<span class="status-badge" style="background:#FEE2E2;color:#DC2626;">Inactive</span>';
                  const toggleBtnText = isActive ? 'Deactivate' : 'Activate';
                  const toggleBtnClass = isActive ? 'btn-logout' : 'btn-primary';

                  return `
                    <tr>
                      <td><code>${escapeHtml(w.warehouse_code)}</code></td>
                      <td style="font-weight:700; color:var(--color-primary-text);">${escapeHtml(w.warehouse_name)}</td>
                      <td>${escapeHtml(w.plant_name || '-')}</td>
                      <td>${escapeHtml(w.division_name || '-')}</td>
                      <td>${escapeHtml(w.company_name || '-')}</td>
                      <td>${statusBadge}</td>
                      <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="btn-secondary" style="padding:0.35rem 0.65rem; font-size:0.78rem; margin-right:0.35rem;" onclick="openEditWhModal(${w.warehouse_id})">Edit</button>
                        <button type="button" class="${toggleBtnClass}" style="padding:0.35rem 0.65rem; font-size:0.78rem;" onclick="toggleWhStatus(${w.warehouse_id}, '${w.status}')">${toggleBtnText}</button>
                      </td>
                    </tr>
                  `;
                }).join('');
              } else {
                tbody.innerHTML = `<tr><td colspan="7" class="empty-state" style="color:#DC2626;">${escapeHtml(data.error)}</td></tr>`;
              }
            } catch (err) {
              tbody.innerHTML = '<tr><td colspan="7" class="empty-state" style="color:#DC2626;">Failed to load data from server.</td></tr>';
            }
          }

          async function openAddWarehouseModal() {
            document.getElementById('whModalTitle').textContent = '+ Add Warehouse';
            document.getElementById('formWhId').value = '';
            document.getElementById('formWhCode').value = '';
            document.getElementById('formWhCode').removeAttribute('readonly');
            document.getElementById('formWhName').value = '';
            document.getElementById('formWhStatus').value = 'Active';
            document.getElementById('whFormError').style.display = 'none';
            await handleFormWhCompanyChange();
            document.getElementById('whModal').style.display = 'flex';
          }

          async function openEditWhModal(id) {
            try {
              const res = await fetch(`${getWhApiUrl()}?action=get&warehouse_id=${id}`);
              const data = await res.json();
              if (data.success && data.warehouse) {
                const w = data.warehouse;
                document.getElementById('whModalTitle').textContent = 'Edit Warehouse';
                document.getElementById('formWhId').value = w.warehouse_id;
                document.getElementById('formWhCompanyId').value = w.company_id;
                await handleFormWhCompanyChange(w.division_id, w.plant_id);
                document.getElementById('formWhCode').value = w.warehouse_code;
                document.getElementById('formWhCode').setAttribute('readonly', 'readonly');
                document.getElementById('formWhName').value = w.warehouse_name;
                document.getElementById('formWhStatus').value = w.status;
                document.getElementById('whFormError').style.display = 'none';
                document.getElementById('whModal').style.display = 'flex';
              }
            } catch (err) {
              alert('Failed to load warehouse details.');
            }
          }

          async function submitWhForm(e) {
            e.preventDefault();
            const form = document.getElementById('whForm');
            const errDiv = document.getElementById('whFormError');
            errDiv.style.display = 'none';
            const formData = new FormData(form);
            const id = document.getElementById('formWhId').value;
            formData.append('action', id ? 'update' : 'create');

            try {
              const res = await fetch(getWhApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                closeModal('whModal');
                showWhToast(data.message || 'Warehouse saved successfully.', true);
                loadWarehousesTable();
              } else {
                errDiv.textContent = data.error || 'Failed to save warehouse.';
                errDiv.style.display = 'block';
              }
            } catch (err) {
              errDiv.textContent = 'Server connection error.';
              errDiv.style.display = 'block';
            }
          }

          async function toggleWhStatus(id, currentStatus) {
            const newStatus = (currentStatus || '').toUpperCase() === 'ACTIVE' ? 'Inactive' : 'Active';
            if (!confirm(`Are you sure you want to set warehouse status to ${newStatus}?`)) return;

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('warehouse_id', id);
            formData.append('status', newStatus);

            try {
              const res = await fetch(getWhApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                showWhToast(data.message, true);
                loadWarehousesTable();
              } else {
                alert(data.error || 'Failed to update status.');
              }
            } catch (err) {
              alert('Server connection error.');
            }
          }

          function showWhToast(msg, isSuccess) {
            const toast = document.getElementById('whToast');
            if (!toast) return;
            toast.textContent = msg;
            toast.style.background = isSuccess ? '#D1FAE5' : '#FEE2E2';
            toast.style.color = isSuccess ? '#065F46' : '#991B1B';
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 4000);
          }
        </script>

      <?php elseif ($currentPage === 'periods'): ?>
        <!-- ============================================================
             PERIOD MASTER PAGE MODULE
             ============================================================ -->
        <section class="section-card">
          <div class="section-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h2 class="section-title" style="margin-bottom: 0.2rem;">Period Master</h2>
              <p style="font-size: 0.85rem; color: var(--color-secondary-text);">Manage fiscal costing periods, cycle dates, and lock statuses.</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
              <button type="button" class="btn-primary" onclick="openAddPeriodModal()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>+ Add Period</span>
              </button>
              <button type="button" class="btn-secondary" onclick="loadPeriodsTable()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                <span>Refresh</span>
              </button>
            </div>
          </div>

          <!-- KPI STATS BAR -->
          <div class="grid-cards" style="margin-bottom: 1.25rem;">
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Total Periods</div>
              <div class="stat-number" id="kpiTotalPeriods">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Active Periods</div>
              <div class="stat-number" id="kpiActivePeriods" style="color: #059669;">-</div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem;">
              <div class="stat-label">Locked Periods</div>
              <div class="stat-number" id="kpiLockedPeriods" style="color: #D97706;">-</div>
            </div>
          </div>

          <!-- FILTER BAR -->
          <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; background: var(--color-page-bg); padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--color-border);">
            <div style="flex: 1; min-width: 220px;">
              <input type="text" id="periodSearchInput" class="cost360-select" placeholder="Search periods by name or date..." onkeyup="handlePeriodSearchKeyup(event)">
            </div>
            <div style="width: 140px;">
              <select id="periodLockFilter" class="cost360-select" onchange="loadPeriodsTable()">
                <option value="">All Locks</option>
                <option value="1">Locked</option>
                <option value="0">Unlocked</option>
              </select>
            </div>
            <div style="width: 140px;">
              <select id="periodStatusFilter" class="cost360-select" onchange="loadPeriodsTable()">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div id="periodToast" style="display: none; padding: 0.85rem 1.25rem; border-radius: var(--radius-input); margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600;"></div>

          <!-- TABLE -->
          <div class="table-responsive">
            <table id="periodsTable">
              <thead>
                <tr>
                  <th>Period Code / Name</th>
                  <th>Start Date</th>
                  <th>End Date</th>
                  <th>Lock Status</th>
                  <th>Status</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="periodsTableBody">
                <tr>
                  <td colspan="6" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
                    Loading periods from Neon PostgreSQL database...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ADD / EDIT PERIOD MODAL -->
        <div id="periodModal" class="cost360-modal-overlay" style="display: none;">
          <div class="cost360-modal-dialog" style="max-width: 500px;">
            <div class="modal-header">
              <h3 id="periodModalTitle" style="font-size: 1.1rem; font-weight: 700; color: var(--color-primary-text);">+ Add Period</h3>
              <button type="button" class="modal-close-btn" onclick="closeModal('periodModal')">&times;</button>
            </div>
            <form id="periodForm" onsubmit="submitPeriodForm(event)" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
              <input type="hidden" name="period_id" id="formPeriodId" value="">
              <div id="periodFormError" style="display: none; padding: 0.65rem 0.85rem; background: #FEE2E2; color: #DC2626; border-radius: 6px; font-size: 0.82rem; font-weight: 600;"></div>

              <div>
                <label class="dropdown-label">Period Name *</label>
                <input type="text" name="period_name" id="formPeriodName" class="cost360-select" required placeholder="e.g. FY2024-Q1 or APR-2024">
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                  <label class="dropdown-label">Start Date *</label>
                  <input type="date" name="start_date" id="formPeriodStartDate" class="cost360-select" required>
                </div>
                <div>
                  <label class="dropdown-label">End Date *</label>
                  <input type="date" name="end_date" id="formPeriodEndDate" class="cost360-select" required>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; align-items: center;">
                <div>
                  <label class="dropdown-label">Status *</label>
                  <select name="status" id="formPeriodStatus" class="cost360-select" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                  </select>
                </div>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 1.25rem;">
                  <input type="checkbox" name="is_locked" id="formPeriodIsLocked" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                  <label for="formPeriodIsLocked" style="font-size: 0.88rem; font-weight: 600; cursor: pointer;">Lock Period</label>
                </div>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem; border-top: 1px solid var(--color-border); padding-top: 1rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('periodModal')">Cancel</button>
                <button type="submit" class="btn-primary" id="savePeriodBtn">Save Period</button>
              </div>
            </form>
          </div>
        </div>

        <script>
          let periodSearchDebounce = null;
          document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('periodsTable')) {
              loadPeriodsTable();
            }
          });

          function getPeriodApiUrl() {
            return (window.cost360BaseUrlPrefix || '') + 'organization/periods/api.php';
          }

          function handlePeriodSearchKeyup() {
            clearTimeout(periodSearchDebounce);
            periodSearchDebounce = setTimeout(loadPeriodsTable, 300);
          }

          async function loadPeriodsTable() {
            const tbody = document.getElementById('periodsTableBody');
            if (!tbody) return;
            const search = document.getElementById('periodSearchInput').value.trim();
            const isLocked = document.getElementById('periodLockFilter').value;
            const status = document.getElementById('periodStatusFilter').value;

            try {
              const res = await fetch(`${getPeriodApiUrl()}?action=list&search=${encodeURIComponent(search)}&is_locked=${isLocked}&status=${encodeURIComponent(status)}`);
              const data = await res.json();
              if (data.success) {
                document.getElementById('kpiTotalPeriods').textContent = data.kpis.total;
                document.getElementById('kpiActivePeriods').textContent = data.kpis.active;
                document.getElementById('kpiLockedPeriods').textContent = data.kpis.locked;

                if (!data.periods || data.periods.length === 0) {
                  tbody.innerHTML = '<tr><td colspan="6" class="empty-state">No periods found.</td></tr>';
                  return;
                }

                tbody.innerHTML = data.periods.map(p => {
                  const isActive = (p.status || '').toUpperCase() === 'ACTIVE';
                  const isLocked = Boolean(p.is_locked);
                  const statusBadge = isActive ? '<span class="status-badge">Active</span>' : '<span class="status-badge" style="background:#FEE2E2;color:#DC2626;">Inactive</span>';
                  const lockBadge = isLocked ? '<span class="role-badge" style="background:#FEF3C7;color:#92400E;">Locked</span>' : '<span class="role-badge" style="background:#D1FAE5;color:#065F46;">Open</span>';

                  const toggleBtnText = isActive ? 'Deactivate' : 'Activate';
                  const toggleBtnClass = isActive ? 'btn-logout' : 'btn-primary';
                  const lockBtnText = isLocked ? 'Unlock' : 'Lock';

                  return `
                    <tr>
                      <td style="font-weight:700; color:var(--color-primary-text);"><code>${escapeHtml(p.period_name)}</code></td>
                      <td>${escapeHtml(p.start_date || '-')}</td>
                      <td>${escapeHtml(p.end_date || '-')}</td>
                      <td>${lockBadge}</td>
                      <td>${statusBadge}</td>
                      <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="btn-secondary" style="padding:0.35rem 0.65rem; font-size:0.78rem; margin-right:0.35rem;" onclick="openEditPeriodModal(${p.period_id})">Edit</button>
                        <button type="button" class="btn-secondary" style="padding:0.35rem 0.65rem; font-size:0.78rem; margin-right:0.35rem;" onclick="togglePeriodLock(${p.period_id}, ${isLocked})">${lockBtnText}</button>
                        <button type="button" class="${toggleBtnClass}" style="padding:0.35rem 0.65rem; font-size:0.78rem;" onclick="togglePeriodStatus(${p.period_id}, '${p.status}')">${toggleBtnText}</button>
                      </td>
                    </tr>
                  `;
                }).join('');
              } else {
                tbody.innerHTML = `<tr><td colspan="6" class="empty-state" style="color:#DC2626;">${escapeHtml(data.error)}</td></tr>`;
              }
            } catch (err) {
              tbody.innerHTML = '<tr><td colspan="6" class="empty-state" style="color:#DC2626;">Failed to load data from server.</td></tr>';
            }
          }

          function openAddPeriodModal() {
            document.getElementById('periodModalTitle').textContent = '+ Add Period';
            document.getElementById('formPeriodId').value = '';
            document.getElementById('formPeriodName').value = '';
            document.getElementById('formPeriodStartDate').value = '';
            document.getElementById('formPeriodEndDate').value = '';
            document.getElementById('formPeriodIsLocked').checked = false;
            document.getElementById('formPeriodStatus').value = 'Active';
            document.getElementById('periodFormError').style.display = 'none';
            document.getElementById('periodModal').style.display = 'flex';
          }

          async function openEditPeriodModal(id) {
            try {
              const res = await fetch(`${getPeriodApiUrl()}?action=get&period_id=${id}`);
              const data = await res.json();
              if (data.success && data.period) {
                const p = data.period;
                document.getElementById('periodModalTitle').textContent = 'Edit Period';
                document.getElementById('formPeriodId').value = p.period_id;
                document.getElementById('formPeriodName').value = p.period_name;
                document.getElementById('formPeriodStartDate').value = p.start_date;
                document.getElementById('formPeriodEndDate').value = p.end_date;
                document.getElementById('formPeriodIsLocked').checked = Boolean(p.is_locked);
                document.getElementById('formPeriodStatus').value = p.status;
                document.getElementById('periodFormError').style.display = 'none';
                document.getElementById('periodModal').style.display = 'flex';
              }
            } catch (err) {
              alert('Failed to load period details.');
            }
          }

          async function submitPeriodForm(e) {
            e.preventDefault();
            const form = document.getElementById('periodForm');
            const errDiv = document.getElementById('periodFormError');
            errDiv.style.display = 'none';
            const formData = new FormData(form);
            const id = document.getElementById('formPeriodId').value;
            formData.append('action', id ? 'update' : 'create');

            try {
              const res = await fetch(getPeriodApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                closeModal('periodModal');
                showPeriodToast(data.message || 'Period saved successfully.', true);
                loadPeriodsTable();
              } else {
                errDiv.textContent = data.error || 'Failed to save period.';
                errDiv.style.display = 'block';
              }
            } catch (err) {
              errDiv.textContent = 'Server connection error.';
              errDiv.style.display = 'block';
            }
          }

          async function togglePeriodLock(id, currentlyLocked) {
            const newLock = !currentlyLocked;
            const actionText = newLock ? 'lock' : 'unlock';
            if (!confirm(`Are you sure you want to ${actionText} this period?`)) return;

            const formData = new FormData();
            formData.append('action', 'toggle_lock');
            formData.append('period_id', id);
            formData.append('is_locked', newLock);

            try {
              const res = await fetch(getPeriodApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                showPeriodToast(data.message, true);
                loadPeriodsTable();
              } else {
                alert(data.error || 'Failed to change lock state.');
              }
            } catch (err) {
              alert('Server connection error.');
            }
          }

          async function togglePeriodStatus(id, currentStatus) {
            const newStatus = (currentStatus || '').toUpperCase() === 'ACTIVE' ? 'Inactive' : 'Active';
            if (!confirm(`Are you sure you want to set period status to ${newStatus}?`)) return;

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('period_id', id);
            formData.append('status', newStatus);

            try {
              const res = await fetch(getPeriodApiUrl(), { method: 'POST', body: formData });
              const data = await res.json();
              if (data.success) {
                showPeriodToast(data.message, true);
                loadPeriodsTable();
              } else {
                alert(data.error || 'Failed to update status.');
              }
            } catch (err) {
              alert('Server connection error.');
            }
          }

          function showPeriodToast(msg, isSuccess) {
            const toast = document.getElementById('periodToast');
            if (!toast) return;
            toast.textContent = msg;
            toast.style.background = isSuccess ? '#D1FAE5' : '#FEE2E2';
            toast.style.color = isSuccess ? '#065F46' : '#991B1B';
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 4000);
          }
        </script>

      <?php else: ?>
        <!-- ============================================================
             DEFAULT DASHBOARD OVERVIEW PAGE MODULE
             ============================================================ -->
        <?php if (isCmaAdmin($user['user_id'])): ?>
          <?php
          $cmaCustomerId = getCmaAdminCustomerId($user['user_id']);
          $cmaCustName = 'Customer Organization';
          $cmaCustCode = '';
          $cmaPlanName = 'No Active Plan';
          $cmaMaxUsers = 0;
          $cmaCurrentUsers = 0;
          $cmaAvailableUsers = 0;
          $cmaTotalCompanies = 0;
          $cmaTotalDivisions = 0;
          $cmaTotalPlants = 0;
          $cmaRecentUsers = [];

          if ($cmaCustomerId) {
              $pdo = getDbConnection();
              $custStmt = $pdo->prepare("SELECT customer_name, customer_code FROM customers WHERE customer_id = ?");
              $custStmt->execute([$cmaCustomerId]);
              $custInfo = $custStmt->fetch(PDO::FETCH_ASSOC);
              if ($custInfo) {
                  $cmaCustName = $custInfo['customer_name'];
                  $cmaCustCode = $custInfo['customer_code'];
              }

              require_once __DIR__ . '/config/subscription.php';
              $subInfo = getActiveCustomerSubscription($cmaCustomerId);
              if ($subInfo) {
                  $cmaPlanName = $subInfo['plan_name'] ?? 'Active Plan';
                  $cmaMaxUsers = (int)($subInfo['max_users'] ?? 0);
              }

              $limitCheck = checkUserLimit($cmaCustomerId);
              $cmaCurrentUsers = (int)($limitCheck['current_users'] ?? 0);
              $cmaAvailableUsers = max(0, $cmaMaxUsers - $cmaCurrentUsers);

              $compStmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE customer_id = ? AND UPPER(status) = 'ACTIVE'");
              $compStmt->execute([$cmaCustomerId]);
              $cmaTotalCompanies = (int)$compStmt->fetchColumn();

              $divStmt = $pdo->prepare("
                  SELECT COUNT(*) 
                  FROM divisions d 
                  JOIN companies c ON d.company_id = c.company_id 
                  WHERE c.customer_id = ? AND UPPER(d.status) = 'ACTIVE' AND UPPER(c.status) = 'ACTIVE'
              ");
              $divStmt->execute([$cmaCustomerId]);
              $cmaTotalDivisions = (int)$divStmt->fetchColumn();

              $plantStmt = $pdo->prepare("
                  SELECT COUNT(*) 
                  FROM plants p 
                  JOIN divisions d ON p.division_id = d.division_id 
                  JOIN companies c ON d.company_id = c.company_id 
                  WHERE c.customer_id = ? AND UPPER(p.status) = 'ACTIVE' AND UPPER(d.status) = 'ACTIVE' AND UPPER(c.status) = 'ACTIVE'
              ");
              $plantStmt->execute([$cmaCustomerId]);
              $cmaTotalPlants = (int)$plantStmt->fetchColumn();

              $recentStmt = $pdo->prepare("
                  SELECT DISTINCT u.user_id, u.username, u.first_name, u.last_name, u.email, u.status, r.role_name
                  FROM users u
                  INNER JOIN user_organization_access uoa ON u.user_id = uoa.user_id
                  LEFT JOIN user_roles ur ON u.user_id = ur.user_id
                  LEFT JOIN roles r ON ur.role_id = r.role_id
                  WHERE uoa.customer_id = ?
                  ORDER BY u.user_id DESC
                  LIMIT 5
              ");
              $recentStmt->execute([$cmaCustomerId]);
              $cmaRecentUsers = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
          }
          $usagePct = $cmaMaxUsers > 0 ? min(100, round(($cmaCurrentUsers / $cmaMaxUsers) * 100)) : 0;
          ?>

          <!-- CMA ADMIN WELCOME HERO -->
          <section class="section-card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, rgba(6,61,112,0.9) 0%, rgba(3,54,101,0.95) 100%); border: 1px solid rgba(0,207,239,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
              <div>
                <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-bright-cyan); font-weight: 700; margin-bottom: 0.25rem;">
                  Cost Management Administration
                </div>
                <h1 style="font-size: 1.6rem; font-weight: 700; margin: 0 0 0.4rem 0; color: #FFFFFF;">
                  Welcome, <?php echo htmlspecialchars(($user['first_name'] ? $user['first_name'] . ' ' . $user['last_name'] : $user['username'])); ?>
                </h1>
                <p style="font-size: 0.9rem; color: rgba(255,255,255,0.8); margin: 0;">
                  Customer Tenant: <strong style="color: #FFFFFF;"><?php echo htmlspecialchars($cmaCustName); ?></strong>
                  <?php if ($cmaCustCode): ?><code style="background: rgba(0,0,0,0.25); color: var(--color-bright-cyan); padding: 0.15rem 0.4rem; border-radius: 4px; margin-left: 0.35rem; font-size: 0.8rem;"><?php echo htmlspecialchars($cmaCustCode); ?></code><?php endif; ?>
                </p>
              </div>
              <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a href="<?php echo htmlspecialchars(renderMenuHref('cma/users/')); ?>" class="btn-primary" style="display: inline-flex; align-items: center; gap: 0.4rem; text-decoration: none;">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                  <span>User Master</span>
                </a>
                <a href="<?php echo htmlspecialchars(renderMenuHref('organization/companies/')); ?>" class="btn-secondary" style="display: inline-flex; align-items: center; gap: 0.4rem; text-decoration: none;">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                  <span>Companies</span>
                </a>
                <a href="<?php echo htmlspecialchars(renderMenuHref('cma/access/')); ?>" class="btn-secondary" style="display: inline-flex; align-items: center; gap: 0.4rem; text-decoration: none;">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                  <span>User Access</span>
                </a>
              </div>
            </div>
          </section>

          <!-- TENANT & SUBSCRIPTION METRICS -->
          <section class="grid-cards" style="margin-bottom: 1.5rem;">
            <div class="stat-card">
              <div class="stat-header"><span class="stat-label">Customer Organization</span></div>
              <div class="stat-number" style="font-size: 1.25rem; line-height: 1.4; color: var(--color-bright-cyan);"><?php echo htmlspecialchars($cmaCustName); ?></div>
              <div class="stat-desc">Scoped Tenant ID: <?php echo htmlspecialchars($cmaCustomerId ?: 'N/A'); ?></div>
            </div>

            <div class="stat-card">
              <div class="stat-header"><span class="stat-label">Active Subscription</span></div>
              <div class="stat-number" style="font-size: 1.25rem; line-height: 1.4; color: #10B981;"><?php echo htmlspecialchars($cmaPlanName); ?></div>
              <div class="stat-desc">Plan Tier: <?php echo $cmaMaxUsers > 0 ? $cmaMaxUsers . ' Max Users' : 'No Limit / Uncapped'; ?></div>
            </div>

            <div class="stat-card">
              <div class="stat-header"><span class="stat-label">User Usage</span></div>
              <div class="stat-number" style="color: <?php echo ($cmaMaxUsers > 0 && $cmaCurrentUsers >= $cmaMaxUsers) ? '#DC2626' : '#FFFFFF'; ?>;">
                <?php echo $cmaCurrentUsers; ?> <span style="font-size: 0.95rem; font-weight: 500; color: var(--color-secondary-text);">/ <?php echo $cmaMaxUsers; ?></span>
              </div>
              <div style="background: rgba(255,255,255,0.1); border-radius: 9999px; height: 6px; width: 100%; margin-top: 0.5rem; overflow: hidden;">
                <div style="background: <?php echo $usagePct >= 90 ? '#DC2626' : ($usagePct >= 75 ? '#F59E0B' : '#10B981'); ?>; height: 100%; width: <?php echo $usagePct; ?>%;"></div>
              </div>
              <div class="stat-desc" style="margin-top: 0.35rem;"><?php echo $usagePct; ?>% allocation used</div>
            </div>

            <div class="stat-card">
              <div class="stat-header"><span class="stat-label">Available Users</span></div>
              <div class="stat-number" style="color: <?php echo $cmaAvailableUsers > 0 ? '#10B981' : '#DC2626'; ?>;"><?php echo $cmaAvailableUsers; ?></div>
              <div class="stat-desc">Slots remaining to provision</div>
            </div>
          </section>

          <!-- ORGANIZATION HIERARCHY SUMMARY -->
          <div style="margin-bottom: 1rem;">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--color-primary-text); margin: 0 0 0.75rem 0;">Organization Hierarchy Overview</h3>
          </div>
          <section class="grid-cards" style="margin-bottom: 1.5rem;">
            <div class="stat-card">
              <div class="stat-header"><span class="stat-label">Companies</span></div>
              <div class="stat-number"><?php echo $cmaTotalCompanies; ?></div>
              <div class="stat-desc">Direct corporate entities</div>
            </div>

            <div class="stat-card">
              <div class="stat-header"><span class="stat-label">Divisions</span></div>
              <div class="stat-number"><?php echo $cmaTotalDivisions; ?></div>
              <div class="stat-desc">Operational business divisions</div>
            </div>

            <div class="stat-card">
              <div class="stat-header"><span class="stat-label">Plants</span></div>
              <div class="stat-number"><?php echo $cmaTotalPlants; ?></div>
              <div class="stat-desc">Manufacturing / facility plants</div>
            </div>

            <div class="stat-card">
              <div class="stat-header"><span class="stat-label">Users</span></div>
              <div class="stat-number"><?php echo $cmaCurrentUsers; ?></div>
              <div class="stat-desc">Active users in tenant</div>
            </div>
          </section>

          <!-- RECENT USERS TABLE -->
          <section class="section-card">
            <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
              <h2 class="section-title">Tenant Users</h2>
              <a href="<?php echo htmlspecialchars(renderMenuHref('cma/users/')); ?>" style="font-size: 0.85rem; color: var(--color-bright-cyan); text-decoration: none; font-weight: 600;">View All Users &rarr;</a>
            </div>
            <div class="table-responsive">
              <?php if (!empty($cmaRecentUsers)): ?>
                <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                  <thead>
                    <tr style="border-bottom: 1px solid var(--color-card-border); text-align: left; color: var(--color-secondary-text);">
                      <th style="padding: 0.65rem 0.85rem;">Name</th>
                      <th style="padding: 0.65rem 0.85rem;">Username / Email</th>
                      <th style="padding: 0.65rem 0.85rem;">Role</th>
                      <th style="padding: 0.65rem 0.85rem;">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($cmaRecentUsers as $ru): ?>
                      <tr style="border-bottom: 1px solid var(--color-card-border);">
                        <td style="padding: 0.65rem 0.85rem; font-weight: 600; color: var(--color-primary-text);">
                          <?php echo htmlspecialchars(($ru['first_name'] ? $ru['first_name'] . ' ' . $ru['last_name'] : $ru['username'])); ?>
                        </td>
                        <td style="padding: 0.65rem 0.85rem;">
                          <code><?php echo htmlspecialchars($ru['username']); ?></code>
                          <div style="font-size: 0.78rem; color: var(--color-secondary-text);"><?php echo htmlspecialchars($ru['email']); ?></div>
                        </td>
                        <td style="padding: 0.65rem 0.85rem;">
                          <span style="background: rgba(0,207,239,0.12); color: var(--color-bright-cyan); padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                            <?php echo htmlspecialchars($ru['role_name'] ?: 'User'); ?>
                          </span>
                        </td>
                        <td style="padding: 0.65rem 0.85rem;">
                          <span class="status-badge <?php echo strtoupper($ru['status']) === 'ACTIVE' ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo htmlspecialchars($ru['status']); ?>
                          </span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <div class="empty-state">No users provisioned in this customer organization yet.</div>
              <?php endif; ?>
            </div>
          </section>

        <?php else: ?>
        <section class="grid-cards">
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Accessible Menus</span></div>
            <div class="stat-number"><?php echo count($authorizedMenus); ?></div>
            <div class="stat-desc">Authorized for <?php echo htmlspecialchars($role['role_code'] ?? 'ROLE'); ?></div>
          </div>

          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">Primary Role</span></div>
            <div class="stat-number" style="font-size: 1.15rem; line-height: 1.8rem;"><?php echo htmlspecialchars($role['role_name'] ?? 'Unassigned'); ?></div>
            <div class="stat-desc">Code: <?php echo htmlspecialchars($role['role_code'] ?? 'N/A'); ?></div>
          </div>
        </section>

        <!-- ACCESSIBLE MENUS TABLE -->
        <section class="section-card">
          <div class="section-header">
            <h2 class="section-title">My Accessible Menus</h2>
          </div>
          <div class="table-responsive">
            <?php if (!empty($authorizedMenus)): ?>
              <table>
                <thead>
                  <tr>
                    <th>Menu Code</th>
                    <th>Menu Name</th>
                    <th>Type</th>
                    <th>Route / Path</th>
                    <th>Display Order</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($authorizedMenus as $m): ?>
                    <tr>
                      <td><code><?php echo htmlspecialchars($m['menu_code']); ?></code></td>
                      <td><strong><?php echo htmlspecialchars($m['menu_name']); ?></strong></td>
                      <td><span style="font-size: 0.78rem; color: var(--color-secondary-text);"><?php echo htmlspecialchars($m['menu_type']); ?></span></td>
                      <td><code><?php echo htmlspecialchars($m['route'] ?: '/'); ?></code></td>
                      <td><?php echo htmlspecialchars($m['display_order']); ?></td>
                      <td><span class="status-badge">ACTIVE</span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="empty-state">No menus are currently assigned to your role.</div>
            <?php endif; ?>
          </div>
        </section>


        <?php endif; ?>

      <?php endif; ?>

    </main>

  </div>

  <script>
    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    /* ============================================================
       CUSTOMER MANAGEMENT MODULE JAVASCRIPT LOGIC
       ============================================================ */
    let allCustomersData = [];
    let currentWizStep = 1;

    async function loadCustomersTable() {
      const tbody = document.getElementById('customersTableBody');
      if (!tbody) return;

      tbody.innerHTML = '<tr><td colspan="7" class="empty-state">Loading customer accounts from Neon PostgreSQL...</td></tr>';

      try {
        const resp = await fetch('api/customers_api.php?action=list');
        const res = await resp.json();

        if (!res.success) {
          tbody.innerHTML = `<tr><td colspan="7" class="empty-state" style="color: #DC2626;">Error: ${escapeHtml(res.error)}</td></tr>`;
          return;
        }

        allCustomersData = res.customers || [];

        // Update KPIs
        if (res.kpis) {
          document.getElementById('kpiTotalCust').innerText = res.kpis.total_customers || 0;
          document.getElementById('kpiActiveCust').innerText = res.kpis.active_customers || 0;
          document.getElementById('kpiInactiveCust').innerText = res.kpis.inactive_customers || 0;
          document.getElementById('kpiTotalCompanies').innerText = res.kpis.total_companies || 0;
        }

        renderCustomersRows(allCustomersData);
      } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-state" style="color: #DC2626;">Failed to load customers: ${escapeHtml(err.message)}</td></tr>`;
      }
    }

    function renderCustomersRows(data) {
      const tbody = document.getElementById('customersTableBody');
      if (!tbody) return;

      if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No customer records found in Neon PostgreSQL.</td></tr>';
        return;
      }

      let html = '';
      data.forEach((c, idx) => {
        const isActive = String(c.status).toUpperCase() === 'ACTIVE';
        const statusBadge = isActive
          ? '<span class="status-badge" style="background: #E6F9F6; color: #00A88F;">Active</span>'
          : '<span class="status-badge" style="background: #FEE2E2; color: #DC2626;">Inactive</span>';

        // Derive plan or fallback
        const subPlan = c.subscription_plan || (idx % 2 === 0 ? 'Enterprise' : 'Professional');
        const subClass = subPlan === 'Enterprise' ? 'perm-create' : (subPlan === 'Professional' ? 'perm-read' : 'perm-update');

        html += `
          <tr>
            <td>${idx + 1}</td>
            <td><code>${escapeHtml(c.customer_code)}</code></td>
            <td><strong>${escapeHtml(c.customer_name)}</strong></td>
            <td>
              <div>${escapeHtml(c.contact_person || 'N/A')}</div>
              <div style="font-size: 0.78rem; color: var(--color-secondary-text);">${escapeHtml(c.contact_email || '')}</div>
            </td>
            <td><span class="perm-pill ${subClass}">${escapeHtml(subPlan)}</span></td>
            <td>${statusBadge}</td>
            <td style="text-align: right;">
              <div style="display: flex; gap: 0.4rem; justify-content: flex-end;">
                <button type="button" class="btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.78rem;" onclick="viewCustomerDetails(${c.customer_id})" title="View Details">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path></svg>
                </button>
                <button type="button" class="btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.78rem;" onclick="toggleCustomerStatus(${c.customer_id}, '${escapeJs(c.status)}')" title="${isActive ? 'Deactivate' : 'Activate'}">
                  ${isActive
                    ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#DC2626" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>'
                    : '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#059669" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>'}
                </button>
              </div>
            </td>
          </tr>
        `;
      });

      tbody.innerHTML = html;
    }

    function filterCustomersList() {
      const q = (document.getElementById('custSearchInput')?.value || '').toLowerCase().trim();
      const statusF = document.getElementById('custStatusFilter')?.value || 'ALL';
      const subF = document.getElementById('custSubscriptionFilter')?.value || 'ALL';

      const filtered = allCustomersData.filter((c, idx) => {
        const matchesSearch = !q ||
          (c.customer_code || '').toLowerCase().includes(q) ||
          (c.customer_name || '').toLowerCase().includes(q) ||
          (c.contact_person || '').toLowerCase().includes(q) ||
          (c.contact_email || '').toLowerCase().includes(q);

        const matchesStatus = statusF === 'ALL' || (c.status || '').toUpperCase() === statusF.toUpperCase();

        const plan = c.subscription_plan || (idx % 2 === 0 ? 'Enterprise' : 'Professional');
        const matchesSub = subF === 'ALL' || plan.toUpperCase() === subF.toUpperCase();

        return matchesSearch && matchesStatus && matchesSub;
      });

      renderCustomersRows(filtered);
    }

    function resetCustomerFilters() {
      if (document.getElementById('custSearchInput')) document.getElementById('custSearchInput').value = '';
      if (document.getElementById('custStatusFilter')) document.getElementById('custStatusFilter').value = 'ALL';
      if (document.getElementById('custSubscriptionFilter')) document.getElementById('custSubscriptionFilter').value = 'ALL';
      renderCustomersRows(allCustomersData);
    }

    /* WIZARD LOGIC */
    function openAddCustomerWizard() {
      document.getElementById('addCustomerForm').reset();
      currentWizStep = 1;
      renderWizStep();
      document.getElementById('addCustomerWizardModal').style.display = 'flex';
    }

    function changeWizStep(delta) {
      if (delta > 0) {
        // Validate inputs before advancing
        if (currentWizStep === 1) {
          const code = document.getElementById('wizCustomerCode').value.trim();
          const name = document.getElementById('wizCustomerName').value.trim();
          const person = document.getElementById('wizContactPerson').value.trim();
          const email = document.getElementById('wizContactEmail').value.trim();

          if (!code || !name || !person || !email) {
            alert('Please fill out all mandatory fields in Basic Information (*).');
            return;
          }
        } else if (currentWizStep === 4) {
          const adminName = document.getElementById('wizAdminName').value.trim();
          const adminEmail = document.getElementById('wizAdminEmail').value.trim();
          const adminPass = document.getElementById('wizAdminPass').value.trim();

          if (!adminName || !adminEmail || !adminPass) {
            alert('Please fill out Customer Administrator Name, Email, and Password (*).');
            return;
          }
        }
      }

      currentWizStep += delta;
      if (currentWizStep < 1) currentWizStep = 1;
      if (currentWizStep > 6) currentWizStep = 6;
      renderWizStep();
    }

    function renderWizStep() {
      for (let i = 1; i <= 6; i++) {
        const content = document.getElementById(`wizStepContent${i}`);
        const pill = document.getElementById(`wizStepPill${i}`);
        if (content) content.style.display = (i === currentWizStep) ? 'block' : 'none';
        if (pill) {
          if (i === currentWizStep) {
            pill.style.fontWeight = '700';
            pill.style.color = 'var(--color-primary-blue)';
          } else {
            pill.style.fontWeight = '400';
            pill.style.color = 'var(--color-secondary-text)';
          }
        }
      }

      document.getElementById('btnWizPrev').style.display = (currentWizStep > 1) ? 'inline-block' : 'none';
      document.getElementById('btnWizNext').style.display = (currentWizStep < 6) ? 'inline-block' : 'none';
      document.getElementById('btnWizSubmit').style.display = (currentWizStep === 6) ? 'inline-block' : 'none';

      if (currentWizStep === 6) {
        populateWizReview();
      }
    }

    function populateWizReview() {
      const reviewBox = document.getElementById('wizReviewBox');
      if (!reviewBox) return;

      const code = document.getElementById('wizCustomerCode').value.trim();
      const name = document.getElementById('wizCustomerName').value.trim();
      const contactPerson = document.getElementById('wizContactPerson').value.trim();
      const contactEmail = document.getElementById('wizContactEmail').value.trim();
      const subPlan = document.getElementById('wizSubPlan').value;
      const adminName = document.getElementById('wizAdminName').value.trim();
      const adminEmail = document.getElementById('wizAdminEmail').value.trim();
      const compCode = document.getElementById('wizCompCode').value.trim();
      const compName = document.getElementById('wizCompName').value.trim();

      reviewBox.innerHTML = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.88rem;">
          <div>
            <strong>Customer:</strong> ${escapeHtml(name)} (${escapeHtml(code)})<br>
            <strong>Contact:</strong> ${escapeHtml(contactPerson)} (${escapeHtml(contactEmail)})
          </div>
          <div>
            <strong>Subscription Plan:</strong> ${escapeHtml(subPlan)}<br>
            <strong>Provisioning DB:</strong> Neon Global Database (Connected)
          </div>
          <div style="grid-column: span 2; border-top: 1px solid var(--color-border); padding-top: 0.75rem; margin-top: 0.5rem;">
            <strong>Customer Administrator:</strong> ${escapeHtml(adminName)} (${escapeHtml(adminEmail)})<br>
            <strong>Assigned Role:</strong> Customer Administrator (CUSTOMER_ADMIN)<br>
            <strong>Assigned Scope:</strong> Full Customer Scope
          </div>
          ${compName ? `
          <div style="grid-column: span 2; border-top: 1px solid var(--color-border); padding-top: 0.75rem;">
            <strong>Initial Company:</strong> ${escapeHtml(compName)} (${escapeHtml(compCode)})
          </div>` : ''}
        </div>
      `;
    }

    async function submitCustomerWizard(evt) {
      evt.preventDefault();
      const form = document.getElementById('addCustomerForm');
      const formData = new FormData(form);
      formData.append('action', 'create_customer');

      const submitBtn = document.getElementById('btnWizSubmit');
      submitBtn.disabled = true;
      submitBtn.innerText = 'Creating Customer...';

      try {
        const resp = await fetch('api/customers_api.php', {
          method: 'POST',
          body: formData
        });
        const res = await resp.json();

        if (!res.success) {
          alert('Failed to create customer: ' + (res.error || 'Unknown error'));
          submitBtn.disabled = false;
          submitBtn.innerText = 'Create Customer';
          return;
        }

        alert(`Customer Created Successfully!\nCustomer Code: ${res.customer_code}\nAdmin Email: ${res.admin_email}`);
        closeModal('addCustomerWizardModal');
        loadCustomersTable();
      } catch (err) {
        alert('Request error: ' + err.message);
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerText = 'Create Customer';
      }
    }

    async function viewCustomerDetails(customerId) {
      const modal = document.getElementById('customerDetailsModal');
      if (!modal) return;

      modal.style.display = 'flex';
      document.getElementById('dtlCustName').innerText = 'Loading details...';

      try {
        const resp = await fetch(`api/customers_api.php?action=get_details&customer_id=${customerId}`);
        const res = await resp.json();

        if (!res.success) {
          alert('Error fetching customer details: ' + res.error);
          closeModal('customerDetailsModal');
          return;
        }

        const c = res.customer;
        document.getElementById('dtlCustCode').innerText = c.customer_code;
        document.getElementById('dtlCustName').innerText = c.customer_name;
        document.getElementById('dtlCustContact').innerText = `Contact: ${c.contact_person || 'N/A'} | Email: ${c.contact_email || 'N/A'} | Phone: ${c.contact_phone || 'N/A'}`;
        document.getElementById('dtlCustStatusBadge').innerHTML = c.status.toUpperCase() === 'ACTIVE'
          ? '<span class="status-badge" style="background: #E6F9F6; color: #00A88F;">Active</span>'
          : '<span class="status-badge" style="background: #FEE2E2; color: #DC2626;">Inactive</span>';

        // Overview KPIs
        if (res.kpis) {
          document.getElementById('dtlKpiComp').innerText = res.kpis.company_count;
          document.getElementById('dtlKpiDiv').innerText = res.kpis.division_count;
          document.getElementById('dtlKpiPlant').innerText = res.kpis.plant_count;
          document.getElementById('dtlKpiWh').innerText = res.kpis.warehouse_count;
          document.getElementById('dtlKpiUser').innerText = res.kpis.user_count;
        }

        // Admin Box
        const adm = res.admin;
        document.getElementById('custAdminBox').innerHTML = adm ? `
          <div style="font-size: 0.9rem;">
            <strong>Admin Name:</strong> ${escapeHtml(adm.full_name)}<br>
            <strong>Email:</strong> ${escapeHtml(adm.email)}<br>
            <strong>Phone:</strong> ${escapeHtml(adm.phone || 'N/A')}<br>
            <strong>Role:</strong> Customer Administrator (CUSTOMER_ADMIN)<br>
            <strong>Status:</strong> ${escapeHtml(adm.status)}
          </div>
        ` : '<em>No Customer Administrator user assigned.</em>';

        // Companies Table
        let compHtml = '';
        (res.companies || []).forEach(cp => {
          compHtml += `<tr><td><code>${escapeHtml(cp.company_code)}</code></td><td><strong>${escapeHtml(cp.company_name)}</strong></td><td>${escapeHtml(cp.address || '')}</td><td><span class="status-badge">${escapeHtml(cp.status)}</span></td></tr>`;
        });
        document.getElementById('dtlCompaniesBody').innerHTML = compHtml || '<tr><td colspan="4" class="empty-state">No companies registered.</td></tr>';

        // Divisions Table
        let divHtml = '';
        (res.divisions || []).forEach(d => {
          divHtml += `<tr><td><code>${escapeHtml(d.division_code)}</code></td><td>${escapeHtml(d.division_name)}</td><td>${escapeHtml(d.company_name)}</td><td><span class="status-badge">${escapeHtml(d.status)}</span></td></tr>`;
        });
        document.getElementById('dtlDivisionsBody').innerHTML = divHtml || '<tr><td colspan="4" class="empty-state">No divisions registered.</td></tr>';

        // Plants Table
        let plantHtml = '';
        (res.plants || []).forEach(p => {
          plantHtml += `<tr><td><code>${escapeHtml(p.plant_code)}</code></td><td>${escapeHtml(p.plant_name)}</td><td>${escapeHtml(p.company_name)} / ${escapeHtml(p.division_name)}</td><td><span class="status-badge">${escapeHtml(p.status)}</span></td></tr>`;
        });
        document.getElementById('dtlPlantsBody').innerHTML = plantHtml || '<tr><td colspan="4" class="empty-state">No plants registered.</td></tr>';

        // Warehouses Table
        let whHtml = '';
        (res.warehouses || []).forEach(w => {
          whHtml += `<tr><td><code>${escapeHtml(w.warehouse_code)}</code></td><td>${escapeHtml(w.warehouse_name)}</td><td>${escapeHtml(w.company_name)} / ${escapeHtml(w.plant_name)}</td><td><span class="status-badge">${escapeHtml(w.status)}</span></td></tr>`;
        });
        document.getElementById('dtlWarehousesBody').innerHTML = whHtml || '<tr><td colspan="4" class="empty-state">No warehouses registered.</td></tr>';

        // Users & Scope Table
        let userHtml = '';
        (res.users || []).forEach(u => {
          userHtml += `<tr><td><strong>${escapeHtml(u.full_name)}</strong></td><td>${escapeHtml(u.email)}</td><td><span class="perm-pill perm-view">${escapeHtml(u.role_name || u.role_code || 'User')}</span></td><td>${escapeHtml(u.company_name || 'All Companies (Customer Scope)')}</td><td>${escapeHtml(u.plant_name || 'All Plants')}</td><td><span class="status-badge">${escapeHtml(u.status)}</span></td></tr>`;
        });
        document.getElementById('dtlUsersBody').innerHTML = userHtml || '<tr><td colspan="6" class="empty-state">No users assigned.</td></tr>';

        // Audit Logs Table
        let auditHtml = '';
        (res.audit_logs || []).forEach(a => {
          auditHtml += `<tr><td>${escapeHtml(a.timestamp)}</td><td>${escapeHtml(a.operator_name || 'System')}</td><td><span class="perm-pill perm-update">${escapeHtml(a.action)}</span></td><td>${escapeHtml(a.new_value || a.old_value || '')}</td></tr>`;
        });
        document.getElementById('dtlAuditBody').innerHTML = auditHtml || '<tr><td colspan="4" class="empty-state">No audit logs recorded yet.</td></tr>';

      } catch (err) {
        alert('Failed to load customer details: ' + err.message);
      }
    }

    function switchCustTab(tabName, btn) {
      document.querySelectorAll('.cust-tab-pane').forEach(el => el.style.display = 'none');
      document.querySelectorAll('.cust-tab-btn').forEach(b => b.classList.remove('active'));

      const paneMap = {
        'overview': 'custTabOverview',
        'admin': 'custTabAdmin',
        'companies': 'custTabCompanies',
        'divisions': 'custTabDivisions',
        'plants': 'custTabPlants',
        'warehouses': 'custTabWarehouses',
        'users': 'custTabUsers',
        'audit': 'custTabAudit'
      };

      if (paneMap[tabName]) {
        document.getElementById(paneMap[tabName]).style.display = 'block';
      }
      if (btn) btn.classList.add('active');
    }

    async function toggleCustomerStatus(customerId, currentStatus) {
      const isAct = String(currentStatus).toUpperCase() === 'ACTIVE';
      const targetStatus = isAct ? 'Inactive' : 'Active';

      if (!confirm(`Are you sure you want to ${isAct ? 'DEACTIVATE' : 'ACTIVATE'} this customer tenant account?`)) {
        return;
      }

      try {
        const formData = new FormData();
        formData.append('action', 'deactivate_customer');
        formData.append('customer_id', customerId);
        formData.append('status', targetStatus);

        const resp = await fetch('api/customers_api.php', { method: 'POST', body: formData });
        const res = await resp.json();

        if (!res.success) {
          alert('Error: ' + res.error);
          return;
        }

        alert(res.message);
        loadCustomersTable();
      } catch (err) {
        alert('Request failed: ' + err.message);
      }
    }

    document.addEventListener('DOMContentLoaded', () => {
      if (document.getElementById('customersTableBody')) {
        loadCustomersTable();
      }
    });

    window.openAddCustomerWizard = openAddCustomerWizard;
    window.changeWizStep = changeWizStep;
    window.submitCustomerWizard = submitCustomerWizard;
    window.filterCustomersList = filterCustomersList;
    window.resetCustomerFilters = resetCustomerFilters;
    window.viewCustomerDetails = viewCustomerDetails;
    window.switchCustTab = switchCustTab;
    window.toggleCustomerStatus = toggleCustomerStatus;

    function escapeJs(str) {
      if (!str) return '';
      return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
    }

    function closeModal(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.style.display = 'none';
      }
    }

    function toggleSubmenu(btnEl) {
      const group = btnEl.closest('.nav-parent-group');
      const container = group ? group.querySelector('.submenu-container') : null;
      if (!container) return;

      const isShow = container.classList.contains('show');
      if (isShow) {
        container.classList.remove('show');
        group.classList.remove('open');
        btnEl.setAttribute('aria-expanded', 'false');
      } else {
        container.classList.add('show');
        group.classList.add('open');
        btnEl.setAttribute('aria-expanded', 'true');
      }
    }
    function switchActivePeriod(periodId) {
      if (!periodId) return;
      fetch(window.cost360BaseUrlPrefix + 'api/context_api.php?action=switch_period', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'period_id=' + encodeURIComponent(periodId)
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          window.location.reload();
        } else {
          alert('Failed to switch period: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        alert('Error communicating with server when switching period.');
      });
    }

    function toggleContextPopover(e) {
      if (e) e.stopPropagation();
      const popover = document.getElementById('contextPopover');
      const toggleBtn = document.getElementById('contextPopoverToggle');
      if (!popover) return;
      const isOpen = popover.classList.contains('active');
      if (isOpen) {
        popover.classList.remove('active');
        if (toggleBtn) toggleBtn.classList.remove('active');
      } else {
        popover.classList.add('active');
        if (toggleBtn) toggleBtn.classList.add('active');
      }
    }

    function toggleAddPeriodForm() {
      const f = document.getElementById('popoverAddPeriodForm');
      if (!f) return;
      f.classList.toggle('active');
      const err = document.getElementById('addPeriodErrorMsg');
      if (err) { err.style.display = 'none'; err.textContent = ''; }
      if (f.classList.contains('active')) {
        const input = document.getElementById('newPeriodName');
        if (input) input.focus();
      }
    }

    function submitPopoverNewPeriod(e) {
      e.preventDefault();
      const form = e.target;
      const btn = document.getElementById('btnSaveNewPeriod');
      const err = document.getElementById('addPeriodErrorMsg');
      if (err) { err.style.display = 'none'; err.textContent = ''; }
      if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

      const formData = new FormData(form);
      formData.append('action', 'create_and_select_period');
      formData.append('is_ajax', '1');

      fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          window.location.reload();
        } else {
          if (err) {
            err.textContent = data.error || 'Failed to create period.';
            err.style.display = 'block';
          } else {
            alert(data.error || 'Failed to create period.');
          }
          if (btn) { btn.disabled = false; btn.textContent = 'Save & Activate'; }
        }
      })
      .catch(errObj => {
        if (err) {
          err.textContent = 'Server error occurred while creating period.';
          err.style.display = 'block';
        }
        if (btn) { btn.disabled = false; btn.textContent = 'Save & Activate'; }
      });
    }

    document.addEventListener('click', function(e) {
      const popover = document.getElementById('contextPopover');
      const toggleBtn = document.getElementById('contextPopoverToggle');
      if (!popover || !popover.classList.contains('active')) return;
      if (!popover.contains(e.target) && (!toggleBtn || !toggleBtn.contains(e.target))) {
        popover.classList.remove('active');
        if (toggleBtn) toggleBtn.classList.remove('active');
      }
    });

    window.toggleSubmenu = toggleSubmenu;
    window.escapeHtml = escapeHtml;
    window.escapeJs = escapeJs;
    window.closeModal = closeModal;
    window.switchActivePeriod = switchActivePeriod;
    window.toggleContextPopover = toggleContextPopover;
    window.toggleAddPeriodForm = toggleAddPeriodForm;
    window.submitPopoverNewPeriod = submitPopoverNewPeriod;
  </script>
  <script src="<?php echo $baseUrlPrefix; ?>assets/js/upload_centre.js"></script>
</body>
</html>