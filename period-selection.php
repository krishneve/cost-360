<?php
/**
 * Cost360
 * Period Selection
 *
 * Flow: Customer → Company → Division → Location → Period
 */

require_once __DIR__ . '/config/auth.php';

requireAuth();

$userId = $_SESSION['user_id'] ?? null;
$activeCustomer = getActiveCustomer();
$activeCompany  = getActiveCompany();
$activeDivision = getActiveDivision();
$activeLocation = getActiveOperationalLocation();

if (!$userId) {
    header('Location: login.php');
    exit;
}

if (!$activeCompany || empty($activeCompany['company_id'])) {
    header('Location: company-selection.php');
    exit;
}

if (!$activeDivision || empty($activeDivision['division_id'])) {
    header('Location: division-selection.php');
    exit;
}

$companyId    = $activeCompany['company_id'];
$divisionId   = $activeDivision['division_id'] ?? null;

// Only enforce location if accessible locations actually exist under the division
$locationsUnderDiv = (!empty($divisionId)) ? getUserAccessibleLocations($userId, $companyId, $divisionId) : [];
if ((!$activeLocation || empty($activeLocation['id'])) && count($locationsUnderDiv) > 1) {
    header('Location: organization-selection.php');
    exit;
}

$locationType = $activeLocation['type_code'] ?? null;
$locationId   = $activeLocation['id'] ?? null;
$errorMessage = '';

/* ============================================================
   HANDLE PERIOD SELECTION (POST)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $periodId = trim($_POST['period_id'] ?? '');

    if ($periodId === '') {
        $errorMessage = 'Please select a period.';
    } else {
        if (setActivePeriod($periodId)) {
            header('Location: dashboard.php');
            exit;
        } else {
            $errorMessage = 'You do not have access to the selected period.';
        }
    }
}

/* ============================================================
   FETCH ACCESSIBLE PERIODS FOR LOCATION CONTEXT
   ============================================================ */
$periods = getUserAccessiblePeriods($companyId, $userId, $divisionId, $locationType, $locationId);

/* ============================================================
   DYNAMIC SKIP: AUTO-SELECT SINGLE ACTIVE PERIOD OR ADVANCE IF NONE
   ============================================================ */
