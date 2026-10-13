<?php
/**
 * Cost360
 * Company Selection
 *
 * Users reach this page after selecting a Customer or during initial organization selection.
 */

require_once __DIR__ . '/config/auth.php';

requireAuth();

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: login.php');
    exit;
}

$isAdmin = isPlatformAdmin($userId);
$activeCustomer = getActiveCustomer();
if ((!$activeCustomer || empty($activeCustomer['customer_id'])) && !$isAdmin) {
    $tenantId = getUserCustomerTenantId($userId);
    if ($tenantId) {
        setActiveCustomer($tenantId);
        $activeCustomer = getActiveCustomer();
    }
}
$customerId = $activeCustomer['customer_id'] ?? null;
$accessibleCustomers = getUserAccessibleCustomers($userId);
$hasMultipleCustomers = $isAdmin || count($accessibleCustomers) > 1;
$errorMessage = '';

/* ============================================================
   HANDLE COMPANY SELECTION
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyId = trim($_POST['company_id'] ?? '');

    if ($companyId === '') {
        $errorMessage = 'Please select a company.';
    } else {
        if ($companyId === 'ALL' && $isAdmin) {
            $_SESSION['active_company_id'] = 'ALL';
            $_SESSION['active_company_code'] = 'ALL';
            $_SESSION['active_company_name'] = 'All Companies';
            clearActiveDivision();
            header('Location: division-selection.php');
            exit;
        }

        if (setActiveCompany($companyId)) {
            header('Location: division-selection.php');
            exit;
        } else {
            $errorMessage = 'You do not have access to the selected company.';
        }
    }
}

/* ============================================================
   FETCH ACCESSIBLE COMPANIES FOR SELECTED CUSTOMER
   ============================================================ */

$companies = getUserAccessibleCompanies($userId, $customerId);

/* ============================================================
   AUTO-SELECT SINGLE COMPANY (For non-admin)
   ============================================================ */

if (!$isAdmin && count($companies) === 1 && empty($_POST)) {
    $singleCompany = $companies[0];
    if (setActiveCompany($singleCompany['company_id'])) {
        header('Location: division-selection.php');
        exit;
    }
}

