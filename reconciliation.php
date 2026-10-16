<?php
/**
 * Cost360 - Financial Reconciliation Dedicated Route Wrapper
 * Protects route via requireAuth() and RBAC menu permission check.
 */

require_once __DIR__ . '/config/auth.php';
requireAuth();

$user = getAuthUser();
$userId = (int)($user['user_id'] ?? 0);

if (!isPlatformAdmin($userId) && 
    !isCmaAdmin($userId) && 
    !userHasMenuAccess('RECONCILIATION', $userId) && 
    !userHasMenuAccess('AUDIT', $userId) && 
    !userHasMenuAccess('AUDIT_DESK', $userId) && 
    !userHasMenuAccess('UPLOAD_CENTRE', $userId) && 
    !userHasMenuAccess('DATA_MANAGEMENT', $userId)) {
    header('HTTP/1.1 403 Forbidden');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access Denied - Cost360</title><style>body{font-family:\'Inter\',sans-serif;background:#033665;color:#fff;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}.card{background:#063D70;padding:2rem;border-radius:12px;text-align:center;max-width:420px;box-shadow:0 10px 30px rgba(0,0,0,0.3);}h1{color:#F87171;font-size:1.5rem;margin-bottom:0.75rem;}p{font-size:0.9rem;color:rgba(255,255,255,0.8);margin-bottom:1.5rem;}a{color:#00CFEF;text-decoration:none;font-weight:700;padding:0.5rem 1rem;background:rgba(0,207,239,0.15);border-radius:6px;}</style></head><body><div class="card"><h1>Access Denied</h1><p>Your role in Neon Auth is not authorized to access the Financial Reconciliation module.</p><p><a href="dashboard.php">Return to Dashboard</a></p></div></body></html>';
    exit;
}

$_GET['page'] = 'reconciliation';
require_once __DIR__ . '/dashboard.php';
