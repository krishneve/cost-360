<?php
/**
 * Cost360
 * Company Selection
 *
 * Users reach this page after successful authentication
 * when they have access to more than one company.
 */

require_once __DIR__ . '/config/auth.php';

requireAuth();

$userId =
    $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.php');
    exit;
}


/* ============================================================
   HANDLE COMPANY SELECTION
   ============================================================ */

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $companyId =
        trim($_POST['company_id'] ?? '');

    if ($companyId === '') {

        $errorMessage =
            'Please select a company.';

    } else {

        /*
         * IMPORTANT:
         *
         * setActiveCompany() validates that the selected
         * company is actually accessible to the logged-in user.
         */

        if (
            setActiveCompany(
                $companyId
            )
        ) {

            header(
                'Location: dashboard.php'
            );

            exit;

        } else {

            $errorMessage =
                'You do not have access to the selected company.';
        }
    }
}


/* ============================================================
   FETCH ACCESSIBLE COMPANIES
   ============================================================ */

$companies =
    getUserAccessibleCompanies(
        $userId
    );


/* ============================================================
   NO COMPANY ACCESS
   ============================================================ */

if (empty($companies)) {

    clearActiveCompany();

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
            Cost360 - No Company Access
        </title>

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

        <style>

            * {
                box-sizing: border-box;
            }

            body {

                margin: 0;

                min-height: 100vh;

                font-family:
                    'Inter',
                    -apple-system,
                    BlinkMacSystemFont,
                    'Segoe UI',
                    sans-serif;

                background:
                    linear-gradient(
                        135deg,
                        #F5FAFE 0%,
                        #EAF5FC 100%
                    );

                display: flex;

                align-items: center;

                justify-content: center;

                color: #17324D;
            }

            .card {

                width: 90%;

                max-width: 520px;

                background: #FFFFFF;

                border-radius: 18px;

                padding: 42px;

                text-align: center;

                box-shadow:
                    0 20px 50px
                    rgba(3, 54, 101, 0.12);
            }

            .icon {

                width: 72px;

                height: 72px;

                margin: 0 auto 24px;

                border-radius: 50%;

                display: flex;

                align-items: center;

                justify-content: center;

                background: #FEF2F2;

                color: #DC2626;

                font-size: 32px;
            }

            h1 {

                margin: 0 0 12px;

                font-size: 24px;
            }

            p {

                margin: 0 0 28px;

                color: #6B7F91;

                line-height: 1.6;
            }

            .logout {

                display: inline-block;

                padding: 12px 24px;

                border-radius: 8px;

                background: #0878D1;

                color: #FFFFFF;

                text-decoration: none;

                font-weight: 600;
            }

            .logout:hover {

                background: #063D70;
            }

        </style>

    </head>

    <body>

        <div class="card">

            <div class="icon">
                !
            </div>

            <h1>
                No Company Access
            </h1>

            <p>
                Your account is authenticated, but no active
                company has been assigned to your user profile.
                Please contact your administrator.
            </p>

            <a
                href="logout.php"
                class="logout"
            >
                Logout
            </a>

        </div>

    </body>

    </html>

    <?php

    exit;
}


/* ============================================================
   ONE COMPANY
   ============================================================ */

if (
    count($companies) === 1 &&
    empty($_POST)
) {

    $singleCompany =
        $companies[0];

    if (
        setActiveCompany(
            $singleCompany['company_id']
        )
    ) {

        header(
            'Location: dashboard.php'
        );

        exit;
    }
}


/* ============================================================
   HTML
   ============================================================ */

$user =
    getAuthUser();

$userName =
    trim(
        ($user['first_name'] ?? '') .
        ' ' .
        ($user['last_name'] ?? '')
    );

