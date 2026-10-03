<?php
$file = 'c:/xampp/htdocs/cost-360-main/admin/cma/api.php';
$content = file_get_contents($file);

// 1. Remove strict isolation
$content = preg_replace('/\$activeCustomerId = 0;.*?\n\s*\$action = trim/s', '$activeCustomerId = 0;' . "\n\n\$action = trim", $content);
$content = preg_replace('/if \(\$activeCustomerId <= 0\) \{.*?cmaJsonResponse.*?\}\n/s', '', $content);

// 2. Fix list_users
$content = str_replace(
    'INNER JOIN user_organization_access uoa ON uoa.user_id = u.user_id AND uoa.customer_id = :customer_id',
    '',
    $content
);
$content = str_replace(
    '$params = [\':customer_id\' => $activeCustomerId];',
    '$params = [];',
    $content
);
$content = str_replace(
    'AND uoa.customer_id = :cid',
    '',
    $content
);
$content = str_replace(
    '$stmtAcc->execute([\':uid\' => $uid, \':cid\' => $activeCustomerId]);',
    '$stmtAcc->execute([\':uid\' => $uid]);',
    $content
);

// Fix subscription limit enforcement
$content = preg_replace('/\$limitInfo = checkUserLimit\(\$activeCustomerId\);/', '$limitInfo = [\'plan_name\' => \'Global Plan\', \'plan_code\' => \'GLOBAL\', \'max_users\' => 9999, \'current_users\' => 0, \'available_users\' => 9999, \'allowed\' => true, \'reason\' => \'\'];', $content);

$content = str_replace(
    '\'customer_name\' => $custData[\'customer_name\'] ?? \'Customer Organization\',',
    '\'customer_name\' => \'Global Organization\',',
    $content
);

// 3. Fix create_user
$content = preg_replace('/\$limitCheck = checkUserLimit\(\$activeCustomerId\);.*?\}\n/s', '', $content);
$content = preg_replace('/\/\/ Bind to Customer in user_organization_access.*?\$pdo->commit\(\);/s', '$pdo->commit();', $content);

// 4. Fix get_user_access
$content = str_replace(
    '$stmtU->execute([\':uid\' => $userId, \':cid\' => $activeCustomerId]);',
    '$stmtU->execute([\':uid\' => $userId]);',
    $content
);
$content = preg_replace('/INNER JOIN user_organization_access uoa ON u\.user_id = uoa\.user_id AND uoa\.customer_id = :cid/', '', $content);
$content = preg_replace('/if \(!\$u\) \{.*?cmaJsonResponse\(false, \'User does not belong to your customer organization\.\', \[\], 403\);\s*\}/s', 'if (!$u) { cmaJsonResponse(false, \'User not found.\', [], 404); }', $content);

$content = str_replace(
    '$stmtGrants->execute([\':uid\' => $userId, \':cid\' => $activeCustomerId]);',
    '$stmtGrants->execute([\':uid\' => $userId]);',
    $content
);

// 5. Fix save_user_access
$content = preg_replace('/WHERE user_id = :uid AND customer_id = :cid/', 'WHERE user_id = :uid', $content);
$content = str_replace(
    '$stmtChk->execute([\':uid\' => $userId, \':cid\' => $activeCustomerId]);',
    '$stmtChk->execute([\':uid\' => $userId]);',
    $content
);
$content = str_replace(
    '$stmtCompVerify->execute([\':cid\' => $activeCustomerId, \':comp_id\' => $compId]);',
    '$stmtCompVerify->execute([\':comp_id\' => $compId]);',
    $content
);
$content = str_replace(
    'WHERE c.customer_id = :cid AND',
    'WHERE',
    $content
);
$content = str_replace(
    'customer_id = :cid AND',
    '',
    $content
);
$content = preg_replace('/WHERE customer_id = :cid AND company_id = :comp_id/', 'WHERE company_id = :comp_id', $content);

$content = str_replace(
    '$stmtDivVerify->execute([\':cid\' => $activeCustomerId, \':div_id\' => $divId]);',
    '$stmtDivVerify->execute([\':div_id\' => $divId]);',
    $content
);
$content = str_replace(
    '$stmtPlantVerify->execute([\':cid\' => $activeCustomerId, \':plant_id\' => $locId]);',
    '$stmtPlantVerify->execute([\':plant_id\' => $locId]);',
    $content
);
$content = str_replace(
    '$stmtWhVerify->execute([\':cid\' => $activeCustomerId, \':wh_id\' => $locId]);',
    '$stmtWhVerify->execute([\':wh_id\' => $locId]);',
    $content
);

$content = str_replace(
    '$stmtDel->execute([\':uid\' => $userId, \':cid\' => $activeCustomerId]);',
    '$stmtDel->execute([\':uid\' => $userId]);',
    $content
);

$content = preg_replace('/if \(empty\(\$mappings\)\) \{.*?\} else \{/s', 'if (!empty($mappings)) {', $content);
$content = str_replace(
    'INSERT INTO user_organization_access (
                            user_id, customer_id, company_id, division_id, plant_id, warehouse_id, valid_from, status
                        ) VALUES (
                            :uid, :cid, :comp_id, :div_id, :plant_id, :wh_id, CURRENT_DATE, \'Active\'
                        )',
    'INSERT INTO user_organization_access (
                            user_id, company_id, division_id, plant_id, warehouse_id, valid_from, status
                        ) VALUES (
                            :uid, :comp_id, :div_id, :plant_id, :wh_id, CURRENT_DATE, \'Active\'
                        )',
    $content
);
$content = str_replace('\':cid\' => $activeCustomerId,', '', $content);

file_put_contents($file, $content);
echo "Modifications complete.";
