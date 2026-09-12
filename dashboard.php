<?php
/**
 * Cost360 - Enterprise SaaS Dashboard Workspace
 * 100% Database-Driven from Neon PostgreSQL auth schema.
 * Color Theme: Matched to Cost360 Approved Login Page Palette.
 */

require_once __DIR__ . '/config/auth.php';
requireAuth();

$user = getAuthUser();
$role = getCurrentUserRole();
$authorizedMenus = getUserAuthorizedMenus();
$authorizedPermissions = getUserAuthorizedPermissions();

// Calculate permission category counts dynamically from SQL result
$permCounts = [];
foreach ($authorizedPermissions as $p) {
    $code = strtoupper($p['permission_code']);
    if (!isset($permCounts[$code])) {
        $permCounts[$code] = 0;
    }
    $permCounts[$code]++;
}

// Group permissions by menu for the Access Matrix table
$menuMatrix = [];
foreach ($authorizedPermissions as $p) {
    $mName = $p['menu_name'];
    if (!isset($menuMatrix[$mName])) {
        $menuMatrix[$mName] = [];
    }
    $menuMatrix[$mName][] = [
        'code' => $p['permission_code'],
        'name' => $p['permission_name']
    ];
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cost360 - Enterprise Access Workspace</title>
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
      padding: 1.5rem 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    .sidebar-brand img {
      height: 32px;
      width: auto;
    }
    .brand-title {
      font-size: 1.2rem;
      font-weight: 800;
      background: linear-gradient(135deg, var(--color-white) 0%, var(--color-bright-cyan) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      letter-spacing: -0.02em;
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
      background: var(--color-white);
      border-bottom: 1px solid var(--color-border);
      padding: 0.9rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 90;
      box-shadow: 0 2px 8px rgba(3, 54, 101, 0.04);
    }

    .page-heading {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--color-primary-text);
    }

    .user-profile-menu {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .user-details {
      text-align: right;
    }
    .user-name {
      font-size: 0.88rem;
      font-weight: 700;
      color: var(--color-primary-text);
    }
    .user-meta {
      font-size: 0.75rem;
      color: var(--color-secondary-text);
    }

    .role-badge {
      display: inline-block;
      padding: 0.25rem 0.65rem;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 700;
      background: var(--color-soft-blue);
      color: var(--color-primary-blue);
      border: 1px solid rgba(8, 120, 209, 0.25);
    }

    .status-badge {
      display: inline-block;
      padding: 0.25rem 0.65rem;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 700;
      background: #D1FAE5;
      color: #059669;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .btn-logout {
      background: rgba(239, 68, 68, 0.08);
      color: #DC2626;
      border: 1px solid rgba(239, 68, 68, 0.2);
      padding: 0.45rem 0.95rem;
      border-radius: 6px;
      font-weight: 600;
      font-size: 0.8rem;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-logout:hover {
      background: rgba(239, 68, 68, 0.16);
      border-color: rgba(239, 68, 68, 0.4);
    }

    /* DASHBOARD CONTENT CONTAINER */
    .dashboard-container {
      padding: 2rem;
      max-width: 1400px;
      width: 100%;
      margin: 0 auto;
    }

    /* HERO WELCOME BANNER */
    .hero-welcome-card {
      background: linear-gradient(135deg, var(--color-deep-navy) 0%, var(--color-dark-blue) 55%, var(--color-primary-blue) 100%);
      border-radius: var(--radius-card);
      padding: 2rem;
      margin-bottom: 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: var(--color-white);
      box-shadow: var(--shadow-card);
    }
    .welcome-text h1 {
      font-size: 1.65rem;
      font-weight: 800;
      margin-bottom: 0.4rem;
    }
    .welcome-text p {
      color: rgba(255, 255, 255, 0.85);
      font-size: 0.92rem;
    }
    .welcome-pills {
      display: flex;
      gap: 0.75rem;
      align-items: center;
    }

    /* STAT CARDS */
    .grid-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
      gap: 1.25rem;
      margin-bottom: 2rem;
    }
    .stat-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-card);
      padding: 1.4rem;
      box-shadow: var(--shadow-card);
      transition: transform 0.2s ease, border-color 0.2s ease;
    }
    .stat-card:hover {
      transform: translateY(-2px);
      border-color: var(--color-border-hover);
    }
    .stat-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.75rem;
    }
    .stat-label {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--color-secondary-text);
      font-weight: 700;
    }
    .stat-number {
      font-size: 1.85rem;
      font-weight: 800;
      color: var(--color-primary-blue);
    }
    .stat-desc {
      font-size: 0.75rem;
      color: var(--color-secondary-text);
      margin-top: 0.25rem;
    }

    /* SECTION CARDS & TABLES */
    .section-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-card);
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: var(--shadow-card);
    }
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.25rem;
    }
    .section-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--color-primary-text);
    }
    .table-responsive {
      overflow-x: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.85rem;
    }
    th {
      background: var(--color-deep-navy);
      color: var(--color-white);
      font-weight: 700;
      text-transform: uppercase;
      font-size: 0.7rem;
      letter-spacing: 0.05em;
      padding: 0.85rem 1rem;
    }
    td {
      padding: 0.85rem 1rem;
      border-bottom: 1px solid var(--color-border);
      color: var(--color-primary-text);
    }
    tr:last-child td {
      border-bottom: none;
    }
    tr:hover td {
      background: var(--color-soft-blue);
    }

    /* PERMISSION BADGES */
    .perm-pill {
      display: inline-block;
      padding: 0.18rem 0.55rem;
      border-radius: 4px;
      font-size: 0.72rem;
      font-weight: 700;
      margin-right: 0.35rem;
      margin-bottom: 0.25rem;
    }
    .perm-view { background: #E0F2FE; color: #0284C7; border: 1px solid #BAE6FD; }
    .perm-create { background: #D1FAE5; color: #059669; border: 1px solid #A7F3D0; }
    .perm-edit { background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; }
    .perm-delete { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }
    .perm-approve { background: #F3E8FF; color: #9333EA; border: 1px solid #E9D5FF; }
    .perm-export { background: #CCFBF1; color: #0D9488; border: 1px solid #99F6E4; }

    .empty-state {
      padding: 2.5rem;
      text-align: center;
      color: var(--color-secondary-text);
      font-size: 0.9rem;
    }

    @media (max-width: 900px) {
      .sidebar {
        transform: translateX(-100%);
      }
      .app-main {
        margin-left: 0;
      }
      .hero-welcome-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
    }
  </style>
</head>
<body>

  <!-- DYNAMIC AUTHORIZED SIDEBAR FROM NEON DATABASE -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <img src="assets/images/cost360-logo.png" alt="Cost360 Logo" onerror="this.style.display='none'">
      <span class="brand-title">Cost360</span>
    </div>

    <div class="nav-section-label">Authorized Navigation</div>

    <nav class="sidebar-nav">
      <?php if (!empty($authorizedMenus)): ?>
        <?php foreach ($authorizedMenus as $m): ?>
          <a href="<?php echo htmlspecialchars($m['route'] ?: '#'); ?>" class="nav-item <?php echo (strcasecmp($m['menu_code'], 'DASHBOARD') === 0) ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <?php echo getMenuIconSvg($m['icon']); ?>
            </svg>
            <span><?php echo htmlspecialchars($m['menu_name']); ?></span>
          </a>
        <?php endforeach; ?>
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
      <div class="page-heading">Cost Management & Manufacturing Analytics</div>

      <div class="user-profile-menu">
        <div class="user-details">
          <div class="user-name"><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></div>
          <div class="user-meta"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
        </div>
        <span class="role-badge"><?php echo htmlspecialchars($role['role_name'] ?? 'No Role Assigned'); ?></span>
        <span class="status-badge"><?php echo htmlspecialchars($user['status'] ?? 'ACTIVE'); ?></span>
        <a href="logout.php" class="btn-logout">Sign Out</a>
      </div>
    </header>

    <!-- DASHBOARD WORKSPACE CONTAINER -->
    <main class="dashboard-container">

      <!-- HERO WELCOME BANNER -->
      <section class="hero-welcome-card">
        <div class="welcome-text">
          <h1>Welcome, <?php echo htmlspecialchars($user['first_name'] ?: ($user['email'] ?? 'User')); ?></h1>
          <p>Enterprise Cost Intelligence & Role-Based Access Workspace</p>
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

      <?php if (!$role): ?>
        <div class="section-card" style="border-color: rgba(239, 68, 68, 0.3); background: #FEE2E2;">
          <strong style="color: #DC2626;">Account Access Notice:</strong>
          No role is assigned to your account in Neon PostgreSQL. Please contact your system administrator.
        </div>
      <?php endif; ?>

      <!-- ACCESS SUMMARY CARDS (CALCULATED FROM SQL) -->
      <section class="grid-cards">
        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Accessible Menus</span>
          </div>
          <div class="stat-number"><?php echo count($authorizedMenus); ?></div>
          <div class="stat-desc">Authorized for <?php echo htmlspecialchars($role['role_code'] ?? 'ROLE'); ?></div>
        </div>

        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Active Permissions</span>
          </div>
          <div class="stat-number"><?php echo count($authorizedPermissions); ?></div>
          <div class="stat-desc">Granted action rules</div>
        </div>

        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Primary Role</span>
          </div>
          <div class="stat-number" style="font-size: 1.15rem; line-height: 1.8rem;"><?php echo htmlspecialchars($role['role_name'] ?? 'Unassigned'); ?></div>
          <div class="stat-desc">Code: <?php echo htmlspecialchars($role['role_code'] ?? 'N/A'); ?></div>
        </div>

        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Permission Types</span>
          </div>
          <div class="stat-desc" style="margin-top: 0.5rem;">
            <?php if (!empty($permCounts)): ?>
              <?php foreach ($permCounts as $code => $cnt): ?>
                <span class="perm-pill perm-<?php echo strtolower($code); ?>"><?php echo htmlspecialchars($code); ?>: <?php echo $cnt; ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span style="color: var(--color-secondary-text);">No permissions assigned.</span>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- MY ACCESSIBLE MENUS TABLE -->
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

      <!-- MY ACTION PERMISSIONS TABLE -->
      <section class="section-card">
        <div class="section-header">
          <h2 class="section-title">My Action Permissions</h2>
        </div>
        <div class="table-responsive">
          <?php if (!empty($authorizedPermissions)): ?>
            <table>
              <thead>
                <tr>
                  <th>Menu</th>
                  <th>Permission Code</th>
                  <th>Permission Name</th>
                  <th>Description</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($authorizedPermissions as $p): ?>
                  <tr>
                    <td><strong><?php echo htmlspecialchars($p['menu_name']); ?></strong></td>
                    <td>
                      <span class="perm-pill perm-<?php echo strtolower($p['permission_code']); ?>">
                        <?php echo htmlspecialchars($p['permission_code']); ?>
                      </span>
                    </td>
                    <td><?php echo htmlspecialchars($p['permission_name']); ?></td>
                    <td style="color: var(--color-secondary-text);"><?php echo htmlspecialchars($p['description']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="empty-state">No permissions are currently assigned.</div>
          <?php endif; ?>
        </div>
      </section>

      <!-- ROLE ACCESS MATRIX TABLE -->
      <section class="section-card">
        <div class="section-header">
          <h2 class="section-title">My Role Access Matrix</h2>
        </div>
        <div class="table-responsive">
          <?php if (!empty($menuMatrix)): ?>
            <table>
              <thead>
                <tr>
                  <th>Assigned Role</th>
                  <th>Target Menu</th>
                  <th>Granted Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($menuMatrix as $mName => $perms): ?>
                  <tr>
                    <td><span class="role-badge"><?php echo htmlspecialchars($role['role_name'] ?? 'Role'); ?></span></td>
                    <td><strong><?php echo htmlspecialchars($mName); ?></strong></td>
                    <td>
                      <?php foreach ($perms as $p): ?>
                        <span class="perm-pill perm-<?php echo strtolower($p['code']); ?>">
                          <?php echo htmlspecialchars($p['code']); ?>
                        </span>
                      <?php endforeach; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="empty-state">No role access matrix assignments found.</div>
          <?php endif; ?>
        </div>
      </section>

    </main>

  </div>

</body>
</html>
