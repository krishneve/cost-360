<?php
/**
 * Cost360 - Period Master Route Handler
 * Endpoint: /organization/periods/
 */

require_once __DIR__ . '/../../config/auth.php';
requireAuth();
requireActiveCompany();

$_GET['page'] = 'periods';
require_once __DIR__ . '/../../dashboard.php';
