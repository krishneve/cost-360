<?php
require_once __DIR__ . '/../config/auth.php';
$res = userCanAccessLocation('plant', 1, 4);
var_dump($res);
