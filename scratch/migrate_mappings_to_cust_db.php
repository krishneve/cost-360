<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$custPdo = getCustomerDbConnection('cost360_customer_cust001');

echo "Creating public.saved_mappings in cost360_customer_cust001...\n";
$custPdo->exec("
    CREATE TABLE IF NOT EXISTS public.saved_mappings (
        id BIGSERIAL PRIMARY KEY,
        company_code VARCHAR(50) NOT NULL DEFAULT '',
        target_table VARCHAR(100) NOT NULL,
        sheet_name VARCHAR(255) NOT NULL DEFAULT '',
        mapping JSONB NOT NULL,
        created_by BIGINT,
        created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
    );

    CREATE UNIQUE INDEX IF NOT EXISTS idx_cust_saved_mappings_lookup 
    ON public.saved_mappings (LOWER(company_code), LOWER(target_table), LOWER(sheet_name));
");

echo "✔ Table created successfully in cost360_customer_cust001\n";

// Migrate existing mappings from global DB if table exists
$globalPdo = getDbConnection();
$hasGlobal = $globalPdo->query("SELECT to_regclass('public.saved_mappings')")->fetchColumn();
if ($hasGlobal) {
    $rows = $globalPdo->query("SELECT * FROM public.saved_mappings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $ins = $custPdo->prepare("
            INSERT INTO public.saved_mappings (company_code, target_table, sheet_name, mapping, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?::jsonb, ?, ?, ?)
            ON CONFLICT (LOWER(company_code), LOWER(target_table), LOWER(sheet_name))
            DO UPDATE SET mapping = EXCLUDED.mapping, updated_at = EXCLUDED.updated_at
        ");
        $ins->execute([
            'ABC02',
            $r['target_table'],
            $r['sheet_name'],
            is_array($r['mapping']) ? json_encode($r['mapping']) : $r['mapping'],
            $r['created_by'],
            $r['created_at'],
            $r['updated_at']
        ]);
        echo "   ✔ Migrated mapping for {$r['target_table']} ({$r['sheet_name']}) into cost360_customer_cust001\n";
    }

    // Drop global table so it NEVER collides
    $globalPdo->exec("DROP TABLE IF EXISTS public.saved_mappings CASCADE");
    echo "✔ Dropped public.saved_mappings from Global DB (no cross-tenant collision possible)\n";
}

// Verify rows in cost360_customer_cust001
$cnt = $custPdo->query("SELECT count(*) FROM public.saved_mappings")->fetchColumn();
echo "Total saved mappings in cost360_customer_cust001: {$cnt} rows\n";
