<?php
$content = file_get_contents('config/upload_centre.php');

// 1. In getFilePreviewData, ensure target table exists dynamically on-the-fly
$oldPreviewTableBlock = <<<'OLD'
    $targetCols = getTargetTableColumns($targetTable, $custPdo);
OLD;

$newPreviewTableBlock = <<<'NEW'
    ensureTargetTableExistsInCustomerDb($custPdo, $targetTable);
    $targetCols = getTargetTableColumns($targetTable, $custPdo);
NEW;

// 2. In executeDataImport, dynamically create only targetTable on-demand
$oldImportTableBlock = <<<'OLD'
        // Ensure core customer tables exist in the customer database
        if (function_exists('ensureCustomerTablesExist')) {
            ensureCustomerTablesExist($custPdo);
        }

        // Check physically if $targetTable exists in customer database
        $chkTable = $custPdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = :tname 
            LIMIT 1
        ");
        $chkTable->execute([':tname' => $targetTable]);
        if (!$chkTable->fetch()) {
            if ($targetTable === 'period') {
                $chkAlt = $custPdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'periods' LIMIT 1");
                if ($chkAlt && $chkAlt->fetch()) {
                    $targetTable = 'periods';
                }
            }
        }

        // Final physical table existence check (if missing, auto-create dynamic table)
        $chkTableFinal = $custPdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = :tname 
            LIMIT 1
        ");
        $chkTableFinal->execute([':tname' => $targetTable]);
        if (!$chkTableFinal->fetch()) {
            // Auto-create missing target table in Customer Database on-the-fly
            try {
                $custPdo->exec("
                    CREATE TABLE IF NOT EXISTS public.{$targetTable} (
                        id BIGSERIAL PRIMARY KEY,
                        code VARCHAR(100),
                        name VARCHAR(255),
                        description TEXT,
                        status VARCHAR(20) DEFAULT 'Active',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    );
                ");
            } catch (Throwable $createEx) {
                return [
                    'ok' => false,
                    'message' => "Target table '{$targetTable}' does not exist in customer database ({$custDbName}) and auto-creation failed: " . $createEx->getMessage()
                ];
            }
        }
OLD;

$newImportTableBlock = <<<'NEW'
        // Dynamically ensure ONLY the requested target table exists in the dedicated customer database
        ensureTargetTableExistsInCustomerDb($custPdo, $targetTable);
NEW;

$content = str_replace($oldImportTableBlock, $newImportTableBlock, $content);

// Ensure getFilePreviewData has ensureTargetTableExistsInCustomerDb
if (strpos($content, 'ensureTargetTableExistsInCustomerDb($custPdo, $targetTable);') === false) {
    $content = str_replace(
        "    \$targetCols = getTargetTableColumns(\$targetTable, \$custPdo);",
        "    ensureTargetTableExistsInCustomerDb(\$custPdo, \$targetTable);\n    \$targetCols = getTargetTableColumns(\$targetTable, \$custPdo);",
        $content
    );
}

file_put_contents('config/upload_centre.php', $content);
echo "Updated upload_centre.php dynamic table execution successfully.\n";
