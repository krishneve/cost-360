<?php
/**
 * Cost360
 * Period Selection
 *
 * Users reach this page after selecting Company, Division, Plant, and Warehouse.
 */

require_once __DIR__ . '/config/auth.php';

requireAuth();

$userId = $_SESSION['user_id'] ?? null;
$activeCompany = getActiveCompany();
$activeDivision = getActiveDivision();
$activePlant = getActivePlant();
$activeWarehouse = getActiveWarehouse();

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

if (!$activePlant || empty($activePlant['plant_id'])) {
    header('Location: plant-selection.php');
    exit;
}

if (!$activeWarehouse || empty($activeWarehouse['warehouse_id'])) {
    header('Location: warehouse-selection.php');
    exit;
}

$companyId = $activeCompany['company_id'];
$errorMessage = '';

/* ============================================================
   HANDLE PERIOD SELECTION
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
   FETCH ACCESSIBLE PERIODS
   ============================================================ */

$periods = getUserAccessiblePeriods($companyId);

/* ============================================================
   AUTO-SELECT SINGLE PERIOD
   ============================================================ */

if (count($periods) === 1 && empty($_POST)) {
    $singlePeriod = $periods[0];
    if (setActivePeriod($singlePeriod['period_id'])) {
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #F5FAFE 0%, #EAF5FC 100%);
            color: #17324D;
        }
        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
        }
        .container {
            width: 100%;
            max-width: 950px;
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
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(3, 54, 101, 0.07);
            border: 1px solid #E1EAF0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .context-header {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #0878D1;
            margin-bottom: 4px;
        }
        .context-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            color: #6B7F91;
            font-weight: 600;
        }
        .context-value {
            font-size: 14px;
            font-weight: 700;
            color: #033665;
        }
        .change-link {
            color: #0878D1;
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
            color: #17324D;
        }
        .grid-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
        }
        .card-item {
            background: #FFFFFF;
            border: 1px solid #E1EAF0;
            border-radius: 16px;
            padding: 24px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            box-shadow: 0 8px 25px rgba(3, 54, 101, 0.06);
        }
        .card-item:hover {
            transform: translateY(-3px);
            border-color: #0878D1;
            box-shadow: 0 14px 35px rgba(3, 54, 101, 0.12);
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
        .card-dates {
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
        .card-form { margin: 0; }
        .card-button {
            width: 100%;
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
    </style>
</head>
<body>
<div class="page">
    <div class="container">
        <div class="brand">
            <h1>Cost360</h1>
            <p>Select Active Accounting Period Context</p>
        </div>

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
                    <span class="context-label">Plant</span>
                    <span class="context-value"><?php echo htmlspecialchars($activePlant['plant_name']); ?></span>
                </div>
                <div class="context-item">
                    <span class="context-label">Warehouse</span>
                    <span class="context-value"><?php echo htmlspecialchars($activeWarehouse['warehouse_name']); ?></span>
                </div>
            </div>
            <a href="warehouse-selection.php" class="change-link">Change Warehouse</a>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <h2 class="section-title">Available Accounting Periods</h2>

        <?php if (empty($periods)): ?>
            <div class="empty-card">
                <h3>No Active Periods Found</h3>
                <p>No active accounting periods are currently defined in the system for this organization context.</p>
                <a href="warehouse-selection.php" class="change-link">Back to Warehouse Selection</a>
            </div>
        <?php else: ?>
            <div class="grid-list">
                <?php foreach ($periods as $per): ?>
                    <form method="POST" class="card-form">
                        <input type="hidden" name="period_id" value="<?php echo htmlspecialchars($per['period_id']); ?>">
                        <button type="submit" class="card-button">
                            <div class="card-item">
                                <div class="card-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
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
            <a href="warehouse-selection.php">&larr; Back to Warehouse Selection</a>
        </div>
    </div>
</div>
</body>
</html>
