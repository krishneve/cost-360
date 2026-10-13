<?php
/**
 * Cost360
 * Customer Selection
 *
 * Top-level Customer Context Selection for Platform Admin and Multi-Customer Users.
 */

require_once __DIR__ . '/config/auth.php';

requireAuth();

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: login.php');
    exit;
}

$errorMessage = '';

/* ============================================================
   HANDLE CUSTOMER SELECTION
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = trim($_POST['customer_id'] ?? '');

    if ($customerId === '') {
        $errorMessage = 'Please select a customer.';
    } else {
        if (setActiveCustomer($customerId)) {
            header('Location: company-selection.php');
            exit;
        } else {
            $errorMessage = 'You do not have access to the selected customer.';
        }
    }
}

/* ============================================================
   FETCH ACCESSIBLE CUSTOMERS
   ============================================================ */

$customers = getUserAccessibleCustomers($userId);
$isAdmin = isPlatformAdmin($userId);

/* ============================================================
   AUTO-SELECT SINGLE CUSTOMER (For non-admin)
   ============================================================ */

if (!$isAdmin && count($customers) === 1 && empty($_POST)) {
    $singleCustomer = $customers[0];
    if (setActiveCustomer($singleCustomer['customer_id'])) {
        header('Location: company-selection.php');
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
    <title>Cost360 - Select Customer Context</title>
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
            margin-bottom: 30px;
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
        .welcome {
            background: #FFFFFF;
            border-radius: 16px;
            padding: 22px 26px;
            margin-bottom: 24px;
            box-shadow: 0 8px 25px rgba(3, 54, 101, 0.07);
            border: 1px solid #E1EAF0;
        }
        .welcome-label { font-size: 13px; color: #6B7F91; margin-bottom: 5px; }
        .welcome-name { font-size: 20px; font-weight: 700; color: #17324D; }
        .section-title { margin: 0 0 18px; font-size: 20px; font-weight: 700; color: #17324D; }
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
        .logout-container { text-align: center; margin-top: 30px; }
        .logout { color: #6B7F91; font-size: 14px; text-decoration: none; }
        .logout:hover { color: #0878D1; }
    </style>
    <link rel="stylesheet" href="assets/css/cost360-core.css">
</head>
<body>
<div class="page">
    <div class="container">
        <div class="brand">
            <img src="assets/images/cost360-logo-navy.png" alt="Cost360 Logo" style="height: 48px; width: auto; margin-bottom: 8px;">
            <p>Select Customer Context</p>
        </div>

        <div class="welcome">
            <div class="welcome-label">Welcome</div>
            <div class="welcome-name"><?php echo htmlspecialchars($userName); ?></div>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <h2 class="section-title">Select Customer Scope</h2>

        <div class="grid-list">
            <?php if ($isAdmin): ?>
                <form method="POST" class="card-form">
                    <input type="hidden" name="customer_id" value="ALL">
                    <button type="submit" class="card-button">
                        <div class="card-item global-card">
                            <div>
                                <div class="card-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="2" y1="12" x2="22" y2="12"/>
                                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                                    </svg>
                                </div>
                                <div class="card-name">All Customers (Global Admin)</div>
                                <div class="card-code">Global Access Across All Tenants</div>
                            </div>
                            <div class="select-text">
                                <span>Select Global Scope</span>
                                <span>&rarr;</span>
                            </div>
                        </div>
                    </button>
                </form>
            <?php endif; ?>

            <?php foreach ($customers as $c): ?>
                <form method="POST" class="card-form">
                    <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($c['customer_id']); ?>">
                    <button type="submit" class="card-button">
                        <div class="card-item">
                            <div>
                                <div class="card-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                        <circle cx="9" cy="7" r="4"/>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                    </svg>
                                </div>
                                <div class="card-name"><?php echo htmlspecialchars($c['customer_name']); ?></div>
                                <div class="card-code">Code: <?php echo htmlspecialchars($c['customer_code'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="select-text">
                                <span>Select Customer</span>
                                <span>&rarr;</span>
                            </div>
                        </div>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>

        <div class="logout-container">
            <a href="logout.php" class="logout">Sign Out</a>
        </div>
    </div>
</div>
</body>
</html>
