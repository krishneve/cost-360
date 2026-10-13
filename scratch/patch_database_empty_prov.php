<?php
$content = file_get_contents('config/database.php');

// 1. Remove ensureCustomerTablesExist call inside getCustomerDbConnection
$content = str_replace(
    "                // Ensure customer tables exist automatically on connection\n                ensureCustomerTablesExist(\$custPdo);\n",
    "",
    $content
);
$content = str_replace(
    "                // Ensure customer tables exist automatically on connection\r\n                ensureCustomerTablesExist(\$custPdo);\r\n",
    "",
    $content
);

// 2. Replace provisionCustomerDatabase to leave DB completely empty
$oldProvPattern = '/if\s*\(!function_exists\(\'provisionCustomerDatabase\'\)\)\s*\{[\s\S]*?\n\}/';

$newProvCode = <<<'NEWPROV'
if (!function_exists('provisionCustomerDatabase')) {
    /**
     * Provisions a dedicated Customer Database for a given customer_id.
     * The customer database remains completely empty initially (no business tables or schemas created).
     * Records/updates the mapping in customer_databases table in the Global DB.
     *
     * @param PDO|int $globalPdoOrCustomerId
     * @param int|null $customerId
     * @param string|null $customerCode
     * @return array
     */
    function provisionCustomerDatabase($globalPdoOrCustomerId, $customerId = null, ?string $customerCode = null): array {
        if ($globalPdoOrCustomerId instanceof PDO) {
            $globalPdo = $globalPdoOrCustomerId;
            $cid = (int)$customerId;
        } else {
            $globalPdo = getDbConnection();
            $cid = (int)$globalPdoOrCustomerId;
        }

        if ($cid <= 0) {
            return ['success' => false, 'error' => "Invalid Customer ID {$cid} provided for database provisioning."];
        }

        // Customer DB Naming Convention: cust<ID> (e.g. cust1, cust71)
        $dbName = "cust" . $cid;

        $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: 'localhost';
        $port = (int)(getenv('PGPORT') ?: getenv('DB_PORT') ?: 5432);
        $user = getenv('PGUSER') ?: getenv('DB_USER') ?: 'neondb_owner';

        try {
            // 1. Create PostgreSQL Database if it does not exist
            $chkDb = $globalPdo->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
            $chkDb->execute([':dbname' => $dbName]);
            if (!$chkDb->fetch()) {
                // CREATE DATABASE cannot run inside a transaction block in PostgreSQL
                $globalPdo->exec("CREATE DATABASE \"{$dbName}\"");
            }

            // 2. IMPORTANT: Database remains completely empty.
            // DO NOT create any business schemas, tables, or sample records during customer creation.

            // 3. Update or Insert mapping in customer_databases table in Global DB
            $chkMapping = $globalPdo->prepare("SELECT id FROM customer_databases WHERE customer_id = :cid LIMIT 1");
            $chkMapping->execute([':cid' => $cid]);
            $existingMappingId = $chkMapping->fetchColumn();

            if ($existingMappingId) {
                $updMap = $globalPdo->prepare("
                    UPDATE customer_databases SET
                        database_name = :dbname,
                        host = :host,
                        port = :port,
                        db_user = :db_user,
                        status = 'Active',
                        initialized_at = CURRENT_TIMESTAMP
                    WHERE customer_id = :cid
                ");
                $updMap->execute([
                    ':dbname' => $dbName,
                    ':host' => $host,
                    ':port' => $port,
                    ':db_user' => $user,
                    ':cid' => $cid
                ]);
            } else {
                $insMap = $globalPdo->prepare("
                    INSERT INTO customer_databases (
                        customer_id, database_name, host, port, db_user, db_password, status, created_at, initialized_at
                    ) VALUES (
                        :cid, :dbname, :host, :port, :db_user, NULL, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");
                $insMap->execute([
                    ':cid' => $cid,
                    ':dbname' => $dbName,
                    ':host' => $host,
                    ':port' => $port,
                    ':db_user' => $user
                ]);
            }

            return [
                'success' => true,
                'customer_id' => $cid,
                'database_name' => $dbName,
                'status' => 'Active',
                'tables' => [],
                'message' => "Customer database '{$dbName}' provisioned successfully (initially empty)."
            ];
        } catch (Throwable $e) {
            error_log("Customer Database Provisioning Failure (Customer ID: {$cid}): " . $e->getMessage());
            return [
                'success' => false,
                'customer_id' => $cid,
                'database_name' => $dbName,
                'error' => $e->getMessage()
            ];
        }
    }
}
NEWPROV;

$newContent = preg_replace($oldProvPattern, $newProvCode, $content, 1, $count);
if ($count > 0) {
    file_put_contents('config/database.php', $newContent);
    echo "Updated config/database.php successfully.\n";
} else {
    echo "Pattern matching failed for provisionCustomerDatabase.\n";
}
