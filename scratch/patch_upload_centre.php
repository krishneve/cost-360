<?php
$content = file_get_contents('config/upload_centre.php');

// 1. Replace getTargetTableColumns
$oldGetCols = <<<'OLD'
// Dynamically discover columns and data types of target tables in public schema
function getTargetTableColumns($tableName) {
    try {
        $stmt = dbQuery("
            SELECT column_name, data_type, is_nullable 
            FROM information_schema.columns 
            WHERE table_schema = 'public' AND table_name = ?
            ORDER BY ordinal_position
        ", [$tableName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
OLD;

$newGetCols = <<<'NEW'
// Dynamically discover columns and data types of target tables in public schema
function getTargetTableColumns($tableName, $customerId = null) {
    try {
        if ($customerId !== null && $customerId > 0) {
            $pdo = getCustomerDbConnection($customerId);
        } elseif (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['active_customer_id'])) {
            $pdo = getCustomerDbConnection((int)$_SESSION['active_customer_id']);
        } else {
            $pdo = getDbConnection();
        }

        $stmt = $pdo->prepare("
            SELECT column_name, data_type, is_nullable 
            FROM information_schema.columns 
            WHERE table_schema = 'public' AND table_name = ?
            ORDER BY ordinal_position
        ");
        $stmt->execute([$tableName]);
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cols)) {
            $altTable = ($tableName === 'item_master') ? 'items' : (($tableName === 'items') ? 'item_master' : null);
            if ($altTable) {
                $stmt->execute([$altTable]);
                $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}
NEW;

if (strpos($content, $oldGetCols) !== false) {
    $content = str_replace($oldGetCols, $newGetCols, $content);
    echo "Updated getTargetTableColumns successfully.\n";
} else {
    echo "Old getTargetTableColumns pattern not found.\n";
}

// 2. Replace the connection block in executeDataImport
$oldConnBlock = <<<'OLD'
    // Resolve Customer DB PDO handle
    try {
        $pdo = getCustomerDbConnection($targetCustomerId, $userId);
    } catch (Throwable $e) {
        error_log("Customer DB Routing Warning (using central connection fallback): " . $e->getMessage());
        $pdo = getDbConnection();
    }
OLD;

$newConnBlock = <<<'NEW'
    // Resolve Customer DB PDO handle strictly
    try {
        $pdo = getCustomerDbConnection($targetCustomerId, $userId);
    } catch (Throwable $e) {
        logUploadAudit($userId, $username, 'IMPORT_FAILED', 'Upload Centre', null, 'Failed', ['upload_id' => $uploadId, 'error' => $e->getMessage()]);
        return ['ok' => false, 'message' => 'Customer Database Routing Error: ' . $e->getMessage()];
    }
NEW;

if (strpos($content, $oldConnBlock) !== false) {
    $content = str_replace($oldConnBlock, $newConnBlock, $content);
    echo "Updated strict Customer DB routing in executeDataImport.\n";
} else {
    echo "Connection block pattern not found in executeDataImport.\n";
}

file_put_contents('config/upload_centre.php', $content);
