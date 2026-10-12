<?php
/**
 * Patch script to update config/upload_centre.php and api/upload_api.php
 * with Master Upsert and Period-Wise Transaction Management.
 */

$file = __DIR__ . '/../config/upload_centre.php';
$code = file_get_contents($file);

// 1. Update getFinalizedCategoryDefinitions() to add dataset_type to all categories
// and period_id to transaction business keys.
$oldCatDefs = "        '01_ITEM_PRODUCT_MASTER' => [
            'key'             => '01_ITEM_PRODUCT_MASTER',";

$newCatDefs = "        '01_ITEM_PRODUCT_MASTER' => [
            'key'             => '01_ITEM_PRODUCT_MASTER',
            'dataset_type'    => 'MASTER',";

if (strpos($code, "'dataset_type'") === false) {
    // Add dataset_type to all categories
    $code = str_replace(
        "'01_ITEM_PRODUCT_MASTER' => [\n            'key'             => '01_ITEM_PRODUCT_MASTER',",
        "'01_ITEM_PRODUCT_MASTER' => [\n            'key'             => '01_ITEM_PRODUCT_MASTER',\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'02_WORK_CENTER_MASTER' => [\n            'key'             => '02_WORK_CENTER_MASTER',",
        "'02_WORK_CENTER_MASTER' => [\n            'key'             => '02_WORK_CENTER_MASTER',\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'03_PARTY_MASTER' => [\n            'key'             => '03_PARTY_MASTER',",
        "'03_PARTY_MASTER' => [\n            'key'             => '03_PARTY_MASTER',\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'04_COST_CENTER_MASTER' => [\n            'key'             => '04_COST_CENTER_MASTER',",
        "'04_COST_CENTER_MASTER' => [\n            'key'             => '04_COST_CENTER_MASTER',\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'05_STANDARD_COST' => [\n            'key'             => '05_STANDARD_COST',",
        "'05_STANDARD_COST' => [\n            'key'             => '05_STANDARD_COST',\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'05A_BOM' => [\n            'key'             => '05A_BOM',",
        "'05A_BOM' => [\n            'key'             => '05A_BOM',\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'05B_ROUTING' => [\n            'key'             => '05B_ROUTING',",
        "'05B_ROUTING' => [\n            'key'             => '05B_ROUTING',\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'06_MATERIAL_TRANSACTION' => [\n            'key'             => '06_MATERIAL_TRANSACTION',",
        "'06_MATERIAL_TRANSACTION' => [\n            'key'             => '06_MATERIAL_TRANSACTION',\n            'dataset_type'    => 'TRANSACTION',",
        $code
    );
    $code = str_replace(
        "'07_DOWNTIME' => [\n            'key'             => '07_DOWNTIME',",
        "'07_DOWNTIME' => [\n            'key'             => '07_DOWNTIME',\n            'dataset_type'    => 'TRANSACTION',",
        $code
    );
    $code = str_replace(
        "'08_FINANCE_TRANSACTION' => [\n            'key'             => '08_FINANCE_TRANSACTION',",
        "'08_FINANCE_TRANSACTION' => [\n            'key'             => '08_FINANCE_TRANSACTION',\n            'dataset_type'    => 'TRANSACTION',",
        $code
    );
    $code = str_replace(
        "'09_TRIAL_BALANCE' => [\n            'key'             => '09_TRIAL_BALANCE',",
        "'09_TRIAL_BALANCE' => [\n            'key'             => '09_TRIAL_BALANCE',\n            'dataset_type'    => 'TRANSACTION',",
        $code
    );

    // Also support CRLF if needed
    $code = str_replace(
        "'01_ITEM_PRODUCT_MASTER' => [\r\n            'key'             => '01_ITEM_PRODUCT_MASTER',",
        "'01_ITEM_PRODUCT_MASTER' => [\r\n            'key'             => '01_ITEM_PRODUCT_MASTER',\r\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'02_WORK_CENTER_MASTER' => [\r\n            'key'             => '02_WORK_CENTER_MASTER',",
        "'02_WORK_CENTER_MASTER' => [\r\n            'key'             => '02_WORK_CENTER_MASTER',\r\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'03_PARTY_MASTER' => [\r\n            'key'             => '03_PARTY_MASTER',",
        "'03_PARTY_MASTER' => [\r\n            'key'             => '03_PARTY_MASTER',\r\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'04_COST_CENTER_MASTER' => [\r\n            'key'             => '04_COST_CENTER_MASTER',",
        "'04_COST_CENTER_MASTER' => [\r\n            'key'             => '04_COST_CENTER_MASTER',\r\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'05_STANDARD_COST' => [\r\n            'key'             => '05_STANDARD_COST',",
        "'05_STANDARD_COST' => [\r\n            'key'             => '05_STANDARD_COST',\r\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'05A_BOM' => [\r\n            'key'             => '05A_BOM',",
        "'05A_BOM' => [\r\n            'key'             => '05A_BOM',\r\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'05B_ROUTING' => [\r\n            'key'             => '05B_ROUTING',",
        "'05B_ROUTING' => [\r\n            'key'             => '05B_ROUTING',\r\n            'dataset_type'    => 'MASTER',",
        $code
    );
    $code = str_replace(
        "'06_MATERIAL_TRANSACTION' => [\r\n            'key'             => '06_MATERIAL_TRANSACTION',",
        "'06_MATERIAL_TRANSACTION' => [\r\n            'key'             => '06_MATERIAL_TRANSACTION',\r\n            'dataset_type'    => 'TRANSACTION',",
        $code
    );
    $code = str_replace(
        "'07_DOWNTIME' => [\r\n            'key'             => '07_DOWNTIME',",
        "'07_DOWNTIME' => [\r\n            'key'             => '07_DOWNTIME',\r\n            'dataset_type'    => 'TRANSACTION',",
        $code
    );
    $code = str_replace(
        "'08_FINANCE_TRANSACTION' => [\r\n            'key'             => '08_FINANCE_TRANSACTION',",
        "'08_FINANCE_TRANSACTION' => [\r\n            'key'             => '08_FINANCE_TRANSACTION',\r\n            'dataset_type'    => 'TRANSACTION',",
        $code
    );
    $code = str_replace(
        "'09_TRIAL_BALANCE' => [\r\n            'key'             => '09_TRIAL_BALANCE',",
        "'09_TRIAL_BALANCE' => [\r\n            'key'             => '09_TRIAL_BALANCE',\r\n            'dataset_type'    => 'TRANSACTION',",
        $code
    );
}

