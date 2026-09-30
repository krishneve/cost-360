<?php
/**
 * Cost360 - Operational Location Selection (Unified Plant OR Warehouse)
 * Allows the user to select either a Plant OR a Warehouse as their operational context.
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
   HANDLE LOCATION SELECTION (POST)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationType = strtolower(trim($_POST['location_type'] ?? ''));
    $locationId   = trim($_POST['location_id'] ?? '');

    if ($locationId === '') {
        $errorMessage = 'Please select either a Plant or a Warehouse.';
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

        if ($locationType === 'plant') {
            if (setActivePlant($locationId)) {
                header('Location: period-selection.php');
                exit;
            } else {
                $errorMessage = 'You do not have access to the selected Plant.';
            }
        } elseif ($locationType === 'warehouse') {
            if (setActiveWarehouse($locationId)) {
                header('Location: period-selection.php');
                exit;
            } else {
                $errorMessage = 'You do not have access to the selected Warehouse.';
            }
        } else {
            $errorMessage = 'Invalid operational location type selected.';
        }
    }
}

/* ============================================================
   FETCH ACCESSIBLE PLANTS AND WAREHOUSES UNDER DIVISION
   ============================================================ */
$plants     = getUserAccessiblePlants($userId, $companyId, $divisionId);
$warehouses = getUserAccessibleWarehouses($userId, $companyId, $divisionId, null);

$user = getAuthUser();
$userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($userName === '') {
    $userName = $user['username'] ?? $user['email'] ?? 'User';
}

