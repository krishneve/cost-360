<?php
/**
 * Cost360 - Customer Master Route Handler
 * Endpoint: /customers/master/
 */

require_once __DIR__ . '/../../config/auth.php';
requireAuth();

$_GET['page'] = 'customers';
require_once __DIR__ . '/../../dashboard.php';