// 2. Add helper functions for dataset type and period management
$helperCode = <<<'PHP'

/**
 * Returns true if the dataset category is Master/Reference data (Worksheets 1-7).
 */
function isMasterDataset(string $categoryKey): bool {
    $defs = getFinalizedCategoryDefinitions();
    return ($defs[$categoryKey]['dataset_type'] ?? 'MASTER') === 'MASTER';
}

/**
 * Resolves accounting period context from request, session, or latest open period.
 */
function getAccountingPeriodContext(?int $periodId = null): ?array {
    $globalPdo = getDbConnection();
    if ($periodId && $periodId > 0) {
        $stmt = $globalPdo->prepare("SELECT * FROM public.periods WHERE period_id = ?");
        $stmt->execute([$periodId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) return $p;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['active_period_id'])) {
        $stmt = $globalPdo->prepare("SELECT * FROM public.periods WHERE period_id = ?");
        $stmt->execute([(int)$_SESSION['active_period_id']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) return $p;
    }
    // Fallback to latest active period
    $stmt = $globalPdo->query("SELECT * FROM public.periods WHERE UPPER(status) = 'ACTIVE' ORDER BY start_date DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Validates accounting period before transaction import.
 * Ensures period is not locked and is currently active.
 */
function validateAccountingPeriodForImport(?array $period): array {
    if (!$period) {
        return ['valid' => false, 'error' => 'No active accounting period selected or resolved for transaction import.'];
    }
    if (strtoupper($period['status'] ?? '') !== 'ACTIVE') {
        return ['valid' => false, 'error' => "Accounting period '{$period['period_name']}' is not Active (Status: {$period['status']}). Transactions cannot be imported."];
    }
    if (!empty($period['is_locked'])) {
        return ['valid' => false, 'error' => "Accounting period '{$period['period_name']}' is LOCKED. Transaction data imports are strictly prohibited into locked periods."];
    }
    return ['valid' => true, 'period' => $period];
}
PHP;

if (strpos($code, 'function isMasterDataset') === false) {
    $code = str_replace(
        "function classifyWorksheet(",
        $helperCode . "\n\nfunction classifyWorksheet(",
        $code
    );
}

file_put_contents($file, $code);
echo "SUCCESS: Added dataset_type and period helpers to config/upload_centre.php\n";