if ($userName === '') {

    $userName =
        $user['username'] ??
        $user['email'] ??
        'User';
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
        Cost360 - Select Company
    </title>

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

    <style>

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            min-height: 100vh;

            font-family:
                'Inter',
                -apple-system,
                BlinkMacSystemFont,
                'Segoe UI',
                sans-serif;

            background:
                linear-gradient(
                    135deg,
                    #F5FAFE 0%,
                    #EAF5FC 100%
                );

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

            max-width: 900px;
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

            margin-bottom: 20px;

            box-shadow:
                0 8px 25px
                rgba(3, 54, 101, 0.07);

            border: 1px solid #E1EAF0;
        }

        .welcome-label {

            font-size: 13px;

            color: #6B7F91;

            margin-bottom: 5px;
        }

        .welcome-name {

            font-size: 20px;

            font-weight: 700;

            color: #17324D;
        }

        .section-title {

            margin: 0 0 16px;

            font-size: 20px;

            font-weight: 700;

            color: #17324D;
        }

        .companies {

            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(280px, 1fr)
                );

            gap: 18px;
        }

        .company-card {

            background: #FFFFFF;

            border: 1px solid #E1EAF0;

            border-radius: 16px;

            padding: 24px;

            cursor: pointer;

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease,
                border-color 0.2s ease;

            box-shadow:
                0 8px 25px
                rgba(3, 54, 101, 0.06);
        }

        .company-card:hover {

            transform:
                translateY(-3px);

            border-color:
                #0878D1;

            box-shadow:
                0 14px 35px
                rgba(3, 54, 101, 0.12);
        }

        .company-icon {

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

        .company-icon svg {

            width: 25px;

            height: 25px;
        }

        .company-name {

            font-size: 18px;

            font-weight: 700;

            color: #17324D;

            margin-bottom: 7px;
        }

        .company-code {

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

        .logout-container {

            text-align: center;

            margin-top: 30px;
        }

        .logout {

            color: #6B7F91;

            font-size: 14px;

            text-decoration: none;
        }

        .logout:hover {

            color: #0878D1;
        }

        .company-form {

            margin: 0;
        }

        .company-button {

            width: 100%;

            border: 0;

            background: transparent;

            padding: 0;

            text-align: left;

            cursor: pointer;

            font-family: inherit;

            color: inherit;
        }

        @media (max-width: 600px) {

            .page {
                padding: 20px 14px;
            }

            .brand h1 {
                font-size: 27px;
            }

            .welcome {
                padding: 18px;
            }

            .company-card {
                padding: 20px;
            }

        }

    </style>

</head>

<body>

<div class="page">

    <div class="container">

        <div class="brand">

            <h1>
                Cost360
            </h1>

            <p>
                Select the company you want to work with
            </p>

        </div>


        <div class="welcome">

            <div class="welcome-label">
                Welcome
            </div>

            <div class="welcome-name">
                <?php
                echo htmlspecialchars(
                    $userName
                );
                ?>
            </div>

        </div>


        <?php if (!empty($errorMessage)): ?>

            <div class="error">

                <?php
                echo htmlspecialchars(
                    $errorMessage
                );
                ?>

            </div>

        <?php endif; ?>


        <h2 class="section-title">
            Your Companies
        </h2>


        <div class="companies">

            <?php foreach ($companies as $company): ?>

                <form
                    method="POST"
                    class="company-form"
                >

                    <input
                        type="hidden"
                        name="company_id"
                        value="<?php
                            echo htmlspecialchars(
                                $company['company_id']
                            );
                        ?>"
                    >

                    <button
                        type="submit"
                        class="company-button"
                    >

                        <div class="company-card">

                            <div class="company-icon">

                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >

                                    <rect
                                        x="3"
                                        y="7"
                                        width="18"
                                        height="14"
                                        rx="2"
                                    />

                                    <path
                                        d="M8 21V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v16"
                                    />

                                </svg>

                            </div>


                            <div class="company-name">

                                <?php
                                echo htmlspecialchars(
                                    $company['company_name']
                                );
                                ?>

                            </div>


                            <div class="company-code">

                                Company Code:

                                <strong>

                                    <?php
                                    echo htmlspecialchars(
                                        $company['company_code']
                                    );
                                    ?>

                                </strong>

                            </div>


                            <div class="select-text">

                                <span>
                                    Select Company
                                </span>

                                <span>
                                    →
                                </span>

                            </div>

                        </div>

                    </button>

                </form>

            <?php endforeach; ?>

        </div>


        <div class="logout-container">

            <a
                href="logout.php"
                class="logout"
            >
                Logout
            </a>

        </div>

    </div>

</div>

</body>

</html>