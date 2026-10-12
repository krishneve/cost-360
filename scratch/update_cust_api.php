<?php
// Update admin/customers/api.php to include provisionCustomerDatabase call and allow CMA Admin

$apiContent = file_get_contents('admin/customers/api.php');

// 1. Update permission check for create_customer
$oldPerm = "if (\$userRoleCode !== 'PLATFORM_ADMIN') {";
$newPerm = "if (\$userRoleCode !== 'PLATFORM_ADMIN' && \$userRoleCode !== 'CMA_ADMIN' && !isPlatformAdmin(\$authUserId) && !isCmaAdmin(\$authUserId)) {";
if (strpos($apiContent, $oldPerm) !== false) {
    $apiContent = str_replace($oldPerm, $newPerm, $apiContent);
    echo "Updated create_customer permission check.\n";
}

// 2. Add customer database provisioning right after $pdo->commit(); in create_customer
$oldCommit = <<<'OLD'
                $pdo->commit();

                sendApiResponse(true, 'Customer tenant and Customer Administrator created successfully with default FREE subscription.', [
OLD;

$newCommit = <<<'NEW'
                $pdo->commit();

                // 9. Automatically Provision Dedicated PostgreSQL Customer Database
                $dbProvisionInfo = null;
                try {
                    $dbProvisionInfo = provisionCustomerDatabase($newCustomerId);
                } catch (Throwable $provEx) {
                    error_log("Customer DB Provisioning Warning for Customer ID {$newCustomerId}: " . $provEx->getMessage());
                    $dbProvisionInfo = [
                        'success'       => false,
                        'customer_id'   => $newCustomerId,
                        'database_name' => 'cust_' . $newCustomerId,
                        'error'         => $provEx->getMessage()
                    ];
                }

                sendApiResponse(true, 'Customer tenant and Customer Administrator created successfully with dedicated database provisioning.', [
NEW;

if (strpos($apiContent, $oldCommit) !== false) {
    $apiContent = str_replace($oldCommit, $newCommit, $apiContent);
    echo "Hooked provisionCustomerDatabase into admin/customers/api.php.\n";
} else {
    echo "Commit block pattern not found in admin/customers/api.php.\n";
}

file_put_contents('admin/customers/api.php', $apiContent);
