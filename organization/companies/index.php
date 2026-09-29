<?php
/**
 * Cost360 - Company Master Route Handler
 * Endpoint: /organization/companies/
 */

require_once __DIR__ . '/../../config/auth.php';
requireAuth();
requireActiveCompany();

$_GET['page'] = 'companies';
require_once __DIR__ . '/../../dashboard.php';
