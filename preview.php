<?php
/**
 * Cost360 - Visual Preview Runner
 * Authenticates admin session and renders target page with live data.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = 1;
$currentRole = getCurrentUserRole($userId);
$user = getAuthUser($userId);

$_SESSION['user_id'] = $userId;
$_SESSION['email'] = 'admin@cost360.com';
$_SESSION['username'] = 'admin@cost360.com';
$_SESSION['first_name'] = 'Platform';
$_SESSION['last_name'] = 'Administrator';
$_SESSION['role_id'] = $currentRole['role_id'] ?? 1;
$_SESSION['role_code'] = $currentRole['role_code'] ?? 'PLATFORM_ADMIN';
$_SESSION['role_name'] = $currentRole['role_name'] ?? 'Platform Administrator';
$_SESSION['role_status'] = 'Active';
$_SESSION['auth_cache']['user'] = $user;
$_SESSION['auth_cache']['role'] = $currentRole;

setActiveCustomer(1);
setActiveCompany(1);

$divs = getUserAccessibleDivisions($userId, 1);
if (!empty($divs)) {
    setActiveDivision($divs[0]['division_id']);
    $locs = getUserAccessibleLocations($userId, 1, $divs[0]['division_id']);
    if (!empty($locs) && !empty($locs[0]['plant_id'])) {
        setActivePlant($locs[0]['plant_id']);
    }
}
$pers = getUserAccessiblePeriods(1, $userId);
if (!empty($pers)) {
    setActivePeriod($pers[0]['period_id']);
}

$targetPage = $_GET['page'] ?? 'users';
$_GET['page'] = $targetPage;

require __DIR__ . '/dashboard.php';
