<?php
/**
 * Cost360 - Division Master Route Handler
 * Endpoint: /organization/divisions/
 */

require_once __DIR__ . '/../../config/auth.php';
requireAuth();
requireActiveCompany();

$_GET['page'] = 'divisions';
require_once __DIR__ . '/../../dashboard.php';
