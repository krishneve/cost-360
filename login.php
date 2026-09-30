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

    $ctx = getActiveOrganizationContext();

    if (
        $ctx &&
        !empty($ctx['company_id']) &&
        !empty($ctx['division_id']) &&
        !empty($ctx['plant_id']) &&
        !empty($ctx['warehouse_id']) &&
        !empty($ctx['period_id'])
    ) {

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
                        $accessibleCompanies =
                            getUserAccessibleCompanies(
                                $user['user_id']
                            );


                        $companyCount =
                            count(
                                $accessibleCompanies
                            );


                        /* =====================================
                           NO COMPANY ACCESS
                           ===================================== */

                        if (
                            $companyCount === 0
                        ) {

                            $errorMessage =
                                'Your account is authenticated, but no company access has been assigned.';

                            /*
                             * Remove the authenticated session
                             * because the user cannot enter the
                             * Cost360 application without an
                             * organization/company context.
                             */

                            $_SESSION = [];

                            if (
                                ini_get(
                                    'session.use_cookies'
                                )
                            ) {

                                $params =
                                    session_get_cookie_params();

                                setcookie(
                                    session_name(),
                                    '',
                                    time() - 42000,
                                    $params['path'],
                                    $params['domain'],
                                    $params['secure'],
                                    $params['httponly']
                                );
                            }

                            session_destroy();


                        } else {

                            /* =================================
                               ONE COMPANY
                               ================================= */

                            if (
                                $companyCount === 1
                            ) {

                                $company =
                                    $accessibleCompanies[0];


                                /*
                                 * Automatically select
                                 * the only accessible company.
                                 */

                                $companySelected =
                                    setActiveCompany(
                                        $company['company_id']
                                    );


                                if (
                                    !$companySelected
                                ) {

                                    $errorMessage =
                                        'Unable to set the active company. Please contact support.';

                                } else {

                                    /* =========================
                                       AJAX RESPONSE
                                       ========================= */

                                    if ($isAjax) {

                                        header(
                                            'Content-Type: application/json'
                                        );

                                        echo json_encode([
                                            'success' =>
                                                true,

                                            'redirect' =>
                                                'division-selection.php'
                                        ]);

                                        exit;
                                    }


                                    /* =========================
                                       NORMAL RESPONSE
                                       ========================= */

                                    header(
                                        'Location: division-selection.php'
                                    );

                                    exit;
                                }


                            } else {

                                /* =============================
                                   MULTIPLE COMPANIES
                                   ============================= */

                                /*
                                 * User has access to more than
                                 * one company.
                                 *
                                 * Do not select one automatically.
                                 *
                                 * Send user to company selection.
                                 */

                                if ($isAjax) {

                                    header(
                                        'Content-Type: application/json'
                                    );

                                    echo json_encode([
                                        'success' =>
                                            true,

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

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>
    Cost360 - Think Beyond Cost | Sign In
  </title>

  <meta
    name="description"
    content="Smart cost management and manufacturing analytics for better business decisions. Sign in to your Cost360 cost intelligence workspace."
  >


  <!-- Modern Google Fonts -->

  <link
    rel="preconnect"
    href="https://fonts.googleapis.com"
  >

  <link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
  >

  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
  >


  <!-- Cost360 Login CSS Stylesheet -->

  <link
    rel="stylesheet"
    href="assets/css/login.css"
  >

</head>


<body>


  <!-- ===================================================================
       1. DESKTOP APPROVED LAYOUT (> 768px Viewports)
       =================================================================== -->

  <div class="desktop-layout app-container">


    <!-- LEFT PANEL -->

    <div class="brand-panel">

      <!-- Interactive Ambient Particles Canvas -->

      <canvas
        id="particles-canvas-desktop"
      ></canvas>


      <!-- Exact Reference Hero Graphic Image Layer -->

      <div class="left-hero-container">

        <img
          src="assets/images/left-panel-hero.png"
          alt="Cost360 Manufacturing Analytics & Cost Management"
          class="left-hero-img"
        >

      </div>

    </div>


    <!-- RIGHT PANEL -->

    <div class="auth-panel">


      <!-- Ambient Curve Background Graphics -->

      <div class="right-bg-decoration">

        <svg
          viewBox="0 0 600 600"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >

          <circle
            cx="550"
            cy="500"
            r="380"
            stroke="#0878D1"
            stroke-width="1.2"
            opacity="0.08"
            stroke-dasharray="8 6"
          />

          <circle
            cx="550"
            cy="500"
            r="280"
            stroke="#00CFEF"
            stroke-width="1.5"
            opacity="0.1"
          />

          <circle
            cx="550"
            cy="500"
            r="180"
            stroke="#0878D1"
            stroke-width="1"
            opacity="0.06"
          />

        </svg>

      </div>


      <!-- Centered Desktop Login Card Container -->

      <div class="auth-card-container">

        <main class="login-card">


          <div class="card-header">

            <h2 class="card-title">
              Sign in
            </h2>

          </div>


          <!-- Feedback Alert -->

          <div
            class="feedback-alert feedbackAlert <?php echo !empty($errorMessage) ? 'show error' : ''; ?>"
            role="alert"
            aria-live="polite"
          >

            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            >

              <circle
                cx="12"
                cy="12"
                r="10"
              ></circle>

              <line
                x1="12"
                y1="8"
                x2="12"
                y2="12"
              ></line>

              <line
                x1="12"
                y1="16"
                x2="12.01"
                y2="16"
              ></line>

            </svg>


            <span class="alertText">

              <?php

              echo !empty($errorMessage)
                  ? htmlspecialchars(
                      $errorMessage
                    )
                  : 'Invalid email or password.';

              ?>

            </span>

          </div>


          <!-- Desktop Login Form -->

          <form
            class="login-form loginForm"
            method="POST"
            action="login.php"
            novalidate
          >


            <div class="form-group">

              <label
                for="usernameDesktop"
                class="form-label"
              >
                Email
              </label>


              <div class="input-wrapper">


                <span class="input-icon-prefix">

                  <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >

                    <path
                      d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"
                    ></path>

                    <polyline
                      points="22,6 12,13 2,6"
                    ></polyline>

                  </svg>

                </span>


                <input
                  type="email"
                  id="usernameDesktop"
                  name="email"
                  class="form-input"
                  placeholder="Enter your email"
                  value="<?php echo $submittedEmail; ?>"
                  required
                  autocomplete="email"
                >

              </div>

            </div>


            <div class="form-group">

              <label
                for="passwordDesktop"
                class="form-label"
              >
                Password
              </label>


              <div class="input-wrapper">


                <span class="input-icon-prefix">

                  <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >

                    <rect
                      x="3"
                      y="11"
                      width="18"
                      height="11"
                      rx="2"
                      ry="2"
                    ></rect>

                    <path
                      d="M7 11V7a5 5 0 0 1 10 0v4"
                    ></path>

                  </svg>

                </span>


                <input
                  type="password"
                  id="passwordDesktop"
                  name="password"
                  class="form-input passwordInput"
                  placeholder="Enter your password"
                  required
                  autocomplete="current-password"
                >


                <button
                  type="button"
                  class="toggle-password-btn togglePassword"
                  aria-label="Show password"
                >

                  <svg
                    class="eyeIconOpen"
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >

                    <path
                      d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"
                    ></path>

                    <circle
                      cx="12"
                      cy="12"
                      r="3"
                    ></circle>

                  </svg>


                  <svg
                    class="eyeIconClosed"
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    style="display: none;"
                  >

                    <path
                      d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"
                    ></path>

                    <line
                      x1="1"
                      y1="1"
                      x2="23"
                      y2="23"
                    ></line>

                  </svg>

                </button>

              </div>

            </div>


            <div class="form-options">

              <label class="checkbox-label">

                <input
                  type="checkbox"
                  name="remember"
                  class="custom-checkbox"
                >

                <span>
                  Remember me
                </span>

              </label>


              <a
                href="#"
                class="forgot-password-link"
              >
                Forgot Password?
              </a>

            </div>


            <button
              type="submit"
              class="btn-primary submitBtn"
            >

              <span
                class="btn-spinner btnSpinner"
                style="display: none;"
              ></span>


              <span class="btnText">

                SIGN IN

                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2.2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >

                  <line
                    x1="5"
                    y1="12"
                    x2="19"
                    y2="12"
                  ></line>

                  <polyline
                    points="12 5 19 12 12 19"
                  ></polyline>

                </svg>

              </span>

            </button>


            <div class="or-divider">

              <span>
                OR
              </span>

            </div>


            <div class="card-support-link">

              Need help accessing your account?

              <a
                href="#"
                class="support-anchor"
              >
                Contact support
              </a>

            </div>

          </form>

        </main>

      </div>

    </div>

  </div>


  <!-- ===================================================================
       2. DEDICATED MOBILE LAYOUT
       =================================================================== -->

  <div class="mobile-layout">


    <!-- Mobile Top Header Blob Background Visual -->

    <div class="mobile-header-backdrop">

      <svg
        viewBox="0 0 500 240"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
      >

        <path
          d="M 0 0 L 500 0 L 500 160 Q 350 240 250 200 Q 150 160 0 200 Z"
          fill="url(#mobileHeaderGrad)"
          opacity="0.12"
        />

        <defs>

          <linearGradient
            id="mobileHeaderGrad"
            x1="0"
            y1="0"
            x2="1"
            y2="1"
          >

            <stop
              stop-color="#0878D1"
            />

            <stop
              offset="1"
              stop-color="#00CFEF"
            />

          </linearGradient>

        </defs>

      </svg>

    </div>


    <!-- Centered Correct Cost360 Logo -->

    <header class="mobile-logo-header">

      <img
        src="assets/images/cost360-logo-mobile.png"
        alt="Cost360 Logo"
        class="mobile-logo-image"
      >

    </header>


    <!-- Main Mobile Content Area -->

    <main class="mobile-content-container">


      <!-- Card Title -->

      <div class="mobile-card-heading">

        <h1 class="mobile-title">
          Sign in
        </h1>

      </div>


      <!-- Feedback Alert -->

      <div
        class="feedback-alert feedbackAlert <?php echo !empty($errorMessage) ? 'show error' : ''; ?>"
        role="alert"
        aria-live="polite"
      >

        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
        >

          <circle
            cx="12"
            cy="12"
            r="10"
          ></circle>

          <line
            x1="12"
            y1="8"
            x2="12"
            y2="12"
          ></line>

          <line
            x1="12"
            y1="16"
            x2="12.01"
            y2="16"
          ></line>

        </svg>


        <span class="alertText">

          <?php

          echo !empty($errorMessage)
              ? htmlspecialchars(
                  $errorMessage
                )
              : 'Invalid email or password.';

          ?>

        </span>

      </div>


      <!-- Mobile Login Form -->

      <form
        class="login-form loginForm"
        method="POST"
        action="login.php"
        novalidate
      >


        <div class="form-group">

          <label
            for="usernameMobile"
            class="form-label"
          >
            Email
          </label>


          <div class="input-wrapper">


            <span class="input-icon-prefix">

              <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
              >

                <path
                  d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"
                ></path>

                <polyline
                  points="22,6 12,13 2,6"
                ></polyline>

              </svg>

            </span>


            <input
              type="email"
              id="usernameMobile"
              name="email"
              class="form-input"
              placeholder="Enter your email"
              value="<?php echo $submittedEmail; ?>"
              required
              autocomplete="email"
            >

          </div>

        </div>


        <div class="form-group">

          <label
            for="passwordMobile"
            class="form-label"
          >
            Password
          </label>


          <div class="input-wrapper">


            <span class="input-icon-prefix">

              <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
              >

                <rect
                  x="3"
                  y="11"
                  width="18"
                  height="11"
                  rx="2"
                  ry="2"
                ></rect>

                <path
                  d="M7 11V7a5 5 0 0 1 10 0v4"
                ></path>

              </svg>

            </span>


            <input
              type="password"
              id="passwordMobile"
              name="password"
              class="form-input passwordInput"
              placeholder="Enter your password"
              required
              autocomplete="current-password"
            >


            <button
              type="button"
              class="toggle-password-btn togglePassword"
              aria-label="Show password"
            >

              <svg
                class="eyeIconOpen"
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
              >

                <path
                  d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"
                ></path>

                <circle
                  cx="12"
                  cy="12"
                  r="3"
                ></circle>

              </svg>


              <svg
                class="eyeIconClosed"
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                style="display: none;"
              >

                <path
                  d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"
                ></path>

                <line
                  x1="1"
                  y1="1"
                  x2="23"
                  y2="23"
                ></line>

              </svg>

            </button>

          </div>

        </div>


        <div class="form-options">

          <label class="checkbox-label">

            <input
              type="checkbox"
              name="remember"
              class="custom-checkbox"
            >

            <span>
              Remember me
            </span>

          </label>


          <a
            href="#"
            class="forgot-password-link"
          >
            Forgot Password?
          </a>

        </div>


        <button
          type="submit"
          class="btn-primary submitBtn"
        >

          <span
            class="btn-spinner btnSpinner"
            style="display: none;"
          ></span>


          <span class="btnText">

            SIGN IN

            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2.2"
              stroke-linecap="round"
              stroke-linejoin="round"
            >

              <line
                x1="5"
                y1="12"
                x2="19"
                y2="12"
              ></line>

              <polyline
                points="12 5 19 12 12 19"
              ></polyline>

            </svg>

          </span>

        </button>


        <div class="or-divider">

          <span>
            OR
          </span>

        </div>


        <div class="card-support-link">

          Need help accessing your account?

          <a
            href="#"
            class="support-anchor"
          >
            Contact support
          </a>

        </div>

      </form>

    </main>

  </div>


  <!-- Cost360 Login JavaScript Logic -->

  <script
    src="assets/js/login.js"
  ></script>

</body>

</html>