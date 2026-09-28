<?php
/**
 * Cost360 - Customer Management Module Admin Endpoint
 */

require_once __DIR__ . '/../../config/auth.php';
requireAuth();

$_GET['page'] = 'customers';
require_once __DIR__ . '/../../dashboard.php';
