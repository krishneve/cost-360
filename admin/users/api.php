<?php
/**
 * Cost360 - User Master API
 *
 * Works with the actual Cost360 database structure:
 *
 * users
 * user_roles
 * roles
 * categories
 * login_history
 * audit_log
 *
 * Existing User Master UI is kept unchanged.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';


/* ============================================================
   RESPONSE HELPER
   ============================================================ */

function jsonResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $httpCode = 200
): void {

    http_response_code($httpCode);

    $response = [
        'success' => $success
    ];

    if ($success) {
        if ($message !== '') {
            $response['message'] = $message;
        }
    } else {
        $response['error'] = $message;
    }

    $response = array_merge($response, $data);

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* ============================================================
   AUTHENTICATION
   ============================================================ */

if (!isLoggedIn()) {

    jsonResponse(
        false,
        'Your session has expired. Please log in again.',
        [],
        401
    );
}


/* ============================================================
   DATABASE CONNECTION
   ============================================================ */

try {

    $pdo = getDbConnection();

} catch (Throwable $e) {

    error_log(
        'User Master DB connection error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to connect to the database.',
        [],
        500
    );
}


/* ============================================================
   CURRENT LOGGED-IN USER
   ============================================================ */

$currentUserId =
    (int)($_SESSION['user_id'] ?? 0);


/* ============================================================
   USER MASTER MENU ACCESS
   ============================================================ */

if (!isPlatformAdmin($currentUserId) && !isCmaAdmin($currentUserId) && !userHasMenuAccess('USER_MASTER') && !userHasMenuAccess('CMA_USER_MASTER')) {

    jsonResponse(
        false,
        'Access denied. You do not have access to User Master.',
        [],
        403
    );
}


/* ============================================================
   GET ACTION
   ============================================================ */

$action = 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        trim($_POST['action'] ?? 'list');

} else {

    $action =
        trim($_GET['action'] ?? 'list');
}


/* ============================================================
   PERMISSION CHECK
   ============================================================ */

function requireUserMasterPermission(
    string $permission
): void {

    if (isPlatformAdmin() || isCmaAdmin()) {
        return;
    }

    if (
        !userHasPermission(
            $permission,
            'USER_MASTER'
        ) &&
        !userHasPermission(
            $permission,
            'CMA_USER_MASTER'
        )
    ) {

        jsonResponse(
            false,
            "You do not have {$permission} permission for User Master.",
            [],
            403
        );
    }
}


/* ============================================================
   STATUS NORMALIZATION
   ============================================================ */

function normalizeUserStatus(
    string $status
): string {

    $status =
        strtoupper(trim($status));

    $allowedStatuses = [
        'ACTIVE',
        'INACTIVE',
        'SUSPENDED',
        'LOCKED'
    ];

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {

        return 'ACTIVE';
    }

    return $status;
}


/* ============================================================
   GET ACTIVE ROLE
   ============================================================ */

