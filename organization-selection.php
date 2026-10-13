<?php
/**
 * Cost360 - Operational Location Selection
 * Unified organizational level for Plants and Warehouses.
 * Hierarchy: Customer → Company → Division → Location → Period
 */

require_once __DIR__ . '/config/auth.php';

requireAuth();

$userId = $_SESSION['user_id'] ?? null;
$activeCustomer = getActiveCustomer();
$activeCompany  = getActiveCompany();
$activeDivision = getActiveDivision();
$isAdmin = isPlatformAdmin($userId);

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

$companyId  = $activeCompany['company_id'];
$divisionId = $activeDivision['division_id'];
$errorMessage = '';

/* ============================================================
   FETCH UNIFIED ACCESSIBLE LOCATIONS UNDER DIVISION
   ============================================================ */
$locations = getUserAccessibleLocations($userId, $companyId, $divisionId);

/* ============================================================
   AUTO-SKIP LOGIC (MANDATORY REQUIREMENT)
   - IF exactly ONE Location exists under this scope: Automatically select it and proceed to Period selection.
   - IF ZERO Locations exist under this scope: Advance to Period selection at Division scope.
   ============================================================ */
if (!$isAdmin && empty($_POST)) {
    if (count($locations) === 1) {
        $singleLocation = $locations[0];
        if (setActiveLocation($singleLocation['type'], $singleLocation['id'])) {
            header('Location: period-selection.php');
            exit;
        }
    } elseif (count($locations) === 0) {
        header('Location: period-selection.php');
        exit;
    }
}

