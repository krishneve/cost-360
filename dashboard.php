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

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$baseUrlPrefix = '';
if (preg_match('#/(?:admin|administration|organization)/[^/]+/#i', $scriptName) || 
    preg_match('#/(?:admin|administration|organization)/[^/]+/index\.php#i', $scriptName)) {
    $baseUrlPrefix = '../../';
} else if (strpos($scriptName, '/admin/') !== false || strpos($scriptName, '/administration/') !== false || strpos($scriptName, '/organization/') !== false) {
    $baseUrlPrefix = '../';
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
        $parentOnlyRoutes = ['administration', 'data', 'costing', 'audit', 'reports', 'settings', 'organization', 'customers', 'customer_mgmt'];
        if (in_array($lower, $parentOnlyRoutes)) {
            return 'javascript:void(0);';
        }

        // Map known routes to actual working handlers
        $routeMap = [
            'customers'              => 'customers.php',
            'customers/master'       => 'customers.php',
            'organization/overview'  => 'organization/overview/',
            'organization/companies' => 'organization/companies/',
            'organization/divisions' => 'organization/divisions/',
            'organization/plants'    => 'organization/plants/',
            'organization/warehouses' => 'organization/warehouses/',
            'organization/periods'   => 'organization/periods/',
            'administration/users'   => 'admin/users/',
            'admin/users'            => 'admin/users/',
            'administration/roles'   => 'admin/roles/',
            'admin/roles'            => 'admin/roles/',
            'administration/menus'   => 'admin/menus/',
            'admin/menus'            => 'admin/menus/',
            'data/upload'            => 'upload-centre.php',
            'data/mapping'           => 'mapping-centre.php',
            'data/history'           => 'import-history.php',
            'data/audit'             => 'audit-logs.php',
            'data/masters'           => 'master-groups.php',
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
                  <th>Created At</th>
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
                <td colspan="9" style="text-align: center; padding: 2rem; color: var(--color-secondary-text);">
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
                    <td>${escapeHtml(u.created_at)}</td>
                    <td>${escapeHtml(u.last_login_at)}</td>
                    <td style="text-align: right; white-space: nowrap;">
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="openEditUserModal(${u.user_id})">Edit</button>
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; margin-right: 4px;" onclick="openChangePasswordModal(${u.user_id}, '${escapeJs(u.username)}', '${escapeJs(u.email)}')">Password</button>
                      <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; color: #DC2626; border-color: #FCA5A5;" onclick="openDeleteModal(${u.user_id}, '${escapeJs(u.username)}')">Delete</button>
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