function getRole(
    PDO $pdo,
    int $roleId
): array {

    if ($roleId <= 0) {

        throw new Exception(
            'Please select a valid role.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT
            r.role_id,
            r.category_id,
            r.role_code,
            r.role_name,
            r.description,
            r.status,
            c.category_code,
            c.category_name

        FROM roles r

        INNER JOIN categories c
            ON c.category_id = r.category_id

        WHERE r.role_id = :role_id
          AND r.status = 'Active'
          AND c.status = 'Active'

        LIMIT 1
    ");

    $stmt->execute([
        ':role_id' => $roleId
    ]);

    $role =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {

        throw new Exception(
            'Selected role does not exist or is inactive.'
        );
    }

    return $role;
}


/* ============================================================
   GET USER
   ============================================================ */

function getUser(
    PDO $pdo,
    int $userId
): ?array {

    $stmt = $pdo->prepare("
        SELECT
            u.user_id,
            u.category_id,
            u.full_name,
            u.email,
            u.phone,

            u.username,
            u.first_name,
            u.last_name,

            u.status,
            u.is_active,
            u.is_email_verified,

            u.failed_login_attempts,
            u.locked_until,

            u.last_login_at,

            u.created_at,
            u.created_by,
            u.updated_at,

            r.role_id,
            r.role_code,
            r.role_name

        FROM users u

        LEFT JOIN LATERAL
        (
            SELECT
                ur.role_id

            FROM user_roles ur

            WHERE ur.user_id = u.user_id
              AND ur.status = 'Active'

            ORDER BY
                ur.assigned_at DESC,
                ur.user_role_id DESC

            LIMIT 1

        ) cur_role
            ON TRUE

        LEFT JOIN roles r
            ON r.role_id = cur_role.role_id

        WHERE u.user_id = :user_id

        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' => $userId
    ]);

    $user =
        $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}


/* ============================================================
   AUDIT LOG
   ============================================================ */

function writeAuditLog(
    PDO $pdo,
    int $currentUserId,
    int $entityId,
    string $action,
    ?array $oldValue,
    ?array $newValue
): void {

    // Map standard audit actions (Phase 17)
    $mappedAction = $action;
    if ($action === 'INSERT') {
        $mappedAction = 'USER_CREATED';
    } elseif ($action === 'UPDATE') {
        if (isset($newValue['status']) && isset($oldValue['status']) && $newValue['status'] !== $oldValue['status']) {
            $mappedAction = (strtoupper($newValue['status']) === 'ACTIVE') ? 'USER_ACTIVATED' : 'USER_DEACTIVATED';
        } else {
            $mappedAction = 'USER_UPDATED';
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO audit_log
        (
            user_id,
            entity_name,
            entity_id,
            action,
            old_value,
            new_value,
            timestamp,
            ip_address
        )

        VALUES
        (
            :user_id,
            :entity_name,
            :entity_id,
            :action,
            :old_value,
            :new_value,
            CURRENT_TIMESTAMP,
            :ip_address
        )
    ");

    $stmt->execute([

        ':user_id' =>
            $currentUserId > 0
                ? $currentUserId
                : null,

        ':entity_name' =>
            'users',

        ':entity_id' =>
            $entityId,

        ':action' =>
            $mappedAction,

        ':old_value' =>
            $oldValue !== null
                ? json_encode(
                    $oldValue,
                    JSON_UNESCAPED_UNICODE
                )
                : null,

        ':new_value' =>
            $newValue !== null
                ? json_encode(
                    $newValue,
                    JSON_UNESCAPED_UNICODE
                )
                : null,

        ':ip_address' =>
            $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}


/* ============================================================
   MAIN
   ============================================================ */

try {

    switch ($action) {


        /* ======================================================
           LIST USERS
           ====================================================== */

        case 'list':

            $search =
                trim($_GET['search'] ?? '');

            $roleId =
                trim($_GET['role_id'] ?? '');

            $status =
                trim($_GET['status'] ?? '');


            /*
             * Get latest ACTIVE role.
             *
             * A user can technically have multiple
             * records in user_roles because the schema
             * supports multiple role assignments.
             *
             * The current UI displays one role, so we
             * display the latest active role.
             */

            $sql = "
                SELECT

                    u.user_id,

                    u.username,

                    u.first_name,
                    u.last_name,
                    u.full_name,

                    u.email,

                    u.phone AS mobile_number,

                    u.status,

                    u.is_email_verified,

                    u.created_at,
                    u.updated_at,

                    u.last_login_at,

                    r.role_id,
                    r.role_code,
                    r.role_name

                FROM users u

                LEFT JOIN LATERAL
                (
                    SELECT
                        ur.role_id

                    FROM user_roles ur

                    WHERE ur.user_id = u.user_id
                      AND ur.status = 'Active'

                    ORDER BY
                        ur.assigned_at DESC,
                        ur.user_role_id DESC

                    LIMIT 1

                ) cur_role
                    ON TRUE

                LEFT JOIN roles r
                    ON r.role_id =
                       cur_role.role_id

                WHERE 1 = 1
            ";

            $params = [];

            if (isCmaAdmin($currentUserId)) {
                $cmaCustId = getCmaAdminCustomerId($currentUserId);
                $sql .= " AND (
                    u.user_id = :cma_user_id 
                    OR (
                        EXISTS (
                            SELECT 1 FROM user_organization_access uoa 
                            WHERE uoa.user_id = u.user_id 
                              AND uoa.customer_id = :cma_cust_id 
                              AND UPPER(uoa.status) = 'ACTIVE'
                        )
                        AND COALESCE(r.role_code, '') NOT IN ('PLATFORM_ADMIN', 'SUPER_ADMIN', 'CMA_ADMIN')
                    )
                )";
                $params[':cma_user_id'] = $currentUserId;
                $params[':cma_cust_id'] = $cmaCustId ?: 0;
            }


            /* --------------------------------------------------
               SEARCH
               -------------------------------------------------- */

            if ($search !== '') {

                $sql .= "
                    AND
                    (
                        u.username ILIKE :search

                        OR u.email ILIKE :search

                        OR u.first_name ILIKE :search

                        OR u.last_name ILIKE :search

                        OR u.full_name ILIKE :search
                    )
                ";

                $params[':search'] =
                    '%' . $search . '%';
            }


            /* --------------------------------------------------
               ROLE FILTER
               -------------------------------------------------- */

            if (
                $roleId !== '' &&
                strtoupper($roleId) !== 'ALL'
            ) {

                if (!ctype_digit($roleId)) {

                    throw new Exception(
                        'Invalid role filter.'
                    );
                }

                $sql .= "
                    AND r.role_id = :role_id
                ";

                $params[':role_id'] =
                    (int)$roleId;
            }


            /* --------------------------------------------------
               STATUS FILTER
               -------------------------------------------------- */

            if (
                $status !== '' &&
                strtoupper($status) !== 'ALL'
            ) {

                $status =
                    normalizeUserStatus(
                        $status
                    );

                $sql .= "
                    AND UPPER(u.status) = :status
                ";

                $params[':status'] =
                    $status;
            }


            $sql .= "
                ORDER BY
                    u.user_id ASC
            ";


            $stmt =
                $pdo->prepare($sql);

            $stmt->execute($params);

            $users =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );


            $formattedUsers = [];


            foreach ($users as $user) {

                $firstName =
                    trim(
                        $user['first_name'] ?? ''
                    );

                $lastName =
                    trim(
                        $user['last_name'] ?? ''
                    );


                $fullName =
                    trim(
                        $firstName .
                        ' ' .
                        $lastName
                    );


                if ($fullName === '') {

                    $fullName =
                        trim(
                            $user['full_name'] ?? ''
                        );
                }


                if ($fullName === '') {

                    $fullName =
                        $user['username'] ?? '';
                }


                $formattedUsers[] = [

                    'user_id' =>
                        (int)$user['user_id'],

                    'username' =>
                        $user['username'] ?? '',

                    'first_name' =>
                        $firstName,

                    'last_name' =>
                        $lastName,

                    'full_name' =>
                        $fullName,

                    'email' =>
                        $user['email'] ?? '',

                    'mobile_number' =>
                        $user['mobile_number'] ?? '',

                    'status' =>
                        strtoupper(
                            $user['status']
                                ?? 'ACTIVE'
                        ),

                    'role_id' =>
                        $user['role_id'] !== null
                            ? (int)$user['role_id']
                            : null,

                    'role_code' =>
                        $user['role_code']
                            ?? 'UNASSIGNED',

                    'role_name' =>
                        $user['role_name']
                            ?? 'No Role Assigned',

                    'created_at' =>
                        !empty(
                            $user['created_at']
                        )
                            ? date(
                                'd M Y, H:i',
                                strtotime(
                                    $user['created_at']
                                )
                            )
                            : '—',

                    'last_login_at' =>
                        !empty(
                            $user['last_login_at']
                        )
                            ? date(
                                'd M Y, H:i',
                                strtotime(
                                    $user['last_login_at']
                                )
                            )
                            : 'Never'
                ];
            }


            jsonResponse(
                true,
                '',
                [
                    'count' =>
                        count($formattedUsers),

                    'users' =>
                        $formattedUsers
                ]
            );

            break;


        /* ======================================================
           ROLES
           ====================================================== */

        case 'roles':

            /*
             * The role dropdown should contain only
             * active roles belonging to active categories.
             */

            $sql = "
                SELECT

                    r.role_id,
                    r.category_id,

                    r.role_code,
                    r.role_name,

                    r.description,

                    c.category_code,
                    c.category_name

                FROM roles r

                INNER JOIN categories c
                    ON c.category_id =
                       r.category_id

                WHERE r.status = 'Active'
                  AND c.status = 'Active'
            ";

            if (isCmaAdmin($currentUserId)) {
                $sql .= " AND r.role_code NOT IN ('PLATFORM_ADMIN', 'SUPER_ADMIN', 'CMA_ADMIN')";
            }

            $sql .= "
                ORDER BY
                    r.role_name ASC
            ";

            $stmt = $pdo->query($sql);
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);


            jsonResponse(
                true,
                '',
                [
                    'roles' => $roles
                ]
            );

            break;


        /* ======================================================
           GET USER
           ====================================================== */

        case 'get_user':

            $userId =
                (int)(
                    $_GET['user_id'] ?? 0
                );


            if ($userId <= 0) {

                throw new Exception(
                    'Invalid User ID.'
                );
            }


            $user =
                getUser(
                    $pdo,
                    $userId
                );


            if (!$user) {

                throw new Exception(
                    'User not found.'
                );
            }


            jsonResponse(
                true,
                '',
                [
                    'user' => [

                        'user_id' =>
                            (int)$user['user_id'],

                        'username' =>
                            $user['username'] ?? '',

                        'first_name' =>
                            $user['first_name'] ?? '',

                        'last_name' =>
                            $user['last_name'] ?? '',

                        'email' =>
                            $user['email'] ?? '',

                        'mobile_number' =>
                            $user['phone'] ?? '',

                        'status' =>
                            strtoupper(
                                $user['status']
                                    ?? 'ACTIVE'
                            ),

                        'role_id' =>
                            $user['role_id'] !== null
                                ? (int)$user['role_id']
                                : null,

                        'role_code' =>
                            $user['role_code'] ?? '',

                        'role_name' =>
                            $user['role_name'] ?? ''
                    ]
                ]
            );

            break;


        /* ======================================================
           CREATE USER
           ====================================================== */

        case 'create':

            requireUserMasterPermission(
                'CREATE'
            );


            if (
                $_SERVER['REQUEST_METHOD']
                !== 'POST'
            ) {

                throw new Exception(
                    'Invalid request method.'
                );
            }


            $username =
                trim(
                    $_POST['username'] ?? ''
                );

            $firstName =
                trim(
                    $_POST['first_name'] ?? ''
                );

            $lastName =
                trim(
                    $_POST['last_name'] ?? ''
                );

            $email =
                trim(
                    $_POST['email'] ?? ''
                );

            $password =
                $_POST['password'] ?? '';

            $confirmPassword =
                $_POST['confirm_password']
                ?? '';

            $roleId =
                (int)(
                    $_POST['role_id'] ?? 0
                );

            $status =
                normalizeUserStatus(
                    $_POST['status']
                        ?? 'ACTIVE'
                );


            /* --------------------------------------------------
               VALIDATION
               -------------------------------------------------- */

            if ($firstName === '') {

                throw new Exception(
                    'First name is required.'
                );
            }


            if ($username === '') {

                throw new Exception(
                    'Username is required.'
                );
            }


            if (
                strlen($username) > 150
            ) {

                throw new Exception(
                    'Username cannot exceed 150 characters.'
                );
            }


            if ($email === '') {

                throw new Exception(
                    'Email address is required.'
                );
            }


            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                throw new Exception(
                    'Please enter a valid email address.'
                );
            }


            if ($password === '') {

                throw new Exception(
                    'Password is required.'
                );
            }


            if (strlen($password) < 6) {

                throw new Exception(
                    'Password must be at least 6 characters long.'
                );
            }


            if (
                $password !==
                $confirmPassword
            ) {

                throw new Exception(
                    'Passwords do not match.'
                );
            }


            /* --------------------------------------------------
               ROLE
               -------------------------------------------------- */

            $role =
                getRole(
                    $pdo,
                    $roleId
                );

            /* --------------------------------------------------
               CUSTOMER / TENANT & SUBSCRIPTION USER LIMIT ENFORCEMENT
               -------------------------------------------------- */
            $targetCustomerId = 0;
            if (isCmaAdmin($currentUserId)) {
                $targetCustomerId = getCmaAdminCustomerId($currentUserId);
                if (!$targetCustomerId) {
                    throw new Exception('No customer organization assigned to this CMA Admin.');
                }
                if (in_array(strtoupper($role['role_code'] ?? ''), ['PLATFORM_ADMIN', 'SUPER_ADMIN', 'CMA_ADMIN'], true)) {
                    throw new Exception('CMA Admins can only assign operational customer roles.');
                }
            } elseif (!empty($_POST['customer_id'])) {
                $targetCustomerId = (int)$_POST['customer_id'];
            } elseif (!empty($_SESSION['active_customer_id'])) {
                $targetCustomerId = (int)$_SESSION['active_customer_id'];
            } else {
                // Check if creator is a customer-scoped user (e.g. Customer Admin)
                $stmtScope = $pdo->prepare("
                    SELECT customer_id 
                    FROM user_organization_access 
                    WHERE user_id = :uid AND UPPER(status) = 'ACTIVE' AND customer_id IS NOT NULL 
                    ORDER BY access_id ASC LIMIT 1
                ");
                $stmtScope->execute([':uid' => $currentUserId]);
                $targetCustomerId = (int)($stmtScope->fetchColumn() ?: 0);
            }

            if ($targetCustomerId > 0) {
                require_once __DIR__ . '/../../config/subscription.php';
                $limitCheck = checkUserLimit($targetCustomerId);
                if (!$limitCheck['allowed']) {
                    throw new Exception($limitCheck['reason']);
                }
            }


            /* --------------------------------------------------
               DUPLICATE USERNAME
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                SELECT user_id
                FROM users

                WHERE LOWER(username)
                    = LOWER(:username)

                LIMIT 1
            ");

            $stmt->execute([
                ':username' =>
                    $username
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'A user with this username already exists.'
                );
            }


            /* --------------------------------------------------
               DUPLICATE EMAIL
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                SELECT user_id
                FROM users

                WHERE LOWER(email)
                    = LOWER(:email)

                LIMIT 1
            ");

            $stmt->execute([
                ':email' =>
                    $email
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'A user with this email address already exists.'
                );
            }


            /* --------------------------------------------------
               PASSWORD
               -------------------------------------------------- */

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            if ($passwordHash === false) {

                throw new Exception(
                    'Unable to securely create password.'
                );
            }


            $fullName =
                trim(
                    $firstName .
                    ' ' .
                    $lastName
                );


            /*
             * users.category_id is NOT NULL.
             *
             * Therefore category comes from
             * the selected role.
             */

            $categoryId =
                (int)$role['category_id'];


            $isActive =
                $status === 'ACTIVE';


            $pdo->beginTransaction();


            /* --------------------------------------------------
               INSERT USER
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                INSERT INTO users
                (
                    category_id,
                    full_name,
                    email,
                    phone,
                    password_hash,
                    is_active,
                    created_by,
                    updated_at,

                    username,
                    first_name,
                    last_name,
                    status,
                    is_email_verified,
                    failed_login_attempts,
                    locked_until,
                    last_login_at
                )

                VALUES
                (
                    :category_id,
                    :full_name,
                    :email,
                    NULL,
                    :password_hash,
                    :is_active,
                    :created_by,
                    CURRENT_TIMESTAMP,

                    :username,
                    :first_name,
                    :last_name,
                    :status,
                    FALSE,
                    0,
                    NULL,
                    NULL
                )

                RETURNING user_id
            ");


            $stmt->execute([

                ':category_id' =>
                    $categoryId,

                ':full_name' =>
                    $fullName,

                ':email' =>
                    $email,

                ':password_hash' =>
                    $passwordHash,

                ':is_active' =>
                    $isActive,

                ':created_by' =>
                    $currentUserId > 0
                        ? $currentUserId
                        : null,

                ':username' =>
                    $username,

                ':first_name' =>
                    $firstName,

                ':last_name' =>
                    $lastName,

                ':status' =>
                    $status
            ]);


            $newUserId =
                (int)$stmt->fetchColumn();


            if ($newUserId <= 0) {

                throw new Exception(
                    'Failed to create user.'
                );
            }


            /* --------------------------------------------------
               ASSIGN ROLE
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                INSERT INTO user_roles
                (
                    user_id,
                    role_id,
                    assigned_at,
                    assigned_by,
                    status
                )

                VALUES
                (
                    :user_id,
                    :role_id,
                    CURRENT_TIMESTAMP,
                    :assigned_by,
                    'Active'
                )
            ");

            $stmt->execute([

                ':user_id' =>
                    $newUserId,

                ':role_id' =>
                    $roleId,

                ':assigned_by' =>
                    $currentUserId > 0
                        ? $currentUserId
                        : null
            ]);

            /* --------------------------------------------------
               CUSTOMER TENANT SCOPE
               -------------------------------------------------- */
            if ($targetCustomerId > 0) {
                $stmtCustAccess = $pdo->prepare("
                    INSERT INTO user_organization_access
                    (
                        user_id,
                        customer_id,
                        valid_from,
                        status
                    )
                    VALUES
                    (
                        :user_id,
                        :customer_id,
                        CURRENT_DATE,
                        'Active'
                    )
                ");
                $stmtCustAccess->execute([
                    ':user_id' => $newUserId,
                    ':customer_id' => $targetCustomerId
                ]);
            }

            /* --------------------------------------------------
               AUDIT
               -------------------------------------------------- */

            writeAuditLog(
                $pdo,
                $currentUserId,
                $newUserId,
                'INSERT',
                null,
                [
                    'username' =>
                        $username,

                    'first_name' =>
                        $firstName,

                    'last_name' =>
                        $lastName,

                    'email' =>
                        $email,

                    'category_id' =>
                        $categoryId,

                    'role_id' =>
                        $roleId,

                    'role_code' =>
                        $role['role_code'],

                    'status' =>
                        $status
                ]
            );


            $pdo->commit();


            jsonResponse(
                true,
                "User '{$username}' created successfully.",
                [
                    'user_id' =>
                        $newUserId
                ]
            );

            break;


        /* ======================================================
           UPDATE USER
           ====================================================== */

        case 'update':

            requireUserMasterPermission(
                'EDIT'
            );


            if (
                $_SERVER['REQUEST_METHOD']
                !== 'POST'
            ) {

                throw new Exception(
                    'Invalid request method.'
                );
            }


            $userId =
                (int)(
                    $_POST['user_id'] ?? 0
                );

            $username =
                trim(
                    $_POST['username'] ?? ''
                );

            $firstName =
                trim(
                    $_POST['first_name'] ?? ''
                );

            $lastName =
                trim(
                    $_POST['last_name'] ?? ''
                );

            $email =
                trim(
                    $_POST['email'] ?? ''
                );

            $roleId =
                (int)(
                    $_POST['role_id'] ?? 0
                );

            $status =
                normalizeUserStatus(
                    $_POST['status']
                        ?? 'ACTIVE'
                );


            /* --------------------------------------------------
               VALIDATION
               -------------------------------------------------- */

            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }


            if ($username === '') {

                throw new Exception(
                    'Username is required.'
                );
            }


            if ($firstName === '') {

                throw new Exception(
                    'First name is required.'
                );
            }


            if ($email === '') {

                throw new Exception(
                    'Email address is required.'
                );
            }


            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                throw new Exception(
                    'Please enter a valid email address.'
                );
            }


            /* --------------------------------------------------
               EXISTING USER
               -------------------------------------------------- */

            $oldUser =
                getUser(
                    $pdo,
                    $userId
                );


            if (!$oldUser) {

                throw new Exception(
                    'User not found.'
                );
            }


            /* --------------------------------------------------
               ROLE
               -------------------------------------------------- */

            $role =
                getRole(
                    $pdo,
                    $roleId
                );


            /* --------------------------------------------------
               USERNAME DUPLICATE
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                SELECT user_id

                FROM users

                WHERE LOWER(username)
                    = LOWER(:username)

                  AND user_id <> :user_id

                LIMIT 1
            ");

            $stmt->execute([

                ':username' =>
                    $username,

                ':user_id' =>
                    $userId
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'Another user is already using this username.'
                );
            }


            /* --------------------------------------------------
               EMAIL DUPLICATE
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                SELECT user_id

                FROM users

                WHERE LOWER(email)
                    = LOWER(:email)

                  AND user_id <> :user_id

                LIMIT 1
            ");

            $stmt->execute([

                ':email' =>
                    $email,

                ':user_id' =>
                    $userId
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'Another user is already using this email address.'
                );
            }


            $fullName =
                trim(
                    $firstName .
                    ' ' .
                    $lastName
                );


            $categoryId =
                (int)$role['category_id'];


            $isActive =
                $status === 'ACTIVE';


            $pdo->beginTransaction();


            /* --------------------------------------------------
               UPDATE USER
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                UPDATE users

                SET
                    category_id = :category_id,

                    full_name = :full_name,

                    email = :email,

                    username = :username,

                    first_name = :first_name,

                    last_name = :last_name,

                    status = :status,

                    is_active = :is_active,

                    updated_at = CURRENT_TIMESTAMP

                WHERE user_id = :user_id
            ");

            $stmt->execute([

                ':category_id' =>
                    $categoryId,

                ':full_name' =>
                    $fullName,

                ':email' =>
                    $email,

                ':username' =>
                    $username,

                ':first_name' =>
                    $firstName,

                ':last_name' =>
                    $lastName,

                ':status' =>
                    $status,

                ':is_active' =>
                    $isActive,

                ':user_id' =>
                    $userId
            ]);


            /* --------------------------------------------------
               DEACTIVATE CURRENT ACTIVE ROLES
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                UPDATE user_roles

                SET status = 'Inactive'

                WHERE user_id = :user_id

                  AND status = 'Active'
            ");

            $stmt->execute([
                ':user_id' =>
                    $userId
            ]);


            /* --------------------------------------------------
               ASSIGN SELECTED ROLE
               -------------------------------------------------- */

            $stmt = $pdo->prepare("
                INSERT INTO user_roles
                (
                    user_id,
                    role_id,
                    assigned_at,
                    assigned_by,
                    status
                )

                VALUES
                (
                    :user_id,
                    :role_id,
                    CURRENT_TIMESTAMP,
                    :assigned_by,
                    'Active'
                )

                ON CONFLICT
                (
                    user_id,
                    role_id
                )

                DO UPDATE SET

                    status = 'Active',

                    assigned_at =
                        CURRENT_TIMESTAMP,

                    assigned_by =
                        EXCLUDED.assigned_by
            ");

            $stmt->execute([

                ':user_id' =>
                    $userId,

                ':role_id' =>
                    $roleId,

                ':assigned_by' =>
                    $currentUserId > 0
                        ? $currentUserId
                        : null
            ]);


            /* --------------------------------------------------
               AUDIT
               -------------------------------------------------- */

            writeAuditLog(
                $pdo,
                $currentUserId,
                $userId,
                'UPDATE',
                [
                    'username' =>
                        $oldUser['username'] ?? '',

                    'first_name' =>
                        $oldUser['first_name'] ?? '',

                    'last_name' =>
                        $oldUser['last_name'] ?? '',

                    'email' =>
                        $oldUser['email'] ?? '',

                    'category_id' =>
                        $oldUser['category_id'] ?? null,

                    'role_id' =>
                        $oldUser['role_id'] ?? null,

                    'status' =>
                        $oldUser['status'] ?? ''
                ],
                [
                    'username' =>
                        $username,

                    'first_name' =>
                        $firstName,

                    'last_name' =>
                        $lastName,

                    'email' =>
                        $email,

                    'category_id' =>
                        $categoryId,

                    'role_id' =>
                        $roleId,

                    'role_code' =>
                        $role['role_code'],

                    'status' =>
                        $status
                ]
            );


            $pdo->commit();


            /*
             * If current user edited themselves,
             * remove cached authentication data.
             */

            if (
                $userId ===
                $currentUserId
            ) {

                unset(
                    $_SESSION['auth_cache']
                );
            }


            jsonResponse(
                true,
                'User details updated successfully.'
            );

            break;


        /* ======================================================
           CHANGE PASSWORD
           ====================================================== */

        case 'change_password':

            requireUserMasterPermission(
                'EDIT'
            );


            if (
                $_SERVER['REQUEST_METHOD']
                !== 'POST'
            ) {

                throw new Exception(
                    'Invalid request method.'
                );
            }


            $userId =
                (int)(
                    $_POST['user_id'] ?? 0
                );

            $newPassword =
                $_POST['new_password'] ?? '';

            $confirmPassword =
                $_POST['confirm_password']
                ?? '';


            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }


            if (
                $newPassword === '' ||
                strlen($newPassword) < 6
            ) {

                throw new Exception(
                    'New password must be at least 6 characters long.'
                );
            }


            if (
                $newPassword !==
                $confirmPassword
            ) {

                throw new Exception(
                    'Passwords do not match.'
                );
            }


            $user =
                getUser(
                    $pdo,
                    $userId
                );


            if (!$user) {

                throw new Exception(
                    'User not found.'
                );
            }


            $passwordHash =
                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );


            if ($passwordHash === false) {

                throw new Exception(
                    'Unable to securely update password.'
                );
            }


            $pdo->beginTransaction();


            /*
             * The actual users table contains:
             *
             * password_hash
             * failed_login_attempts
             * locked_until
             *
             * It does NOT contain password_changed_at.
             */

            $stmt = $pdo->prepare("
                UPDATE users

                SET
                    password_hash = :password_hash,

                    failed_login_attempts = 0,

                    locked_until = NULL,

                    updated_at =
                        CURRENT_TIMESTAMP

                WHERE user_id = :user_id
            ");

            $stmt->execute([

                ':password_hash' =>
                    $passwordHash,

                ':user_id' =>
                    $userId
            ]);


            writeAuditLog(
                $pdo,
                $currentUserId,
                $userId,
                'UPDATE',
                [
                    'action' =>
                        'PASSWORD_CHANGE'
                ],
                [
                    'action' =>
                        'PASSWORD_CHANGED'
                ]
            );


            $pdo->commit();


            jsonResponse(
                true,
                'Password changed successfully.'
            );

            break;


        /* ======================================================
           DELETE USER
           ====================================================== */

        case 'delete':

            requireUserMasterPermission(
                'DELETE'
            );


            if (
                $_SERVER['REQUEST_METHOD']
                !== 'POST'
            ) {

                throw new Exception(
                    'Invalid request method.'
                );
            }


            $userId =
                (int)(
                    $_POST['user_id'] ?? 0
                );


            if ($userId <= 0) {

                throw new Exception(
                    'Invalid user ID.'
                );
            }


            /* --------------------------------------------------
               PREVENT SELF DELETE
               -------------------------------------------------- */

            if (
                $userId ===
                $currentUserId
            ) {

                throw new Exception(
                    'You cannot delete your own logged-in account.'
                );
            }


            /* --------------------------------------------------
               USER
               -------------------------------------------------- */

            $user =
                getUser(
                    $pdo,
                    $userId
                );


            if (!$user) {

                throw new Exception(
                    'User not found.'
                );
            }


            $pdo->beginTransaction();


            /* --------------------------------------------------
               AUDIT BEFORE DELETE
               -------------------------------------------------- */

            writeAuditLog(
                $pdo,
                $currentUserId,
                $userId,
                'DELETE',
                [
                    'username' =>
                        $user['username'] ?? '',

                    'first_name' =>
                        $user['first_name'] ?? '',

                    'last_name' =>
                        $user['last_name'] ?? '',

                    'email' =>
                        $user['email'] ?? '',

                    'role_id' =>
                        $user['role_id'] ?? null,

                    'status' =>
                        $user['status'] ?? ''
                ],
                null
            );


            /*
             * user_roles will be deleted automatically
             * because of ON DELETE CASCADE.
             *
             * login_history will also be deleted automatically
             * because of ON DELETE CASCADE.
             *
             * user_organization_access will also be deleted
             * automatically because of ON DELETE CASCADE.
             */

            $stmt = $pdo->prepare("
                DELETE FROM users

                WHERE user_id = :user_id
            ");

            $stmt->execute([
                ':user_id' =>
                    $userId
            ]);


            if (
                $stmt->rowCount() === 0
            ) {

                throw new Exception(
                    'User could not be deleted.'
                );
            }


            $pdo->commit();


            jsonResponse(
                true,
                'User deleted successfully.'
            );

            break;


        /* ======================================================
           INVALID ACTION
           ====================================================== */

        default:

            jsonResponse(
                false,
                'Invalid User Master action.',
                [],
                400
            );
    }


} catch (Throwable $e) {

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();
    }


    error_log(
        'User Master API Error: ' .
        $e->getMessage()
    );


    jsonResponse(
        false,
        $e->getMessage(),
        [],
        400
    );
}