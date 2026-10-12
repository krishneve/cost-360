<?php
$content = file_get_contents('config/database.php');

$pattern = '/function getCustomerDatabaseMapping\(\$customerId\)\s*\{[\s\S]*?return \$mapping;\s*\}/';

$replacement = <<<'NEW'
function getCustomerDatabaseMapping($customerId) {
        $cid = (int)$customerId;
        if ($cid <= 0) {
            throw new Exception("Invalid Customer ID specified.");
        }

        $centralPdo = getDbConnection();
        $stmt = $centralPdo->prepare("
            SELECT id, customer_id, database_name, host, port, db_user, db_password, status
            FROM customer_databases
            WHERE customer_id = :cid
            LIMIT 1
        ");
        $stmt->execute([':cid' => $cid]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mapping || empty($mapping['database_name'])) {
            // Auto-heal and create mapping if customer exists in customers table
            $chkCust = $centralPdo->prepare("SELECT customer_id FROM customers WHERE customer_id = :cid LIMIT 1");
            $chkCust->execute([':cid' => $cid]);
            if ($chkCust->fetch()) {
                $dbName = "cust_" . $cid;
                $insMap = $centralPdo->prepare("
                    INSERT INTO customer_databases (customer_id, database_name, status, created_at, initialized_at)
                    VALUES (:cid, :dbname, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT (customer_id) 
                    DO UPDATE SET database_name = EXCLUDED.database_name, status = 'Active', initialized_at = CURRENT_TIMESTAMP
                ");
                $insMap->execute([':cid' => $cid, ':dbname' => $dbName]);

                $stmt->execute([':cid' => $cid]);
                $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        if (!$mapping || empty($mapping['database_name'])) {
            throw new Exception("Missing customer_database mapping for Customer ID {$cid}.");
        }

        if (isset($mapping['status']) && strtolower(trim($mapping['status'])) !== 'active') {
            throw new Exception("Customer database is inactive for Customer ID {$cid}.");
        }

        return $mapping;
    }
NEW;

$newContent = preg_replace($pattern, $replacement, $content, 1, $count);
if ($count > 0) {
    file_put_contents('config/database.php', $newContent);
    echo "Updated getCustomerDatabaseMapping with auto-healing successfully.\n";
} else {
    echo "Pattern match failed.\n";
}