$user = getAuthUser();
$userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($userName === '') {
    $userName = $user['username'] ?? $user['email'] ?? 'User';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost360 - Select Company</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #F5FAFE 0%, #EAF5FC 100%);
            color: #17324D;
        }
        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            width: 100%;
            box-sizing: border-box;
        }
        .container {
            width: 100%;
            max-width: 1140px;
            margin: 0 auto;
        }
        .brand {
            text-align: center;
            margin-bottom: 24px;
        }
        .brand h1 {
            margin: 0;
            color: #033665;
            font-size: 32px;
            font-weight: 800;
        }
        .brand p {
            margin: 8px 0 0;
            color: #6B7F91;
            font-size: 15px;
        }
        .context-summary {
            background: #FFFFFF;
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(3, 54, 101, 0.07);
            border: 1px solid #E1EAF0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .context-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .context-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6B7F91;
            font-weight: 600;
        }
        .context-value {
            font-size: 16px;
            font-weight: 700;
            color: #033665;
        }
        .change-link {
            color: #0878D1;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }
        .change-link:hover { text-decoration: underline; }
        .section-title {
            margin: 0 0 16px;
            font-size: 20px;
            font-weight: 700;
            color: #17324D;
        }
        .grid-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            width: 100%;
        }
        @media (max-width: 680px) {
            .grid-list {
                grid-template-columns: 1fr;
            }
        }
        .card-item {
            background: #FFFFFF;
            border: 1px solid #E1EAF0;
            border-radius: 16px;
            padding: 24px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            box-shadow: 0 8px 25px rgba(3, 54, 101, 0.06);
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .card-item:hover {
            transform: translateY(-3px);
            border-color: #0878D1;
            box-shadow: 0 14px 35px rgba(3, 54, 101, 0.12);
        }
        .card-item.global-card {
            border: 2px dashed #0878D1;
            background: #F0F7FF;
        }
        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: #EAF5FC;
            color: #0878D1;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
        }
        .card-icon svg { width: 25px; height: 25px; }
        .card-name {
            font-size: 18px;
            font-weight: 700;
            color: #17324D;
            margin-bottom: 7px;
        }
        .card-code {
            font-size: 13px;
            color: #6B7F91;
            margin-bottom: 18px;
        }
        .select-text {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #0878D1;
            font-size: 14px;
            font-weight: 700;
        }
        .error {
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: #991B1B;
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .card-form { margin: 0; height: 100%; }
        .card-button {
            width: 100%;
            height: 100%;
            border: 0;
            background: transparent;
            padding: 0;
            text-align: left;
            cursor: pointer;
            font-family: inherit;
            color: inherit;
        }
        .empty-card {
            background: #FFFFFF;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(3, 54, 101, 0.07);
        }
        .empty-card p {
            color: #6B7F91;
            margin-bottom: 20px;
        }
        .nav-back {
            text-align: center;
            margin-top: 24px;
        }
        .nav-back a {
            color: #6B7F91;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .nav-back a:hover { color: #0878D1; }
        .progress-bar-container {
            background: #FFFFFF;
            border: 1px solid #D8E6F0;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 14px rgba(3, 54, 101, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .progress-steps {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 13px;
            font-weight: 600;
        }
        .step {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748B;
        }
        .step.completed {
            color: #059669;
        }
        .step.active {
            color: #033665;
            font-weight: 700;
            background: #E8F4FD;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #B3D8F5;
        }
        .step-arrow {
            color: #CBD5E1;
            font-size: 12px;
        }
    </style>
    <link rel="stylesheet" href="assets/css/cost360-core.css">
</head>
<body>
<div class="page">
    <div class="container">
        <div class="brand">
            <img src="assets/images/cost360-logo-navy.png" alt="Cost360 Logo" style="height: 48px; width: auto; margin-bottom: 8px;">
            <p>Select Company Context</p>
        </div>

        <!-- PROGRESS BREADCRUMB -->
        <div class="progress-bar-container">
            <div class="progress-steps">
                <span class="step completed">Customer ✓</span>
                <span class="step-arrow">→</span>
                <span class="step active">Company ●</span>
                <span class="step-arrow">→</span>
                <span class="step">Division ○</span>
                <span class="step-arrow">→</span>
                <span class="step">Location ○</span>
                <span class="step-arrow">→</span>
                <span class="step">Period ○</span>
            </div>
            <?php if ($hasMultipleCustomers): ?>
                <a href="customer-selection.php" class="change-link">← Change Customer</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($activeCustomer) && !empty($activeCustomer['customer_name'])): ?>
            <div class="context-summary">
                <div class="context-item">
                    <span class="context-label">Customer Scope</span>
                    <span class="context-value"><?php echo htmlspecialchars($activeCustomer['customer_name']); ?></span>
                </div>
                <?php if ($hasMultipleCustomers): ?>
                    <a href="customer-selection.php" class="change-link">Change Customer</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <h2 class="section-title">Available Companies</h2>

        <?php if (empty($companies) && !$isAdmin): ?>
            <div class="empty-card">
                <h3>No Company Access</h3>
                <p>Your account is authenticated, but no active company has been assigned under this customer context. Please contact your administrator.</p>
                <a href="customer-selection.php" class="change-link">Change Customer Scope</a>
            </div>
        <?php else: ?>
            <div class="grid-list">
                <?php if ($isAdmin): ?>
                    <form method="POST" class="card-form">
                        <input type="hidden" name="company_id" value="ALL">
                        <button type="submit" class="card-button">
                            <div class="card-item global-card">
                                <div>
                                    <div class="card-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="2" y1="12" x2="22" y2="12"/>
                                        </svg>
                                    </div>
                                    <div class="card-name">All Companies (Global)</div>
                                    <div class="card-code">Cross-Company Admin Scope</div>
                                </div>
                                <div class="select-text">
                                    <span>Select All Companies</span>
                                    <span>&rarr;</span>
                                </div>
                            </div>
                        </button>
                    </form>
                <?php endif; ?>

                <?php foreach ($companies as $c): ?>
                    <form method="POST" class="card-form">
                        <input type="hidden" name="company_id" value="<?php echo htmlspecialchars($c['company_id']); ?>">
                        <button type="submit" class="card-button">
                            <div class="card-item">
                                <div>
                                    <div class="card-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="7" width="18" height="14" rx="2"/>
                                            <path d="M8 21V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v16"/>
                                        </svg>
                                    </div>
                                    <div class="card-name"><?php echo htmlspecialchars($c['company_name']); ?></div>
                                    <div class="card-code">Code: <?php echo htmlspecialchars($c['company_code']); ?></div>
                                </div>
                                <div class="select-text">
                                    <span>Select Company</span>
                                    <span>&rarr;</span>
                                </div>
                            </div>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($hasMultipleCustomers): ?>
            <div class="nav-back">
                <a href="customer-selection.php">&larr; Back to Customer Selection</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>