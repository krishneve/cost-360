<?php
require_once __DIR__ . '/../config/upload_centre.php';

$ctx = getUserCustomerDbContext(['user_id' => 1], 1);
$pdo = $ctx['pdo'];

// Delete temporary test mappings
$pdo->exec("DELETE FROM public.saved_mappings WHERE id IN (1, 4)");

// Standardize standard_cost mapping for ABC02
$stdMap = json_encode([
    'product_code' => 'product_code',
    'std_material' => 'std_material',
    'std_rev_date' => 'std_revision_date',
    'std_conversion' => 'std_conversion',
    'std_total_cost' => 'std_total_cost'
]);

$upd = $pdo->prepare("UPDATE public.saved_mappings SET mapping = ?::jsonb, updated_at = CURRENT_TIMESTAMP WHERE id = 2");
$upd->execute([$stdMap]);

echo "Successfully cleaned up customer saved_mappings table.\n";
