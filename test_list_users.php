<?php
require 'c:/xampp/htdocs/cost-360-main/config/database.php';
require 'c:/xampp/htdocs/cost-360-main/config/auth.php';
session_start();
$_SESSION['user_id']=2;
$_SESSION['role_id']=2;
$_GET['action']='list_users';
ob_start();
require 'c:/xampp/htdocs/cost-360-main/admin/cma/api.php';
$out = ob_get_clean();
echo $out;
