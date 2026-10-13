<?php
/**
 * Cost360 - Think Beyond Cost
 * Enterprise Cost Management & Manufacturing Analytics Platform
 *
 * Authentication Flow:
 *
 * Login
 *   ↓
 * Verify Email + Password
 *   ↓
 * Identify User
 *   ↓
 * Check Organization Access
 *   ↓
 * Find Accessible Companies
 *   ↓
 * ┌─────────────────────────────────────┐
 * │ 0 Companies → No Company Access     │
 * │ 1 Company  → Auto Select            │
 * │ 2+ Companies → Company Selection    │
 * └─────────────────────────────────────┘
 *   ↓
 * Active Company
 *   ↓
 * Dashboard
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';


/* ============================================================
   REQUEST TYPE
   ============================================================ */

$isAjax =
    (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower(
            $_SERVER['HTTP_X_REQUESTED_WITH']
        ) === 'xmlhttprequest'
    )
    ||
    (
        isset($_SERVER['HTTP_ACCEPT']) &&
        strpos(
            $_SERVER['HTTP_ACCEPT'],
            'application/json'
        ) !== false
    )
    ||
    (
        isset($_POST['is_ajax']) &&
        $_POST['is_ajax'] === '1'
    );


/* ============================================================
   REDIRECT IF ALREADY AUTHENTICATED
   ============================================================ */

if (isLoggedIn()) {

    if (isPlatformAdmin() || isCmaAdmin()) {
        requireActiveOrganizationContext();
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'redirect' => 'dashboard.php'
            ]);
            exit;
        }
        header('Location: dashboard.php');
        exit;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        autoResolveSingleScopeChain($userId);
    }
    $ctx = getActiveOrganizationContext();

    if ($ctx && !empty($ctx['company_id'])) {
        $divs = getUserAccessibleDivisions($userId, $ctx['company_id']);
        $hasDiv = !empty($ctx['division_id']) || empty($divs);

        $locs = (!empty($ctx['division_id'])) ? getUserAccessibleLocations($userId, $ctx['company_id'], $ctx['division_id']) : [];
        $hasLoc = !empty($ctx['plant_id']) || !empty($ctx['warehouse_id']) || empty($locs);

        $pers = getUserAccessiblePeriods($ctx['company_id'], $userId);
        $hasPer = !empty($ctx['period_id']) || empty($pers);

        if ($hasDiv && $hasLoc && $hasPer) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'redirect' => 'dashboard.php'
                ]);
                exit;
            }
            header('Location: dashboard.php');
            exit;
        }
    }


    /*
     * User is authenticated but does not have
     * an active company.
     *
     * Send them to company selection.
     */

    if ($isAjax) {

        header(
            'Content-Type: application/json'
        );

        echo json_encode([
            'success' => true,
            'redirect' =>
                'company-selection.php'
        ]);

        exit;
    }

    header(
        'Location: company-selection.php'
    );

    exit;
}


/* ============================================================
   VARIABLES
   ============================================================ */

$errorMessage = '';

/*
 * requireAuth() sends users whose role was deactivated back to login.php
 * with this code. Show the security message after the session is destroyed.
 */
if (($_GET['error'] ?? '') === 'role_inactive') {
    $errorMessage =
        'Your role has been deactivated. Please contact your administrator.';
}

$submittedEmail = '';
$rememberChecked = false;
if (!empty($_COOKIE['cost360_remember_email'])) {
    $submittedEmail = htmlspecialchars($_COOKIE['cost360_remember_email']);
    $rememberChecked = true;
}


