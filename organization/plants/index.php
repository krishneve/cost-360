<?php
/**
 * Cost360 - Plant Master Route Handler
 * Endpoint: /organization/plants/
 */

require_once __DIR__ . '/../../config/auth.php';
requireAuth();
requireActiveCompany();

$_GET['page'] = 'plants';
require_once __DIR__ . '/../../dashboard.php';