/* ============================================================
   HANDLE LOCATION SELECTION (POST)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationType = strtolower(trim($_POST['location_type'] ?? ''));
    $locationId   = trim($_POST['location_id'] ?? '');

    if ($locationId === '') {
        $errorMessage = 'Please select a Location to proceed.';
    } else {
        if ($locationId === 'ALL' && $isAdmin) {
            $_SESSION['active_location_type'] = $locationType ?: 'plant';
            $_SESSION['active_plant_id'] = 'ALL';
            $_SESSION['active_plant_code'] = 'ALL';
            $_SESSION['active_plant_name'] = 'All Plants';
            $_SESSION['active_warehouse_id'] = 'ALL';
            $_SESSION['active_warehouse_code'] = 'ALL';
            $_SESSION['active_warehouse_name'] = 'All Warehouses';
            clearActivePeriod();
            syncCost360ContextSession();
            header('Location: period-selection.php');
            exit;
        }

        if (setActiveLocation($locationType, $locationId)) {
            header('Location: period-selection.php');
            exit;
        } else {
            $errorMessage = 'You do not have access to the selected Location.';
        }
    }
}

$user = getAuthUser();
$userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($userName === '') {
    $userName = $user['username'] ?? $user['email'] ?? 'User';
}

$activeLoc = getActiveOperationalLocation();
$currentlySelectedType = $activeLoc['type_code'] ?? ($_SESSION['active_location_type'] ?? '');
$currentlySelectedId   = $activeLoc['id'] ?? (($currentlySelectedType === 'plant') ? ($_SESSION['active_plant_id'] ?? '') : ($_SESSION['active_warehouse_id'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost360 - Select Location</title>
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
            padding: 36px 20px;
            width: 100%;
            box-sizing: border-box;
        }
        .container {
            width: 100%;
            max-width: 1140px;
            margin: 0 auto;
        }

        /* BRAND & TOP HEADER */
        .brand {
            text-align: center;
            margin-bottom: 24px;
        }
        .brand h1 {
            margin: 0;
            color: #033665;
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.5px;
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
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .context-group {
            display: flex;
            gap: 28px;
            flex-wrap: wrap;
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
            font-size: 15px;
            font-weight: 700;
            color: #033665;
        }

        /* BANNER */
        .choice-indicator {
            background: #F0F7FD;
            border: 1px solid #B3D8F5;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            text-align: center;
        }
        .choice-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #033665;
        }
        .choice-subtitle {
            margin: 6px 0 0;
            font-size: 13px;
            color: #475569;
        }

        /* LOCATION LIST / GRID */
        .location-section {
            background: #FFFFFF;
            border: 1px solid #D8E6F0;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(3, 54, 101, 0.05);
            margin-bottom: 20px;
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid #EDF2F7;
        }
        .section-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #033665;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-badge {
            background: #E8F4FD;
            color: #0070BA;
            font-size: 12px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 12px;
        }

        .grid-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 16px;
        }

        .location-card {
            border: 2px solid #E2E8F0;
            border-radius: 12px;
            padding: 18px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            background: #FFFFFF;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .location-card:hover {
            border-color: #0070BA;
            box-shadow: 0 6px 18px rgba(0, 112, 186, 0.12);
            transform: translateY(-2px);
        }
        .location-card.selected {
            border-color: #0070BA;
            background: #F7FBFE;
            box-shadow: 0 6px 20px rgba(0, 112, 186, 0.15);
        }

        .selected-ribbon {
            display: none;
            position: absolute;
            top: 12px;
            right: 12px;
            background: #0070BA;
            color: #FFFFFF;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.05em;
            padding: 3px 8px;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .location-card.selected .selected-ribbon {
            display: block;
        }

        .card-top {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }
        .card-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .icon-plant {
            background: #E0F2FE;
            color: #0284C7;
        }
        .icon-warehouse {
            background: #FEF3C7;
            color: #D97706;
        }

        .card-info {
            flex: 1;
            min-width: 0;
        }
        .card-name {
            font-size: 15px;
            font-weight: 700;
            color: #033665;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .type-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .type-badge.badge-plant {
            background: #E0F2FE;
            color: #0369A1;
            border: 1px solid #BAE6FD;
        }
        .type-badge.badge-warehouse {
            background: #FEF3C7;
            color: #B45309;
            border: 1px solid #FDE68A;
        }

        .card-meta {
            font-size: 12px;
            color: #64748B;
            margin-bottom: 2px;
        }
        .card-meta strong {
            color: #1E293B;
        }

        .card-footer {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #EDF2F7;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .select-btn {
            background: transparent;
            border: 1px solid #0070BA;
            color: #0070BA;
            padding: 7px 14px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
            text-align: center;
        }
        .location-card:hover .select-btn,
        .location-card.selected .select-btn {
            background: #0070BA;
            color: #FFFFFF;
        }

        /* ACTION BAR */
        .action-bar {
            margin-top: 24px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-continue {
            background: #0070BA;
            color: #FFFFFF;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 112, 186, 0.25);
        }
        .btn-continue:hover:not(:disabled) {
            background: #005A96;
            transform: translateY(-1px);
        }
        .btn-continue:disabled {
            background: #94A3B8;
            cursor: not-allowed;
            box-shadow: none;
        }

        .alert-error {
            background: #FEE2E2;
            border: 1px solid #FCA5A5;
            color: #DC2626;
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .empty-location-box {
            text-align: center;
            padding: 48px 24px;
            background: #FFFFFF;
            border: 1px dashed #CBD5E1;
            border-radius: 16px;
            margin-bottom: 24px;
        }
        .empty-location-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            color: #94A3B8;
        }
        .empty-location-title {
            font-size: 18px;
            font-weight: 700;
            color: #033665;
            margin: 0 0 8px;
        }
        .empty-location-desc {
            font-size: 14px;
            color: #64748B;
            max-width: 480px;
            margin: 0 auto 20px;
        }
    </style>
    <link rel="stylesheet" href="assets/css/cost360-core.css">
</head>
<body>
<div class="page">
    <div class="container">
        <!-- BRAND -->
        <div class="brand">
            <img src="assets/images/cost360-logo-navy.png" alt="Cost360 Logo" style="height: 48px; width: auto; margin-bottom: 8px;">
            <p>Select Operational Location Context</p>
        </div>

        <!-- PROGRESS BREADCRUMB (UNIFIED HIERARCHY) -->
        <div class="progress-bar-container">
            <div class="progress-steps">
                <span class="step completed">Customer ✓</span>
                <span class="step-arrow">→</span>
                <span class="step completed">Company ✓</span>
                <span class="step-arrow">→</span>
                <span class="step completed">Division ✓</span>
                <span class="step-arrow">→</span>
                <span class="step active">Location ●</span>
                <span class="step-arrow">→</span>
                <span class="step">Period ○</span>
            </div>
            <a href="division-selection.php" class="back-nav-link">
                ← Back to Division Selection
            </a>
        </div>

        <!-- CONTEXT SUMMARY -->
        <div class="context-summary">
            <div class="context-group">
                <div class="context-item">
                    <span class="context-label">Customer</span>
                    <span class="context-value"><?php echo htmlspecialchars($activeCustomer['customer_name'] ?? $activeCompany['company_name']); ?></span>
                </div>
                <div class="context-item">
                    <span class="context-label">Company</span>
                    <span class="context-value"><?php echo htmlspecialchars($activeCompany['company_name']); ?></span>
                </div>
                <div class="context-item">
                    <span class="context-label">Division</span>
                    <span class="context-value"><?php echo htmlspecialchars($activeDivision['division_name']); ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <!-- BANNER -->
        <div class="choice-indicator">
            <h2 class="choice-title">Select Operational Location</h2>
            <p class="choice-subtitle">Plant and Warehouse are unified as Locations. Choose your operational location to continue to Period context.</p>
        </div>

        <?php if (empty($locations)): ?>
            <!-- CASE 3: ZERO LOCATIONS -->
            <div class="empty-location-box">
                <svg class="empty-location-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
                <h3 class="empty-location-title">No Plant or Warehouse is configured for this Division.</h3>
                <p class="empty-location-desc">No active operational locations are configured or accessible under <strong><?php echo htmlspecialchars($activeDivision['division_name']); ?></strong>. Please select another division or contact your administrator.</p>
                <a href="division-selection.php" class="btn-continue" style="text-decoration: none;">
                    ← Select Another Division
                </a>
            </div>
        <?php else: ?>
            <!-- CASE 2: MULTIPLE LOCATIONS (ONE COMBINED LIST) -->
            <form id="locationForm" method="POST" action="organization-selection.php">
                <input type="hidden" name="location_type" id="selectedLocationType" value="<?php echo htmlspecialchars($currentlySelectedType); ?>">
                <input type="hidden" name="location_id" id="selectedLocationId" value="<?php echo htmlspecialchars($currentlySelectedId); ?>">

                <div class="location-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            Available Locations
                        </h3>
                        <span class="section-badge"><?php echo count($locations); ?> Location(s)</span>
                    </div>

                    <div class="grid-list">
                        <?php foreach ($locations as $loc): 
                            $isPlant = ($loc['type_code'] === 'plant');
                            $isSel = ($currentlySelectedType === $loc['type_code'] && (string)$currentlySelectedId === (string)$loc['id']);
                        ?>
                            <div class="location-card <?php echo $isSel ? 'selected' : ''; ?>"
                                 data-type="<?php echo htmlspecialchars($loc['type_code']); ?>"
                                 data-id="<?php echo htmlspecialchars($loc['id']); ?>"
                                 data-name="<?php echo htmlspecialchars($loc['name']); ?>"
                                 onclick="selectLocation('<?php echo htmlspecialchars($loc['type_code']); ?>', '<?php echo htmlspecialchars($loc['id']); ?>', this)">
                                <span class="selected-ribbon">✓ SELECTED</span>
                                
                                <div class="card-top">
                                    <div class="card-icon <?php echo $isPlant ? 'icon-plant' : 'icon-warehouse'; ?>">
                                        <?php if ($isPlant): ?>
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 21h18"></path>
                                                <path d="M19 21v-8l-5 4V9l-5 4V3H3v18"></path>
                                            </svg>
                                        <?php else: ?>
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                                                <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                                <line x1="12" y1="22.08" x2="12" y2="12"></line>
                                            </svg>
                                        <?php endif; ?>
                                    </div>

                                    <div class="card-info">
                                        <span class="type-badge <?php echo $isPlant ? 'badge-plant' : 'badge-warehouse'; ?>">
                                            Type: <?php echo htmlspecialchars($loc['type']); ?>
                                        </span>
                                        <div class="card-name" title="<?php echo htmlspecialchars($loc['name']); ?>">
                                            <?php echo htmlspecialchars($loc['name']); ?>
                                        </div>
                                        <div class="card-meta">
                                            Code: <strong><?php echo htmlspecialchars($loc['code']); ?></strong>
                                        </div>
                                        <?php if (!empty($loc['parent_plant_name'])): ?>
                                            <div class="card-meta">
                                                Attached Plant: <strong><?php echo htmlspecialchars($loc['parent_plant_name']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($loc['location_address'])): ?>
                                            <div class="card-meta" style="font-size: 11px; color: #94A3B8;">
                                                Midc/Area: <?php echo htmlspecialchars($loc['location_address']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card-footer">
                                    <button type="button" class="select-btn">
                                        <?php echo $isSel ? 'Selected Location' : 'Select Location'; ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="action-bar">
                    <button type="submit" id="submitBtn" class="btn-continue" <?php echo empty($currentlySelectedId) ? 'disabled' : ''; ?>>
                        <span>Continue to Period Context</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    function selectLocation(type, id, element) {
        document.querySelectorAll('.location-card').forEach(c => {
            c.classList.remove('selected');
            const btn = c.querySelector('.select-btn');
            if (btn) btn.textContent = 'Select Location';
        });

        element.classList.add('selected');
        const activeBtn = element.querySelector('.select-btn');
        if (activeBtn) activeBtn.textContent = 'Selected Location';

        document.getElementById('selectedLocationType').value = type;
        document.getElementById('selectedLocationId').value = id;

        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.disabled = false;
        }

        // Auto submit directly on click for frictionless selection
        document.getElementById('locationForm').submit();
    }
</script>
</body>
</html>
