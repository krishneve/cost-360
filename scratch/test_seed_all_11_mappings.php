<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$ctx = getUserCustomerDbContext(['user_id' => 1], 1);
$custPdo = $ctx['pdo'];
$custDbName = $ctx['database_name'];
$companyCode = 'ABC02';

echo "Testing default mappings generation for {$custDbName} (Company: {$companyCode})...\n";

$tablesDefs = [
    'items' => [
        'name' => 'Item Master Product Master',
        'sheet' => '01.ITEM-PRODUCT MASTER',
        'dataset_type' => 'MASTER',
        'mapping' => [
            'item_type' => 'item_type',
            'item_group_code' => 'item_group_code',
            'item_group_name' => 'item_group_name',
            'item_code' => 'item_code',
            'item_name' => 'item_name',
            'unit_of_measure' => 'unit_of_measure',
            'std_rate' => 'std_rate',
            'material_rate' => 'material_rate',
            'labour_rate' => 'labour_rate',
            'overheads' => 'overheads',
            'actual_rate' => 'actual_rate',
            'total' => 'total',
            'grade' => 'grade',
            'net_weight_kg' => 'net_weight_kg',
            'gross_weight_kg' => 'gross_weight_kg',
            'upload' => 'upload',
            'raw_material' => 'raw_material',
            'product' => 'product',
            'std_yield_pct' => 'std_yield_pct'
        ]
    ],
    'work_centers' => [
        'name' => 'Work Center Master',
        'sheet' => '02.WORK CENTER MASTER',
        'dataset_type' => 'MASTER',
        'mapping' => [
            'wc_code' => 'wc_code',
            'wc_name' => 'wc_name',
            'wc_group_code' => 'wc_group_code',
            'wc_group_name' => 'wc_group_name',
            'cc_code' => 'cc_code',
            'cc_name' => 'cc_name',
            'std_mhr' => 'std_mhr',
            'rated_hrs_day' => 'rated_hrs_day'
        ]
    ],
    'party_master' => [
        'name' => 'Party Master',
        'sheet' => '03.PARTY MASTER',
        'dataset_type' => 'MASTER',
        'mapping' => [
            'type' => 'type',
            'party_code' => 'party_code',
            'party_name' => 'party_name',
            'party_pan' => 'party_pan',
            'party_account' => 'party_account',
            'state' => 'state',
            'payment_terms_days' => 'payment_terms_days'
        ]
    ],
    'cost_center_master' => [
        'name' => 'Cost Center Master',
        'sheet' => '04.COST_CENTER_MASTER',
        'dataset_type' => 'MASTER',
        'mapping' => [
            'cc_code' => 'cc_code',
            'cc_name' => 'cc_name',
            'cc_type' => 'cc_type',
            'is_production' => 'is_production',
            'shift_capacity_hrs_per_month' => 'shift_capacity_hrs_per_month'
        ]
    ],
    'standard_cost' => [
        'name' => 'Standard Cost',
        'sheet' => '05. STANDARD_COST',
        'dataset_type' => 'TRANSACTION',
        'mapping' => [
            'product_code' => 'product_code',
            'std_material' => 'std_material',
            'std_conversion' => 'std_conversion',
            'std_total_cost' => 'std_total_cost',
            'std_rev_date' => 'std_revision_date',
            'std_revision_date' => 'std_revision_date',
            'processed_material' => 'processed_material',
            'processed_conversion' => 'processed_conversion',
            'processed_total_cost' => 'processed_total_cost'
        ]
    ],
    'bom' => [
        'name' => 'Bill of Materials',
        'sheet' => '05.A_BOM',
        'dataset_type' => 'TRANSACTION',
        'mapping' => [
            'parent_item_code' => 'parent_item_code',
            'item_code' => 'item_code',
            'parent_item_qty' => 'parent_item_qty',
            'child_item_qty' => 'child_item_qty',
            'uom' => 'uom',
            'material_rate' => 'material_rate',
            'labour_rate' => 'labour_rate',
            'std_rate' => 'std_rate',
            'processed_material_rate' => 'processed_material_rate',
            'processed_labour_rate' => 'processed_labour_rate',
            'processed_std_rate' => 'processed_std_rate',
            'scrap_item_code' => 'scrap_item_code',
            'scrap_qty' => 'scrap_qty',
            'scrap_unit' => 'scrap_unit',
            'byproduct_item_code' => 'byproduct_item_code',
            'byproduct_qty' => 'byproduct_qty',
            'byproduct_unit' => 'byproduct_unit',
            'wc_code' => 'wc_code'
        ]
    ],
    'routing' => [
        'name' => 'Routing Master',
        'sheet' => '05.B_ROUTING',
        'dataset_type' => 'TRANSACTION',
        'mapping' => [
            'product_code' => 'product_code',
            'cc_code' => 'cc_code',
            'machine_code' => 'machine_code',
            'std_machine_hrs' => 'std_machine_hrs',
            'std_labour_hrs' => 'std_labour_hrs',
            'std_machine_hr_rate' => 'std_machine_hr_rate',
            'std_labour_rate' => 'std_labour_rate',
            'std_conversion_cost' => 'std_conversion_cost'
        ]
    ],
    'material_transaction' => [
        'name' => 'Material Transactions',
        'sheet' => '06.MATERIAL TRANSACTION',
        'dataset_type' => 'TRANSACTION',
        'mapping' => [
            'doc_type' => 'doc_type',
            'doc_no' => 'doc_no',
            'doc_date' => 'doc_date',
            'party_code' => 'party_code',
            'party_name' => 'party_name',
            'item_code' => 'item_code',
            'item_name' => 'item_name',
            'uom' => 'uom',
            'qty' => 'qty',
            'rejected_qty' => 'rejected_qty',
            'gross_weight' => 'gross_weight',
            'net_weight' => 'net_weight',
            'weight_unit' => 'weight_unit',
            'actual_yield' => 'actual_yield',
            'std_yield' => 'std_yield',
            'rate' => 'rate',
            'amount' => 'amount',
            'std_rate' => 'std_rate',
            'actual_rate' => 'actual_rate',
            'std_realisation_rate' => 'std_realisation_rate',
            'approval_ref' => 'approval_ref',
            'remarks' => 'remarks',
            'cc_code' => 'cc_code',
            'product_code' => 'product_code'
        ]
    ],
    'downtime' => [
        'name' => 'Machine Downtime',
        'sheet' => '07.DOWNTIME',
        'dataset_type' => 'TRANSACTION',
        'mapping' => [
            'log_date' => 'log_date',
            'machine_code' => 'machine_code',
            'machine_name' => 'machine_name',
            'cc_code' => 'cc_code',
            'rated_hrs' => 'rated_hrs',
            'run_hrs' => 'run_hrs',
            'breakdown_hrs' => 'breakdown_hrs',
            'idle_hrs' => 'idle_hrs',
            'power_units_kwh' => 'power_units_kwh'
        ]
    ],
    'finance_transaction' => [
        'name' => 'Finance Transactions',
        'sheet' => '08.FINANCE TRANSACTION',
        'dataset_type' => 'TRANSACTION',
        'mapping' => [
            'no' => 'no',
            'date' => 'date',
            'oh_code' => 'oh_code',
            'oh_name' => 'oh_name',
            'oh_behaviour' => 'oh_behaviour',
            'cc_code' => 'cc_code',
            'cc_name' => 'cc_name',
            'amount' => 'amount',
            'remarks' => 'remarks'
        ]
    ],
    'trial_balance' => [
        'name' => 'Trial Balance',
        'sheet' => '09.TRIAL BALANCE',
        'dataset_type' => 'TRANSACTION',
        'mapping' => [
            'acc_code' => 'acc_code',
            'description' => 'description',
            'opening_balance' => 'opening_balance',
            'opening_balance_sign_d_c' => 'opening_balance_sign_d_c',
            'opening_debit_opening_credit' => 'opening_debit_opening_credit',
            'transacted_debit' => 'transacted_debit',
            'transacted_credit' => 'transacted_credit',
            'closing_balance' => 'closing_balance',
            'closing_balance_sign_d_c' => 'closing_balance_sign_d_c',
            'closing_debit_closing_credit' => 'closing_debit_closing_credit'
        ]
    ]
];

$insStmt = $custPdo->prepare("
    INSERT INTO public.saved_mappings (company_code, target_table, sheet_name, mapping, created_by, created_at, updated_at)
    VALUES (?, ?, ?, ?::jsonb, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ON CONFLICT (LOWER(company_code), LOWER(target_table), LOWER(sheet_name))
    DO NOTHING
");

foreach ($tablesDefs as $t => $info) {
    $insStmt->execute([$companyCode, $t, $info['sheet'], json_encode($info['mapping'])]);
    echo "✔ Processed {$t} ({$info['name']})\n";
}

echo "\nTotal rows now in {$custDbName}.saved_mappings:\n";
$rows = $custPdo->query("SELECT id, company_code, target_table, sheet_name, jsonb_object_keys(mapping) as k FROM public.saved_mappings")->fetchAll(PDO::FETCH_ASSOC);
$byTable = [];
foreach ($rows as $r) {
    $byTable[$r['target_table'] . ' (' . $r['sheet_name'] . ')'] = ($byTable[$r['target_table'] . ' (' . $r['sheet_name'] . ')'] ?? 0) + 1;
}
foreach ($byTable as $name => $count) {
    echo "  - {$name}: {$count} columns mapped\n";
}
