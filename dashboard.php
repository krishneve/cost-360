<?php
/**
 * Cost360 - Enterprise SaaS Dashboard & Integrated Upload Centre Workspace
 * 100% Database-Driven from Neon PostgreSQL auth & public schemas.
 * Color Theme & Branding: Matched to Cost360 Approved Login Page.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/upload_centre.php';

requireAuth();

$user = getAuthUser();
$role = getCurrentUserRole();
$authorizedMenus = getUserAuthorizedMenus();

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
if (preg_match('#/(?:admin|administration)/[^/]+/#', $scriptName) || 
    preg_match('#/(?:admin|administration)/[^/]+/index\.php#', $scriptName)) {
    $baseUrlPrefix = '../../';
} else if (strpos($scriptName, '/admin/') !== false || strpos($scriptName, '/administration/') !== false) {
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
        $parentOnlyRoutes = ['administration', 'customers', 'data', 'costing', 'audit', 'reports', 'settings'];
        if (in_array($lower, $parentOnlyRoutes)) {
            return 'javascript:void(0);';
        }

        // Map known routes to actual working handlers
        $routeMap = [
            'administration/users' => 'admin/users/',
            'admin/users'          => 'admin/users/',
            'administration/roles' => 'admin/roles/',
            'admin/roles'          => 'admin/roles/',
            'administration/menus' => 'admin/menus/',
            'admin/menus'          => 'admin/menus/',
            'data/upload'          => 'upload-centre.php',
            'data/mapping'         => 'mapping-centre.php',
            'data/history'         => 'import-history.php',
            'data/audit'           => 'audit-logs.php',
            'data/masters'         => 'master-groups.php',
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
    .top-header {
      height: 72px;
      background: var(--color-white);
      border-bottom: 1px solid var(--color-border);
      padding: 0 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 90;
      box-shadow: 0 2px 10px rgba(3, 54, 101, 0.03);
    }

    .page-heading {
      font-size: 1.15rem;
      font-weight: 700;
      color: var(--color-primary-text);
    }

    .user-profile-menu {
      display: flex;
      align-items: center;
      gap: 1.25rem;
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
                <span>+ Add User</span>
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
                <span>+ Add Menu</span>
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
    window.toggleSubmenu = toggleSubmenu;
    window.escapeHtml = escapeHtml;
    window.escapeJs = escapeJs;
    window.closeModal = closeModal;
  </script>
  <script src="<?php echo $baseUrlPrefix; ?>assets/js/upload_centre.js"></script>
</body>
</html>
