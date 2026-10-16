<?php
/**
 * Cost360 - Uploaded Data Reports Dedicated Route Wrapper
 *
 * Provides a direct, bookmarkable, and access-controlled entry point to
 * the Unified Uploaded Data Reports frontend.
 * Protects route via requireAuth() and RBAC menu permission check.
 */

require_once __DIR__ . '/config/auth.php';
requireAuth();

$user = getAuthUser();
$userId = (int)($user['user_id'] ?? 0);

$hasReportAccess = isPlatformAdmin($userId) || 
                   isCmaAdmin($userId) || 
                   userHasMenuAccess('REPORTS', $userId) || 
                   userHasMenuAccess('UPLOAD_CENTRE', $userId) || 
                   userHasMenuAccess('UPLOAD_CENTER', $userId) || 
                   userHasMenuAccess('DATA_MANAGEMENT', $userId) || 
                   userHasMenuAccess('AUDIT', $userId) ||
                   userHasMenuAccess('AUDIT_DESK', $userId) ||
                   userHasMenuAccess('RECONCILIATION', $userId) ||
                   userHasPermission('can_view', 'REPORTS', $userId) ||
                   userHasPermission('VIEW', 'REPORTS', $userId) ||
                   userHasPermission('can_view', 'UPLOAD_CENTRE', $userId);

if (!$hasReportAccess) {
    header('HTTP/1.1 403 Forbidden');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access Denied - Cost360</title><style>body{font-family:\'Inter\',sans-serif;background:#033665;color:#fff;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}.card{background:#063D70;padding:2.5rem;border-radius:12px;text-align:center;max-width:440px;box-shadow:0 10px 30px rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.1);}h1{color:#F87171;font-size:1.6rem;margin-bottom:0.75rem;font-weight:700;}p{font-size:0.95rem;color:rgba(255,255,255,0.85);margin-bottom:1.5rem;line-height:1.5;}a{color:#00CFEF;text-decoration:none;font-weight:700;padding:0.6rem 1.25rem;background:rgba(0,207,239,0.15);border:1px solid rgba(0,207,239,0.3);border-radius:6px;transition:all 0.2s;}a:hover{background:rgba(0,207,239,0.3);color:#fff;}</style></head><body><div class="card"><h1>Access Denied</h1><p>Your role or account permissions do not grant access to the Uploaded Data Reports module.</p><p><a href="dashboard.php">Return to Dashboard</a></p></div></body></html>';
    exit;
}

$_GET['page'] = 'uploaded-reports';
require_once __DIR__ . '/dashboard.php';