/* ============================================================
   LOGIN PROCESS
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email =
        trim(
            $_POST['email'] ?? ''
        );

    $password =
        $_POST['password'] ?? '';

    $remember =
        !empty($_POST['remember']);

    $rememberChecked = $remember;

    $submittedEmail =
        htmlspecialchars(
            $email
        );


    /* ========================================================
       BASIC VALIDATION
       ======================================================== */

    if (
        empty($email) ||
        empty($password)
    ) {

        $errorMessage =
            'Please enter both email and password.';

    } else {

        try {

            $pdo =
                getDbConnection();


            /* =================================================
               FETCH USER
               ================================================= */

            $stmt =
                $pdo->prepare('

                    SELECT
                        user_id,
                        username,
                        email,
                        password_hash,
                        first_name,
                        last_name,
                        status,
                        is_email_verified,
                        failed_login_attempts,
                        locked_until

                    FROM users

                    WHERE email = :email

                    LIMIT 1

                ');

            $stmt->execute([
                ':email' => $email
            ]);

            $user =
                $stmt->fetch();


            /* =================================================
               USER NOT FOUND
               ================================================= */

            if (!$user) {

                $errorMessage =
                    'Invalid email or password.';

            } else {

                error_log(
                    'Authentication user loaded from ' .
                    'Neon auth.users table: user_id=' .
                    $user['user_id'] .
                    ', email=' .
                    $user['email']
                );


                /* =============================================
                   USER STATUS
                   ============================================= */

                $status =
                    strtoupper(
                        $user['status']
                    );

                $now =
                    new DateTime();


                /* =============================================
                   CHECK LOCK STATUS
                   ============================================= */

                $isLocked = false;

                if (
                    !empty(
                        $user['locked_until']
                    )
                ) {

                    $lockedUntil =
                        new DateTime(
                            $user['locked_until']
                        );

                    if (
                        $lockedUntil > $now
                    ) {

                        $isLocked = true;
                    }
                }


                /* =============================================
                   ACCOUNT STATUS VALIDATION
                   ============================================= */

                if (
                    $status === 'INACTIVE'
                ) {

                    $errorMessage =
                        'Your account is currently inactive. Please contact support.';

                } elseif (
                    $status === 'SUSPENDED'
                ) {

                    $errorMessage =
                        'Your account has been suspended. Please contact support.';

                } elseif (
                    $status === 'LOCKED' ||
                    $isLocked
                ) {

                    $errorMessage =
                        'Your account is locked due to multiple failed attempts. Please try again later or contact support.';

                } else {

                    $isPasswordValid =
                        false;


                    /* =========================================
                       PASSWORD VERIFICATION
                       ========================================= */

                    /*
                     * First try secure password_hash()
                     * verification.
                     */

                    if (
                        verifyPassword(
                            $password,
                            $user['password_hash']
                        )
                    ) {

                        $isPasswordValid =
                            true;

                    }

                    /*
                     * Legacy plaintext fallback.
                     *
                     * If an old record still contains a
                     * plaintext password, authenticate it once
                     * and immediately upgrade it to a secure hash.
                     */

                    elseif (
                        $password ===
                        $user['password_hash']
                    ) {

                        $isPasswordValid =
                            true;


                        $newSecureHash =
                            hashPassword(
                                $password
                            );


                        $upgradeStmt =
                            $pdo->prepare('

                                UPDATE users

                                SET password_hash =
                                    :hash

                                WHERE user_id =
                                    :user_id

                            ');

                        $upgradeStmt->execute([
                            ':hash' =>
                                $newSecureHash,

                            ':user_id' =>
                                $user['user_id']
                        ]);
                    }


                    /* =========================================
                       SUCCESSFUL PASSWORD
                       ========================================= */

                    if ($isPasswordValid) {

                        /* =====================================
                           CHECK ASSIGNED ROLE STATUS
                           ===================================== */

                        /*
                         * Do not create an authenticated session unless the
                         * user has at least one ACTIVE role assignment.
                         * getCurrentUserRole() checks both roles.status and
                         * user_roles.status.
                         */
                        $currentRole = getCurrentUserRole($user['user_id']);

                        if (!$currentRole) {

                            $errorMessage =
                                'Your assigned role is currently inactive. Please contact your administrator.';

                        } else {

                        /* =====================================
                           RESET LOGIN FAILURE INFORMATION
                           ===================================== */

                        $updateStmt =
                            $pdo->prepare('

                                UPDATE users

                                SET
                                    failed_login_attempts = 0,

                                    locked_until = NULL,

                                    status =
                                        CASE
                                            WHEN status =
                                                \'LOCKED\'
                                            THEN
                                                \'ACTIVE\'
                                            ELSE
                                                status
                                        END,

                                    last_login_at =
                                        CURRENT_TIMESTAMP,

                                    updated_at =
                                        CURRENT_TIMESTAMP

                                WHERE user_id =
                                    :user_id

                            ');

                        $updateStmt->execute([
                            ':user_id' =>
                                $user['user_id']
                        ]);


                        /* =====================================
                           CREATE NEW LOGIN SESSION
                           ===================================== */

                        session_regenerate_id(
                            true
                        );

                        if ($remember) {
                            setcookie('cost360_remember_email', $email, time() + (86400 * 30), '/', '', false, true);
                        } else {
                            setcookie('cost360_remember_email', '', time() - 3600, '/');
                        }

                        $_SESSION['user_id'] =
                            $user['user_id'];

                        $_SESSION['email'] =
                            $user['email'];

                        $_SESSION['username'] =
                            $user['username'];

                        $_SESSION['first_name'] =
                            $user['first_name'];

                        $_SESSION['last_name'] =
                            $user['last_name'] ?? '';

                        $_SESSION['role_id'] =
                            $currentRole['role_id'] ?? null;

                        $_SESSION['role_code'] =
                            $currentRole['role_code'] ?? '';

                        $_SESSION['role_name'] =
                            $currentRole['role_name'] ?? '';

                        $_SESSION['role_status'] =
                            $currentRole['status'] ?? 'Active';


                        /*
                         * Clear any active company from
                         * a previous session.
                         *
                         * This prevents a previously selected
                         * company from carrying into a new login.
                         */

                        clearActiveCompany();


                        /* =====================================
                           GET USER'S ACCESSIBLE COMPANIES
                           ===================================== */
/* ============================================================
   PLATFORM ADMIN & CMA ADMIN
   ============================================================ */

if (isPlatformAdmin($user['user_id']) || isCmaAdmin($user['user_id'])) {

    requireActiveOrganizationContext();

    if ($isAjax) {

        header(
            'Content-Type: application/json'
        );

        echo json_encode([
            'success' => true,
            'redirect' => 'dashboard.php'
        ]);

        exit;
    }

    header(
        'Location: dashboard.php'
    );

    exit;
}
                        autoResolveSingleScopeChain($user['user_id']);
                        $accessibleCompanies = getUserAccessibleCompanies($user['user_id']);
                        $companyCount = count($accessibleCompanies);

                        if ($companyCount === 0) {
                            $errorMessage = 'Your account is authenticated, but no company access has been assigned.';
                            $_SESSION = [];
                            if (ini_get('session.use_cookies')) {
                                $params = session_get_cookie_params();
                                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                            }
                            session_destroy();
                        } else {
                            $nextRedirect = 'company-selection.php';
                            if (empty($_SESSION['active_company_id'])) {
                                $nextRedirect = 'company-selection.php';
                            } else {
                                $divs = getUserAccessibleDivisions($user['user_id'], $_SESSION['active_company_id']);
                                if (empty($_SESSION['active_division_id']) && count($divs) > 1) {
                                    $nextRedirect = 'division-selection.php';
                                } else {
                                    $divId = $_SESSION['active_division_id'] ?? null;
                                    $locs = ($divId) ? getUserAccessibleLocations($user['user_id'], $_SESSION['active_company_id'], $divId) : [];
                                    $hasLoc = !empty($_SESSION['active_plant_id']) || !empty($_SESSION['active_warehouse_id']);
                                    if (!$hasLoc && count($locs) > 1) {
                                        $nextRedirect = 'organization-selection.php';
                                    } else {
                                        $pers = getUserAccessiblePeriods($_SESSION['active_company_id'], $user['user_id'], $divId, $_SESSION['active_location_type'] ?? null, $_SESSION['active_plant_id'] ?? $_SESSION['active_warehouse_id'] ?? null);
                                        if (empty($_SESSION['active_period_id']) && count($pers) > 1) {
                                            $nextRedirect = 'period-selection.php';
                                        } else {
                                            $nextRedirect = 'dashboard.php';
                                        }
                                    }
                                }
                            }

                            if ($isAjax) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => true,
                                    'redirect' => $nextRedirect
                                ]);
                                exit;
                            }

                            header('Location: ' . $nextRedirect);
                            exit;
                        }
                    }

                    }

                    /* =========================================
                       INVALID PASSWORD
                       ========================================= */

                    else {

                        $newFailedAttempts =
                            (int)
                            $user[
                                'failed_login_attempts'
                            ] + 1;


                        /* =====================================
                           LOCK AFTER 5 FAILED ATTEMPTS
                           ===================================== */

                        if (
                            $newFailedAttempts >= 5
                        ) {

                            $lockStmt =
                                $pdo->prepare('

                                    UPDATE users

                                    SET
                                        failed_login_attempts =
                                            :attempts,

                                        locked_until =
                                            CURRENT_TIMESTAMP
                                            +
                                            INTERVAL
                                            \'15 minutes\',

                                        status =
                                            \'LOCKED\',

                                        updated_at =
                                            CURRENT_TIMESTAMP

                                    WHERE user_id =
                                        :user_id

                                ');

                            $lockStmt->execute([
                                ':attempts' =>
                                    $newFailedAttempts,

                                ':user_id' =>
                                    $user['user_id']
                            ]);

                        } else {

                            /* =================================
                               UPDATE FAILED ATTEMPT COUNT
                               ================================= */

                            $failStmt =
                                $pdo->prepare('

                                    UPDATE users

                                    SET
                                        failed_login_attempts =
                                            :attempts,

                                        updated_at =
                                            CURRENT_TIMESTAMP

                                    WHERE user_id =
                                        :user_id

                                ');

                            $failStmt->execute([
                                ':attempts' =>
                                    $newFailedAttempts,

                                ':user_id' =>
                                    $user['user_id']
                            ]);
                        }


                        $errorMessage =
                            'Invalid email or password.';
                    }
                }
            }

        } catch (Exception $e) {

            error_log(
                'Login error: ' .
                $e->getMessage()
            );

            $errorMessage =
                $e->getMessage();
        }
    }


    /* ========================================================
       AJAX ERROR RESPONSE
       ======================================================== */

    if ($isAjax) {

        header(
            'Content-Type: application/json'
        );

        echo json_encode([
            'success' => false,
            'error' =>
                $errorMessage
        ]);

        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cost360 - Think Beyond Cost | Sign In</title>
  <meta name="description" content="Sign in to your Cost360 account for manufacturing cost intelligence and product costing analytics.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/login.css?v=<?php echo time(); ?>">
  <style>
    /* ==========================================================================
       Cost360 - Enterprise Login Styles (Reference Design Exact Match)
       Theme: Manufacturing Cost Intelligence & Financial Analytics Platform
       ========================================================================== */
    :root {
      --color-navy-dark: #021E3B;
      --color-navy-deep: #032A52;
      --color-primary-blue: #0077D7;
      --color-blue-hover: #0060B6;
      --color-cyan-glow: #00CFEF;
      --color-card-text: #0E2942;
      --color-card-muted: #557086;
      --color-card-border: rgba(255, 255, 255, 0.65);
      --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    }

    *, *::before, *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html, body {
      width: 100%;
      min-height: 100vh;
      font-family: var(--font-sans);
      color: var(--color-card-text);
      background-color: #06407B;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      overflow-x: hidden;
    }

    .login-page-wrapper {
      position: relative;
      width: 100%;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 2rem 1.25rem;
      background: #06407B url('assets/images/login-bg.jpg?v=<?php echo time(); ?>') no-repeat center center;
      background-size: cover;
      overflow-x: hidden;
    }

    .bg-artwork-overlay {
      position: absolute;
      inset: 0;
      background: transparent;
      pointer-events: none;
      z-index: 1;
    }

    .login-main-container {
      position: relative;
      z-index: 2;
      width: 100%;
      max-width: 450px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      align-items: center;
      animation: cardEntrance 500ms cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes cardEntrance {
      0% {
        opacity: 0;
        transform: translateY(14px) scale(0.99);
      }
      100% {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .brand-logo-container {
      margin-bottom: 1.35rem;
      text-align: center;
      width: 100%;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .brand-logo-link {
      display: inline-block;
      text-decoration: none;
      outline: none;
    }

    .brand-logo-img {
      width: 260px !important;
      max-width: 85vw !important;
      height: auto !important;
      display: block !important;
      margin: 0 auto !important;
      filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.25));
      transition: transform 0.2s ease;
    }

    .brand-logo-img:hover {
      transform: scale(1.015);
    }

    .login-card {
      width: 100%;
      background: rgba(242, 248, 254, 0.84);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.75);
      border-radius: 18px;
      box-shadow: 0 16px 40px rgba(2, 28, 64, 0.2), 0 0 0 1px rgba(255, 255, 255, 0.5) inset;
      padding: 36px 36px 30px 36px;
      position: relative;
    }

    .card-header {
      text-align: center;
      margin-bottom: 1.55rem;
    }

    .card-title {
      font-size: 28px;
      font-weight: 700;
      color: #0E2942;
      letter-spacing: -0.015em;
      line-height: 1.2;
      margin-bottom: 0.3rem;
      user-select: none;
      outline: none;
      cursor: default;
    }

    .card-subtitle {
      font-size: 13px;
      font-weight: 400;
      color: #587389;
      line-height: 1.35;
      user-select: none;
    }

    .feedback-alert {
      display: none;
      opacity: 0;
      transform: translateY(-4px);
      transition: opacity 0.2s ease, transform 0.2s ease;
      margin-bottom: 0.95rem;
      padding: 0.6rem 0.95rem;
      border-radius: 6px;
      font-size: 0.8rem;
      font-weight: 500;
      line-height: 1.35;
      align-items: center;
      gap: 0.55rem;
    }

    .feedback-alert.show {
      display: flex;
      opacity: 1;
      transform: translateY(0);
    }

    .feedback-alert.error {
      background: rgba(254, 226, 226, 0.95);
      border: 1px solid #FCA5A5;
      color: #B91C1C;
    }

    .feedback-alert.info {
      background: rgba(224, 242, 254, 0.95);
      border: 1px solid #BAE6FD;
      color: #0369A1;
    }

    .alert-icon {
      flex-shrink: 0;
    }

    .login-form {
      display: flex;
      flex-direction: column;
    }

    .form-group {
      margin-bottom: 0.85rem;
    }

    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
      height: 45px;
      background: #FFFFFF;
      border: 1px solid #D5E2EE;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(3, 27, 52, 0.04);
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .input-wrapper:hover {
      border-color: #A4C2DE;
    }

    .input-wrapper:focus-within {
      border-color: #0077D7;
      box-shadow: 0 0 0 2px rgba(0, 119, 215, 0.16);
    }

    .input-wrapper.has-error {
      border-color: #DC2626 !important;
      box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.16) !important;
    }

    .input-icon-prefix {
      position: absolute;
      left: 14px;
      color: #0077D7;
      pointer-events: none;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .form-input {
      width: 100%;
      height: 100%;
      padding: 0 42px 0 42px;
      background: transparent;
      border: none;
      outline: none;
      font-size: 13.5px;
      font-family: inherit;
      color: #0E2942;
      font-weight: 500;
    }

    .form-input::placeholder {
      color: #8E9FAE;
      opacity: 1;
    }

    .toggle-password-btn {
      position: absolute;
      right: 12px;
      background: transparent;
      border: none;
      color: #7990A4;
      cursor: pointer;
      padding: 6px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color 0.2s ease;
    }

    .toggle-password-btn:hover {
      color: #0077D7;
    }

    .form-options {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 0.65rem;
      margin-bottom: 1.15rem;
      font-size: 12.5px;
    }

    .checkbox-label {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      color: #334E68;
      font-weight: 400;
      font-size: 12.5px;
      cursor: pointer;
      user-select: none;
    }

    .custom-checkbox {
      width: 15px;
      height: 15px;
      border: 1.2px solid #BACBDB;
      border-radius: 3px;
      accent-color: #0077D7;
      cursor: pointer;
      margin: 0;
    }

    .forgot-password-link {
      color: #007CE0;
      font-size: 12.5px;
      font-weight: 500;
      text-decoration: none;
      transition: color 0.2s ease;
    }

    .forgot-password-link:hover {
      color: #005FA8;
      text-decoration: underline;
    }

    .btn-primary {
      width: 100%;
      height: 45px;
      background: linear-gradient(180deg, #027BE5 0%, #006CCE 100%);
      color: #FFFFFF;
      font-size: 13.5px;
      font-weight: 700;
      letter-spacing: 0.05em;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 3px 12px rgba(0, 114, 220, 0.28);
      transition: all 0.2s ease;
      outline: none;
    }

    .btn-primary:hover:not(:disabled) {
      background: #0066C0;
      box-shadow: 0 4px 14px rgba(0, 102, 192, 0.35);
      transform: translateY(-1px);
    }

    .btn-primary:active:not(:disabled) {
      transform: translateY(0);
      box-shadow: 0 2px 6px rgba(0, 102, 192, 0.25);
    }

    .btn-primary:disabled {
      opacity: 0.75;
      cursor: not-allowed;
      transform: none;
    }

    .btnText {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    .btn-arrow-icon {
      flex-shrink: 0;
      transition: transform 0.2s ease;
    }

    .btn-primary:hover:not(:disabled) .btn-arrow-icon {
      transform: translateX(2px);
    }

    .btn-spinner {
      width: 16px;
      height: 16px;
      border: 2px solid rgba(255, 255, 255, 0.35);
      border-top-color: #FFFFFF;
      border-radius: 50%;
      animation: spin 0.75s linear infinite;
      display: inline-block;
      margin-right: 0.45rem;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    .or-divider {
      display: flex;
      align-items: center;
      text-align: center;
      margin: 1.1rem 0 1rem 0;
    }

    .or-divider::before,
    .or-divider::after {
      content: '';
      flex: 1;
      border-bottom: 1px solid rgba(175, 200, 225, 0.55);
    }

    .or-divider span {
      padding: 0 0.65rem;
      color: #768E9F;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.05em;
    }

    .card-support-link {
      text-align: center;
      font-size: 12.5px;
      color: #587186;
      font-weight: 400;
    }

    .support-anchor {
      color: #0077D7;
      font-weight: 600;
      text-decoration: none;
      margin-left: 3px;
      transition: color 0.2s ease;
    }

    .support-anchor:hover {
      color: #005FA8;
      text-decoration: underline;
    }

    .info-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(3, 24, 48, 0.65);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      padding: 1.25rem;
    }

    .info-modal-card {
      background: #FFFFFF;
      border-radius: 16px;
      max-width: 400px;
      width: 100%;
      padding: 2rem 1.75rem 1.65rem 1.75rem;
      text-align: center;
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.3);
    }

    .info-modal-icon {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: rgba(0, 119, 215, 0.1);
      color: #0077D7;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1rem auto;
    }

    .info-modal-title {
      font-size: 1.15rem;
      font-weight: 700;
      color: #0E2942;
      margin-bottom: 0.5rem;
    }

    .info-modal-body {
      font-size: 0.85rem;
      color: #556B82;
      line-height: 1.5;
      margin-bottom: 1.35rem;
    }

    .info-modal-close-btn {
      padding: 0.6rem 1.65rem;
      background: #0077D7;
      color: #FFFFFF;
      font-weight: 600;
      font-size: 0.85rem;
      border: none;
      border-radius: 7px;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(0, 119, 215, 0.3);
      transition: background-color 0.2s ease;
    }

    .info-modal-close-btn:hover {
      background: #005FA8;
    }

    @media (max-width: 768px) {
      .login-main-container {
        max-width: 420px;
      }
      .brand-logo-img {
        width: 240px !important;
      }
      .login-card {
        padding: 30px 28px 24px 28px;
      }
    }

    @media (max-width: 500px) {
      .login-page-wrapper {
        padding: 1.5rem 1rem;
      }
      .login-main-container {
        width: calc(100% - 32px);
        max-width: 380px;
        margin: 0 auto;
        animation: none;
      }
      .brand-logo-container {
        margin-bottom: 1.15rem;
      }
      .brand-logo-img {
        width: 210px !important;
      }
      .login-card {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        padding: 28px 20px 22px 20px;
        border-radius: 15px;
      }
      .card-title {
        font-size: 25px;
      }
      .card-subtitle {
        font-size: 12px;
      }
      .input-wrapper {
        height: 43px;
      }
      .btn-primary {
        height: 43px;
        font-size: 13px;
      }
      .card-support-link {
        font-size: 11.5px;
        line-height: 1.4;
      }
    }
  </style>
</head>
<body>
  <div class="login-page-wrapper">
    <!-- Ambient artwork overlay -->
    <div class="bg-artwork-overlay" aria-hidden="true"></div>

    <div class="login-main-container">
      <!-- Centered Cost360 Logo -->
      <header class="brand-logo-container">
        <a href="login.php" class="brand-logo-link" aria-label="Cost360 Home">
          <img 
            src="assets/images/cost360-logo-white.png?v=<?php echo time(); ?>" 
            alt="Cost360 - Think Beyond Cost" 
            class="brand-logo-img"
            width="260"
            height="104"
          >
        </a>
      </header>

      <!-- Glassmorphic Login Card -->
      <main class="login-card" id="loginCard">
        <div class="card-header">
          <h1 class="card-title">Sign in</h1>
          <p class="card-subtitle">Access your Cost360 account</p>
        </div>

        <!-- Feedback Alert Container -->
        <div 
          class="feedback-alert feedbackAlert <?php echo !empty($errorMessage) ? 'show error' : ''; ?>" 
          role="alert" 
          aria-live="polite"
          id="loginAlert"
        >
          <svg class="alert-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <span class="alertText">
            <?php echo !empty($errorMessage) ? htmlspecialchars($errorMessage) : 'Invalid email or password.'; ?>
          </span>
        </div>

        <!-- Login Form -->
        <form class="login-form loginForm" id="loginForm" method="POST" action="login.php" novalidate>
          <!-- Email Field -->
          <div class="form-group">
            <div class="input-wrapper">
              <span class="input-icon-prefix" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                  <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
              </span>
              <input 
                type="email" 
                id="emailInput" 
                name="email" 
                class="form-input" 
                placeholder="Enter your email" 
                value="<?php echo $submittedEmail; ?>" 
                required 
                autocomplete="email"
                spellcheck="false"
              >
            </div>
          </div>

          <!-- Password Field -->
          <div class="form-group">
            <div class="input-wrapper">
              <span class="input-icon-prefix" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
              </span>
              <input 
                type="password" 
                id="passwordInput" 
                name="password" 
                class="form-input passwordInput" 
                placeholder="Enter your password" 
                required 
                autocomplete="current-password"
              >
              <button 
                type="button" 
                class="toggle-password-btn togglePassword" 
                id="togglePasswordBtn"
                aria-label="Show password"
                title="Toggle password visibility"
              >
                <svg class="eyeIconOpen" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
                <svg class="eyeIconClosed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                  <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                  <line x1="1" y1="1" x2="23" y2="23"></line>
                </svg>
              </button>
            </div>
          </div>

          <!-- Options: Remember me & Forgot Password -->
          <div class="form-options">
            <label class="checkbox-label" for="rememberMe">
              <input 
                type="checkbox" 
                id="rememberMe" 
                name="remember" 
                class="custom-checkbox"
                <?php echo !empty($rememberChecked) ? 'checked' : ''; ?>
              >
              <span>Remember me</span>
            </label>
            <a href="javascript:void(0)" class="forgot-password-link" id="forgotPasswordLink">
              Forgot Password?
            </a>
          </div>

          <!-- Primary Action Button -->
          <button type="submit" class="btn-primary submitBtn" id="submitBtn">
            <span class="btn-spinner btnSpinner" style="display: none;" aria-hidden="true"></span>
            <span class="btnText">
              <span>SIGN IN</span>
              <svg class="btn-arrow-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </span>
          </button>

          <!-- Divider -->
          <div class="or-divider" role="separator">
            <span>OR</span>
          </div>

          <!-- Footer Support Link -->
          <div class="card-support-link">
            Need help accessing your account?
            <a href="javascript:void(0)" class="support-anchor" id="contactSupportLink">
              Contact support
            </a>
          </div>
        </form>
      </main>
    </div>

    <!-- Info Modals for Forgot Password & Support -->
    <div class="info-modal-backdrop" id="infoModalBackdrop" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="infoModalTitle">
      <div class="info-modal-card">
        <div class="info-modal-icon" aria-hidden="true">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
          </svg>
        </div>
        <h2 class="info-modal-title" id="infoModalTitle">Account Assistance</h2>
        <p class="info-modal-body" id="infoModalBody">Please contact your organization administrator or support team for assistance.</p>
        <button type="button" class="info-modal-close-btn" id="infoModalCloseBtn">Close</button>
      </div>
    </div>
  </div>

  <!-- Cost360 Login JavaScript Logic -->
  <script src="assets/js/login.js?v=<?php echo time(); ?>"></script>
</body>
</html>