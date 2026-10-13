<?php
$content = file_get_contents('admin/customers/api.php');

$pattern = '/\$pdo->commit\(\);\s*sendApiResponse\(true,\s*\'Customer tenant and Customer Administrator created successfully with default FREE subscription\.\'/';

$replacement = <<<'NEW'
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

                sendApiResponse(true, 'Customer tenant and Customer Administrator created successfully with dedicated database provisioning.'
NEW;

$newContent = preg_replace($pattern, $replacement, $content, 1, $count);
if ($count > 0) {
    file_put_contents('admin/customers/api.php', $newContent);
    echo "Successfully hooked provisioning into admin/customers/api.php (count: $count)\n";
} else {
    echo "Regex pattern did not match.\n";
}