if (empty($_POST)) {
    if (count($periods) === 1) {
        $singlePeriod = $periods[0];
        if (setActivePeriod($singlePeriod['period_id'])) {
            header('Location: dashboard.php');
            exit;
        }
    } elseif (count($periods) === 0) {
        header('Location: dashboard.php');
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
    <title>Cost360 - Select Accounting Period</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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

        /* BRAND */
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
            color: #5A7184;
            font-size: 15px;
        }

        /* HIERARCHY PROGRESS BAR */
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
        .back-nav-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #0070BA;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            padding: 4px 10px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        .back-nav-link:hover {
            background: #E8F4FD;
            text-decoration: underline;
        }

        /* CONTEXT SUMMARY CARD */
        .context-summary {
            background: #FFFFFF;
            border-radius: 14px;
            padding: 18px 24px;
            margin-bottom: 24px;
            box-shadow: 0 6px 20px rgba(3, 54, 101, 0.05);
            border: 1px solid #D8E6F0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .context-header {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #0070BA;
        }
        .context-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .context-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .context-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748B;
            font-weight: 600;
        }
        .context-value {
            font-size: 14px;
            font-weight: 700;
            color: #033665;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .type-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .type-badge.badge-plant {
            background: #E0F2FE;
            color: #0369A1;
        }
        .type-badge.badge-warehouse {
            background: #FEF3C7;
            color: #B45309;
        }

        .change-link {
            color: #0070BA;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            align-self: flex-end;
        }
        .change-link:hover { text-decoration: underline; }

        .section-title {
            margin: 0 0 16px;
            font-size: 20px;
            font-weight: 700;
            color: #033665;
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
            border: 1px solid #D8E6F0;
            border-radius: 14px;
            padding: 22px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 6px 18px rgba(3, 54, 101, 0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .card-item:hover {
            transform: translateY(-2px);
            border-color: #0070BA;
            box-shadow: 0 12px 30px rgba(0, 112, 186, 0.12);
        }
        .card-icon {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            background: #E8F4FD;
            color: #0070BA;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .card-name {
            font-size: 17px;
            font-weight: 700;
            color: #033665;
            margin-bottom: 6px;
        }
        .card-dates {
            font-size: 13px;
            color: #64748B;
            margin-bottom: 16px;
        }
        .select-text {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #0070BA;
            font-size: 13px;
            font-weight: 700;
            padding-top: 12px;
            border-top: 1px solid #EDF2F7;
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
            border: 1px dashed #CBD5E1;
            border-radius: 16px;
            padding: 40px 24px;
            text-align: center;
            box-shadow: 0 6px 20px rgba(3, 54, 101, 0.04);
        }
        .empty-card h3 {
            font-size: 18px;
            font-weight: 700;
            color: #033665;
            margin: 0 0 10px;
        }
        .empty-card p {
            color: #64748B;
            font-size: 14px;
            max-width: 520px;
            margin: 0 auto 20px;
            line-height: 1.5;
        }
        .context-snapshot {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 14px;
            margin: 16px auto 24px;
            max-width: 480px;
            text-align: left;
            font-size: 13px;
        }
        .snapshot-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dashed #E2E8F0;
        }
        .snapshot-row:last-child { border-bottom: none; }
        .snapshot-label { color: #64748B; font-weight: 600; }
        .snapshot-value { color: #033665; font-weight: 700; }

        .nav-back {
            text-align: center;
            margin-top: 24px;
        }
        .nav-back a {
            color: #64748B;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .nav-back a:hover { color: #0070BA; }
    </style>
    <link rel="stylesheet" href="assets/css/cost360-core.css">
</head>
<body>
<div class="page">
    <div class="container">
        <!-- BRAND -->
        <div class="brand">
            <img src="assets/images/cost360-logo-navy.png" alt="Cost360 Logo" style="height: 48px; width: auto; margin-bottom: 8px;">
            <p>Select Active Accounting Period Context</p>
        </div>

        <!-- PROGRESS BREADCRUMB -->
        <div class="progress-bar-container">
            <div class="progress-steps">
                <span class="step completed">Customer ✓</span>
                <span class="step-arrow">→</span>
                <span class="step completed">Company ✓</span>
                <span class="step-arrow">→</span>
                <span class="step completed">Division ✓</span>
                <span class="step-arrow">→</span>
                <span class="step completed">Location ✓</span>
                <span class="step-arrow">→</span>
                <span class="step active">Period ●</span>
            </div>
            <a href="organization-selection.php" class="back-nav-link">
                ← Back to Location Selection
            </a>
        </div>

        <!-- CONTEXT SUMMARY -->
        <div class="context-summary">
            <div class="context-header">Active Organization Scope</div>
            <div class="context-grid">
                <div class="context-item">
                    <span class="context-label">Company</span>
                    <span class="context-value"><?php echo htmlspecialchars($activeCompany['company_name']); ?></span>
                </div>
                <div class="context-item">
                    <span class="context-label">Division</span>
                    <span class="context-value"><?php echo htmlspecialchars($activeDivision['division_name']); ?></span>
                </div>
                <div class="context-item">
                    <span class="context-label">Operational Location</span>
                    <span class="context-value">
                        <?php echo htmlspecialchars($activeLocation['name']); ?>
                        <span class="type-badge badge-<?php echo htmlspecialchars($activeLocation['type_code'] ?? 'plant'); ?>">
                            <?php echo htmlspecialchars($activeLocation['label'] ?? 'Location'); ?>
                        </span>
                    </span>
                </div>
            </div>
            <a href="organization-selection.php" class="change-link">Change Location</a>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <h2 class="section-title">Available Accounting Periods</h2>

        <?php if (empty($periods)): ?>
            <!-- ZERO ACTIVE PERIODS FOUND -->
            <div class="empty-card">
                <h3>No Active Periods Found</h3>
                <p>No active accounting periods are currently configured in the system for this organization context.</p>

                <div class="context-snapshot">
                    <div class="snapshot-row">
                        <span class="snapshot-label">Company:</span>
                        <span class="snapshot-value"><?php echo htmlspecialchars($activeCompany['company_name']); ?></span>
                    </div>
                    <div class="snapshot-row">
                        <span class="snapshot-label">Division:</span>
                        <span class="snapshot-value"><?php echo htmlspecialchars($activeDivision['division_name']); ?></span>
                    </div>
                    <div class="snapshot-row">
                        <span class="snapshot-label">Location:</span>
                        <span class="snapshot-value">
                            <?php echo htmlspecialchars($activeLocation['name']); ?> 
                            (Type: <?php echo htmlspecialchars($activeLocation['label'] ?? 'Location'); ?>)
                        </span>
                    </div>
                </div>

                <a href="organization-selection.php" class="change-link">← Return to Location Selection</a>
            </div>
        <?php else: ?>
            <div class="grid-list">
                <?php foreach ($periods as $per): ?>
                    <form method="POST" class="card-form">
                        <input type="hidden" name="period_id" value="<?php echo htmlspecialchars($per['period_id']); ?>">
                        <button type="submit" class="card-button">
                            <div class="card-item">
                                <div>
                                    <div class="card-icon">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                            <line x1="16" y1="2" x2="16" y2="6"/>
                                            <line x1="8" y1="2" x2="8" y2="6"/>
                                            <line x1="3" y1="10" x2="21" y2="10"/>
                                        </svg>
                                    </div>
                                    <div class="card-name"><?php echo htmlspecialchars($per['period_name']); ?></div>
                                    <div class="card-dates">
                                        <?php echo htmlspecialchars(date('M d, Y', strtotime($per['start_date']))); ?>
                                        &ndash;
                                        <?php echo htmlspecialchars(date('M d, Y', strtotime($per['end_date']))); ?>
                                    </div>
                                </div>
                                <div class="select-text">
                                    <span>Select Period &amp; Enter Dashboard</span>
                                    <span>&rarr;</span>
                                </div>
                            </div>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="nav-back">
            <a href="organization-selection.php">&larr; Back to Location Selection</a>
        </div>
    </div>
</div>
</body>
</html>