$currentlySelectedType = $_SESSION['active_location_type'] ?? '';
$currentlySelectedId   = ($currentlySelectedType === 'plant') 
    ? ($_SESSION['active_plant_id'] ?? '') 
    : (($currentlySelectedType === 'warehouse') ? ($_SESSION['active_warehouse_id'] ?? '') : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost360 - Select Operational Location</title>
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
        }
        .container {
            width: 100%;
            max-width: 980px;
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

        /* SELECTION CHOICE BANNER */
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
            font-size: 17px;
            font-weight: 700;
            color: #033665;
        }
        .choice-subtitle {
            margin: 4px 0 0;
            font-size: 13px;
            color: #5A7184;
        }

        /* SECTION STYLING */
        .location-section {
            margin-bottom: 32px;
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 2px solid #E2E8F0;
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: #033665;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .section-badge {
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
            background: #E2E8F0;
            color: #475569;
        }

        /* GRID & CARDS */
        .grid-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 18px;
        }
        .location-card {
            background: #FFFFFF;
            border: 2px solid #E1EAF0;
            border-radius: 14px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.22s ease;
            box-shadow: 0 4px 16px rgba(3, 54, 101, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }
        .location-card:hover {
            transform: translateY(-3px);
            border-color: #0070BA;
            box-shadow: 0 10px 28px rgba(3, 54, 101, 0.1);
        }
        .location-card.selected {
            border-color: #0070BA;
            background: #F4FAFF;
            box-shadow: 0 8px 24px rgba(0, 112, 186, 0.16);
        }
        .selected-ribbon {
            display: none;
            position: absolute;
            top: 14px;
            right: 14px;
            background: #0070BA;
            color: #FFFFFF;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            letter-spacing: 0.3px;
        }
        .location-card.selected .selected-ribbon {
            display: inline-block;
        }

        .card-top {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 14px;
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
            padding: 6px 14px;
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

        /* STICKY CONTINUE ACTION BAR */
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
    </style>
</head>
<body>
<div class="page">
    <div class="container">
        <!-- BRAND -->
        <div class="brand">
            <h1>Cost360</h1>
            <p>Select Operational Location Context</p>
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

        <!-- SELECTION CHOICE BANNER -->
        <div class="choice-indicator">
            <h2 class="choice-title">Select Operational Location</h2>
            <p class="choice-subtitle">Choose either a <strong>Plant</strong> or a <strong>Warehouse</strong> as your operating location.</p>
        </div>

        <form id="locationForm" method="POST" action="organization-selection.php">
            <input type="hidden" name="location_type" id="selectedLocationType" value="<?php echo htmlspecialchars($currentlySelectedType); ?>">
            <input type="hidden" name="location_id" id="selectedLocationId" value="<?php echo htmlspecialchars($currentlySelectedId); ?>">

            <!-- SECTION 1: PLANTS -->
            <div class="location-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                        </svg>
                        Available Plants
                    </h3>
                    <span class="section-badge"><?php echo count($plants); ?> Plants</span>
                </div>

                <?php if (empty($plants)): ?>
                    <p style="color: #64748B; font-size: 14px; margin: 0 0 16px;">No plants found under this division.</p>
                <?php else: ?>
                    <div class="grid-list">
                        <?php foreach ($plants as $p): 
                            $isSel = ($currentlySelectedType === 'plant' && (string)$currentlySelectedId === (string)$p['plant_id']);
                        ?>
                            <div class="location-card plant-card <?php echo $isSel ? 'selected' : ''; ?>"
                                 data-type="plant"
                                 data-id="<?php echo htmlspecialchars($p['plant_id']); ?>"
                                 data-name="<?php echo htmlspecialchars($p['plant_name']); ?>"
                                 onclick="selectLocation('plant', '<?php echo htmlspecialchars($p['plant_id']); ?>', this)">
                                <span class="selected-ribbon">✓ SELECTED</span>
                                <div class="card-top">
                                    <div class="card-icon icon-plant">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 21h18"></path>
                                            <path d="M19 21v-8l-5 4V9l-5 4V3H3v18"></path>
                                        </svg>
                                    </div>
                                    <div class="card-info">
                                        <div class="card-name" title="<?php echo htmlspecialchars($p['plant_name']); ?>">
                                            <?php echo htmlspecialchars($p['plant_name']); ?>
                                        </div>
                                        <div class="card-meta">Code: <strong><?php echo htmlspecialchars($p['plant_code']); ?></strong></div>
                                        <div class="card-meta">Location: <strong><?php echo htmlspecialchars($p['location'] ?: '—'); ?></strong></div>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <button type="button" class="select-btn">
                                        <?php echo $isSel ? 'Selected Plant ✓' : 'Select Plant →'; ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SECTION 2: WAREHOUSES -->
            <div class="location-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                            <line x1="12" y1="22.08" x2="12" y2="12"></line>
                        </svg>
                        Available Warehouses
                    </h3>
                    <span class="section-badge"><?php echo count($warehouses); ?> Warehouses</span>
                </div>

                <?php if (empty($warehouses)): ?>
                    <p style="color: #64748B; font-size: 14px; margin: 0 0 16px;">No warehouses found under this division.</p>
                <?php else: ?>
                    <div class="grid-list">
                        <?php foreach ($warehouses as $w): 
                            $isSel = ($currentlySelectedType === 'warehouse' && (string)$currentlySelectedId === (string)$w['warehouse_id']);
                        ?>
                            <div class="location-card warehouse-card <?php echo $isSel ? 'selected' : ''; ?>"
                                 data-type="warehouse"
                                 data-id="<?php echo htmlspecialchars($w['warehouse_id']); ?>"
                                 data-name="<?php echo htmlspecialchars($w['warehouse_name']); ?>"
                                 onclick="selectLocation('warehouse', '<?php echo htmlspecialchars($w['warehouse_id']); ?>', this)">
                                <span class="selected-ribbon">✓ SELECTED</span>
                                <div class="card-top">
                                    <div class="card-icon icon-warehouse">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="1" y="3" width="22" height="18" rx="2" ry="2"></rect>
                                            <line x1="3" y1="9" x2="21" y2="9"></line>
                                            <line x1="9" y1="21" x2="9" y2="9"></line>
                                        </svg>
                                    </div>
                                    <div class="card-info">
                                        <div class="card-name" title="<?php echo htmlspecialchars($w['warehouse_name']); ?>">
                                            <?php echo htmlspecialchars($w['warehouse_name']); ?>
                                        </div>
                                        <div class="card-meta">Code: <strong><?php echo htmlspecialchars($w['warehouse_code']); ?></strong></div>
                                        <?php if (!empty($w['plant_name'])): ?>
                                            <div class="card-meta">Plant: <strong><?php echo htmlspecialchars($w['plant_name']); ?></strong></div>
                                        <?php endif; ?>
                                        <?php if (!empty($w['plant_location'])): ?>
                                            <div class="card-meta">Location: <strong><?php echo htmlspecialchars($w['plant_location']); ?></strong></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <button type="button" class="select-btn">
                                        <?php echo $isSel ? 'Selected Warehouse ✓' : 'Select Warehouse →'; ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- GLOBAL ADMIN SCOPE CARD -->
            <?php if ($isAdmin): ?>
                <div style="margin-top: 10px; margin-bottom: 24px;">
                    <div class="location-card" style="border-style: dashed; background: #FAFDFE;" onclick="selectLocation('plant', 'ALL', this)">
                        <div class="card-top" style="margin-bottom: 0;">
                            <div class="card-icon" style="background: #E2E8F0; color: #475569;">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="2" y1="12" x2="22" y2="12"></line>
                                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                                </svg>
                            </div>
                            <div class="card-info">
                                <div class="card-name">All Operational Locations (Global Admin Scope)</div>
                                <div class="card-meta">Access all plants and warehouses without restricting to a single facility.</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ACTION BAR -->
            <div class="action-bar">
                <button type="submit" id="continueBtn" class="btn-continue" <?php echo empty($currentlySelectedId) ? 'disabled' : ''; ?>>
                    <span>Continue to Period Selection</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function selectLocation(type, id, element) {
        // Enforce single selection: remove selected from all cards
        document.querySelectorAll('.location-card').forEach(card => {
            card.classList.remove('selected');
            const btn = card.querySelector('.select-btn');
            if (btn) {
                const cardType = card.getAttribute('data-type');
                btn.textContent = cardType === 'plant' ? 'Select Plant →' : 'Select Warehouse →';
            }
        });

        // Set selected on target card
        element.classList.add('selected');
        const btn = element.querySelector('.select-btn');
        if (btn) {
            btn.textContent = (type === 'plant' ? 'Selected Plant ✓' : 'Selected Warehouse ✓');
        }

        // Update hidden inputs
        document.getElementById('selectedLocationType').value = type;
        document.getElementById('selectedLocationId').value = id;

        // Enable continue button
        const continueBtn = document.getElementById('continueBtn');
        continueBtn.removeAttribute('disabled');

        // Automatically submit after a brief visual confirmation (or allow user to click continue)
        setTimeout(() => {
            document.getElementById('locationForm').submit();
        }, 220);
    }
</script>
</body>
</html>
