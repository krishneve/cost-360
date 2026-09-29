<?php
/**
 * Cost360 - Warehouse Master Route Handler
 * Endpoint: /organization/warehouses/
 */

require_once __DIR__ . '/../../config/auth.php';
requireAuth();
requireActiveCompany();

$_GET['page'] = 'warehouses';
require_once __DIR__ . '/../../dashboard.php';
