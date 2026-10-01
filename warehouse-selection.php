<?php
/**
 * Cost360 - Warehouse Selection (Legacy Route)
 * Plant and Warehouse are now unified into a single organizational level: LOCATION.
 * Automatically redirects to the unified organization-selection.php.
 */

require_once __DIR__ . '/config/auth.php';

requireAuth();

header('Location: organization-selection.php', true, 302);
exit;
