<?php
/**
 * Cost360 - Unified Uploaded Data Reporting Backend (Phase 1)
 *
 * Implements a unified, secure, read-only reporting service for all 11 canonical
 * datasets uploaded through the Upload Centre into customer-specific PostgreSQL databases.
 *
 * Security & Architecture Principles:
 *   - Strictly queries existing customer databases (cost360_customer_cust<ID>).
 *   - Read-only execution: NEVER inserts, updates, or deletes records.
 *   - Strict allowlists for datasets, sortable columns, and filterable fields.
 *   - All SQL inputs parameterized (PDO prepared statements). Table/column names are strictly checked against immutable registries.
 *   - Multi-tenant isolation: enforces customer DB routing, company scope, and period isolation.
 *   - Zero mock/sample data: every metric and row is computed from actual database records.
 *   - Graceful handling of missing tables without on-demand DDL execution.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/upload_centre.php';

/**
 * Returns the immutable catalogue registry of all 11 supported upload datasets.
 *
 * @return array<string, array>
 */
function getReportableDatasetsCatalogueRegistry(): array {
    return [
        '01_ITEM_PRODUCT_MASTER' => [
            'dataset_key'          => '01_ITEM_PRODUCT_MASTER',
            'target_table'         => 'items',
            'primary_key'          => 'item_id',
            'dataset_type'         => 'MASTER',
            'display_name'         => 'Item Master & Product Master',
            'category'             => 'Master Data',
            'description'          => 'Catalog of raw materials, work-in-progress, finished goods, standard rates, weights, and yield standards.',
            'business_key'         => ['item_code'],
            'primary_date_column'  => null,
            'allowed_sort_columns' => [
                'item_id', 'item_code', 'item_name', 'item_type', 'item_group_code', 'item_group_name',
                'unit_of_measure', 'std_rate', 'material_rate', 'labour_rate', 'overheads',
                'actual_rate', 'total', 'grade', 'net_weight_kg', 'gross_weight_kg', 'std_yield_pct',
                'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'item_code', 'item_type', 'item_group_code', 'grade', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['item_code', 'item_name', 'item_type', 'item_group_code', 'item_group_name', 'grade'],
            'numeric_columns'      => ['std_rate', 'material_rate', 'labour_rate', 'overheads', 'actual_rate', 'total', 'net_weight_kg', 'gross_weight_kg', 'std_yield_pct'],
            'display_columns'      => [
                'item_id', 'item_code', 'item_name', 'item_type', 'item_group_code', 'item_group_name',
                'unit_of_measure', 'std_rate', 'material_rate', 'labour_rate', 'overheads',
                'actual_rate', 'total', 'grade', 'net_weight_kg', 'gross_weight_kg', 'std_yield_pct',
                'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '02_WORK_CENTER_MASTER' => [
            'dataset_key'          => '02_WORK_CENTER_MASTER',
            'target_table'         => 'work_centers',
            'primary_key'          => 'work_center_id',
            'dataset_type'         => 'MASTER',
            'display_name'         => 'Work Center Master',
            'category'             => 'Master Data',
            'description'          => 'Production and processing centers, rated daily hours, machine hour rates, and cost center linkages.',
            'business_key'         => ['wc_code'],
            'primary_date_column'  => null,
            'allowed_sort_columns' => [
                'work_center_id', 'wc_code', 'wc_name', 'wc_group_code', 'wc_group_name', 'cc_code', 'cc_name',
                'std_mhr', 'rated_hrs_day', 'hourly_rate', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'wc_code', 'wc_group_code', 'cc_code', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['wc_code', 'wc_name', 'wc_group_code', 'wc_group_name', 'cc_code', 'cc_name'],
            'numeric_columns'      => ['std_mhr', 'rated_hrs_day', 'hourly_rate'],
            'display_columns'      => [
                'work_center_id', 'wc_code', 'wc_name', 'wc_group_code', 'wc_group_name', 'cc_code', 'cc_name',
                'std_mhr', 'rated_hrs_day', 'hourly_rate', 'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '03_PARTY_MASTER' => [
            'dataset_key'          => '03_PARTY_MASTER',
            'target_table'         => 'party_master',
            'primary_key'          => 'id',
            'dataset_type'         => 'MASTER',
            'display_name'         => 'Party Master',
            'category'             => 'Master Data',
            'description'          => 'Suppliers, vendors, customers, PAN numbers, ledger accounts, state jurisdictions, and credit payment terms.',
            'business_key'         => ['party_code'],
            'primary_date_column'  => null,
            'allowed_sort_columns' => [
                'id', 'party_code', 'party_name', 'type', 'party_pan', 'party_account', 'state',
                'payment_terms_days', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'party_code', 'type', 'state', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['party_code', 'party_name', 'type', 'party_pan', 'party_account', 'state'],
            'numeric_columns'      => ['payment_terms_days'],
            'display_columns'      => [
                'id', 'type', 'party_code', 'party_name', 'party_pan', 'party_account', 'state',
                'payment_terms_days', 'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '04_COST_CENTER_MASTER' => [
            'dataset_key'          => '04_COST_CENTER_MASTER',
            'target_table'         => 'cost_center_master',
            'primary_key'          => 'id',
            'dataset_type'         => 'MASTER',
            'display_name'         => 'Cost Center Master',
            'category'             => 'Master Data',
            'description'          => 'Organizational cost centers, operational classifications, production indicators, and monthly shift capacities.',
            'business_key'         => ['cc_code'],
            'primary_date_column'  => null,
            'allowed_sort_columns' => [
                'id', 'cc_code', 'cc_name', 'cc_type', 'is_production', 'shift_capacity_hrs_per_month', 'department', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'cc_code', 'cc_type', 'is_production', 'department', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['cc_code', 'cc_name', 'cc_type', 'department'],
            'numeric_columns'      => ['is_production', 'shift_capacity_hrs_per_month'],
            'display_columns'      => [
                'id', 'cc_code', 'cc_name', 'cc_type', 'is_production', 'shift_capacity_hrs_per_month',
                'department', 'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '05_STANDARD_COST' => [
            'dataset_key'          => '05_STANDARD_COST',
            'target_table'         => 'standard_cost',
            'primary_key'          => 'id',
            'dataset_type'         => 'TRANSACTION',
            'display_name'         => 'Standard Cost',
            'category'             => 'Standard Rates & Engineering',
            'description'          => 'Pre-determined standard material, conversion, and total product costs keyed by revision date.',
            'business_key'         => ['product_code', 'std_revision_date'],
            'primary_date_column'  => 'std_revision_date',
            'allowed_sort_columns' => [
                'id', 'cost_id', 'product_code', 'item_code', 'std_material', 'std_conversion', 'std_total_cost',
                'std_revision_date', 'processed_material', 'processed_conversion', 'processed_total_cost', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'product_code', 'item_code', 'std_revision_date', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['product_code', 'item_code'],
            'numeric_columns'      => ['std_material', 'std_conversion', 'std_total_cost', 'processed_material', 'processed_conversion', 'processed_total_cost'],
            'display_columns'      => [
                'id', 'product_code', 'item_code', 'std_material', 'std_conversion', 'std_total_cost',
                'std_revision_date', 'processed_material', 'processed_conversion', 'processed_total_cost',
                'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '05A_BOM' => [
            'dataset_key'          => '05A_BOM',
            'target_table'         => 'bom',
            'primary_key'          => 'id',
            'dataset_type'         => 'TRANSACTION',
            'display_name'         => 'Bill of Materials (BOM)',
            'category'             => 'Standard Rates & Engineering',
            'description'          => 'Multi-level manufacturing recipe relationships, parent/child component quantities, scrap, and by-products.',
            'business_key'         => ['parent_item_code', 'item_code'],
            'primary_date_column'  => null,
            'allowed_sort_columns' => [
                'id', 'parent_item_code', 'item_code', 'parent_item_qty', 'child_item_qty', 'quantity', 'uom',
                'material_rate', 'labour_rate', 'std_rate', 'scrap_item_code', 'scrap_qty',
                'byproduct_item_code', 'byproduct_qty', 'wc_code', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'parent_item_code', 'item_code', 'wc_code', 'scrap_item_code', 'byproduct_item_code',
                'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['parent_item_code', 'item_code', 'scrap_item_code', 'byproduct_item_code', 'wc_code'],
            'numeric_columns'      => ['parent_item_qty', 'child_item_qty', 'quantity', 'material_rate', 'labour_rate', 'std_rate', 'scrap_qty', 'byproduct_qty'],
            'display_columns'      => [
                'id', 'parent_item_code', 'item_code', 'parent_item_qty', 'child_item_qty', 'uom',
                'material_rate', 'labour_rate', 'std_rate', 'scrap_item_code', 'scrap_qty',
                'byproduct_item_code', 'byproduct_qty', 'wc_code', 'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '05B_ROUTING' => [
            'dataset_key'          => '05B_ROUTING',
            'target_table'         => 'routing',
            'primary_key'          => 'id',
            'dataset_type'         => 'TRANSACTION',
            'display_name'         => 'Manufacturing Routing',
            'category'             => 'Standard Rates & Engineering',
            'description'          => 'Operational sequences, cost center and machine assignments, standard machine/labor hours, and conversion rates.',
            'business_key'         => ['product_code', 'cc_code', 'machine_code'],
            'primary_date_column'  => null,
            'allowed_sort_columns' => [
                'id', 'product_code', 'item_code', 'cc_code', 'machine_code', 'std_machine_hrs', 'std_labour_hrs',
                'std_machine_hr_rate', 'std_labour_rate', 'std_conversion_cost', 'operation_seq', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'product_code', 'item_code', 'cc_code', 'machine_code', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['product_code', 'item_code', 'cc_code', 'machine_code'],
            'numeric_columns'      => ['std_machine_hrs', 'std_labour_hrs', 'std_machine_hr_rate', 'std_labour_rate', 'std_conversion_cost'],
            'display_columns'      => [
                'id', 'product_code', 'item_code', 'cc_code', 'machine_code', 'std_machine_hrs', 'std_labour_hrs',
                'std_machine_hr_rate', 'std_labour_rate', 'std_conversion_cost', 'operation_seq',
                'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '06_MATERIAL_TRANSACTION' => [
            'dataset_key'          => '06_MATERIAL_TRANSACTION',
            'target_table'         => 'material_transaction',
            'primary_key'          => 'id',
            'dataset_type'         => 'TRANSACTION',
            'display_name'         => 'Material Transactions',
            'category'             => 'Operational Data',
            'description'          => 'Warehouse movements, goods receipts (GRN), issues/consumption, production receipts, dispatches, and transfers.',
            'business_key'         => ['doc_no', 'item_code'],
            'primary_date_column'  => 'doc_date',
            'allowed_sort_columns' => [
                'id', 'doc_type', 'transaction_type', 'doc_no', 'doc_date', 'party_code', 'party_name',
                'item_code', 'item_name', 'uom', 'qty', 'rejected_qty', 'rate', 'amount', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'doc_type', 'transaction_type', 'doc_no', 'party_code', 'item_code', 'cc_code', 'product_code',
                'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['doc_no', 'doc_type', 'transaction_type', 'party_code', 'party_name', 'item_code', 'item_name', 'remarks', 'approval_ref'],
            'numeric_columns'      => ['qty', 'rejected_qty', 'gross_weight', 'net_weight', 'actual_yield', 'std_yield', 'rate', 'amount', 'std_rate', 'actual_rate', 'std_realisation_rate'],
            'display_columns'      => [
                'id', 'doc_type', 'transaction_type', 'doc_no', 'doc_date', 'party_code', 'party_name',
                'item_code', 'item_name', 'uom', 'qty', 'rate', 'amount', 'cc_code', 'product_code',
                'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '07_DOWNTIME' => [
            'dataset_key'          => '07_DOWNTIME',
            'target_table'         => 'downtime',
            'primary_key'          => 'id',
            'dataset_type'         => 'TRANSACTION',
            'display_name'         => 'Machine Downtime & Power',
            'category'             => 'Operational Data',
            'description'          => 'Equipment utilization logs, rated/run/breakdown/idle operational hours, and electrical power consumption (kWh).',
            'business_key'         => ['log_date', 'machine_code'],
            'primary_date_column'  => 'log_date',
            'allowed_sort_columns' => [
                'id', 'log_date', 'machine_code', 'machine_name', 'cc_code', 'rated_hrs',
                'run_hrs', 'breakdown_hrs', 'idle_hrs', 'power_units_kwh', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'log_date', 'machine_code', 'cc_code', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['machine_code', 'machine_name', 'cc_code'],
            'numeric_columns'      => ['rated_hrs', 'run_hrs', 'breakdown_hrs', 'idle_hrs', 'power_units_kwh'],
            'display_columns'      => [
                'id', 'log_date', 'machine_code', 'machine_name', 'cc_code', 'rated_hrs', 'run_hrs',
                'breakdown_hrs', 'idle_hrs', 'power_units_kwh', 'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '08_FINANCE_TRANSACTION' => [
            'dataset_key'          => '08_FINANCE_TRANSACTION',
            'target_table'         => 'finance_transaction',
            'primary_key'          => 'id',
            'dataset_type'         => 'TRANSACTION',
            'display_name'         => 'Finance Overhead Transactions',
            'category'             => 'Financial Data',
            'description'          => 'Financial expense vouchers, overhead allocations, cost center assignments, and cost behavior classifications.',
            'business_key'         => ['no', 'date', 'oh_code'],
            'primary_date_column'  => 'date',
            'allowed_sort_columns' => [
                'id', 'no', 'date', 'oh_code', 'oh_name', 'oh_behaviour', 'cc_code', 'cc_name',
                'amount', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'no', 'date', 'oh_code', 'oh_behaviour', 'cc_code', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['no', 'oh_code', 'oh_name', 'oh_behaviour', 'cc_code', 'cc_name', 'remarks'],
            'numeric_columns'      => ['amount'],
            'display_columns'      => [
                'id', 'no', 'date', 'oh_code', 'oh_name', 'oh_behaviour', 'cc_code', 'cc_name',
                'amount', 'remarks', 'company_code', 'period_code', 'status', 'created_at'
            ]
        ],
        '09_TRIAL_BALANCE' => [
            'dataset_key'          => '09_TRIAL_BALANCE',
            'target_table'         => 'trial_balance',
            'primary_key'          => 'id',
            'dataset_type'         => 'TRANSACTION',
            'display_name'         => 'Trial Balance & GL Summary',
            'category'             => 'Financial Data',
            'description'          => 'General Ledger accounts, opening balances, periodic debit/credit movements, and closing positions.',
            'business_key'         => ['acc_code'],
            'primary_date_column'  => null,
            'allowed_sort_columns' => [
                'id', 'acc_code', 'description', 'opening_balance', 'opening_balance_sign_d_c',
                'transacted_debit', 'transacted_credit', 'closing_balance', 'closing_balance_sign_d_c',
                'closing_debit_closing_credit', 'created_at', 'modified_at'
            ],
            'filterable_columns'   => [
                'acc_code', 'opening_balance_sign_d_c', 'closing_balance_sign_d_c', 'company_code', 'division_code', 'location_code', 'period_id', 'period_code', 'status'
            ],
            'searchable_columns'   => ['acc_code', 'description'],
            'numeric_columns'      => ['opening_balance', 'opening_debit_opening_credit', 'transacted_debit', 'transacted_credit', 'closing_balance', 'closing_debit_closing_credit'],
            'display_columns'      => [
                'id', 'acc_code', 'description', 'opening_balance', 'opening_balance_sign_d_c',
                'transacted_debit', 'transacted_credit', 'closing_balance', 'closing_balance_sign_d_c',
                'company_code', 'period_code', 'status', 'created_at'
            ]
        ]
    ];
}

/**
 * Resolves a dataset key or table name to its canonical definition in the registry.
 * Case-insensitive, supports key, table name, or friendly alias.
 *
 * @param string $keyOrTable
 * @return array|null
 */
function findReportableDataset(string $keyOrTable): ?array {
    $registry = getReportableDatasetsCatalogueRegistry();
    $cleaned = strtoupper(trim($keyOrTable));

    if (isset($registry[$cleaned])) {
        return $registry[$cleaned];
    }

    $lower = strtolower(trim($keyOrTable));
    foreach ($registry as $def) {
        if (strtolower($def['target_table']) === $lower || strtolower($def['dataset_key']) === $lower) {
            return $def;
        }
    }

    return null;
}

/**
 * Checks whether a given table exists in the public schema of the customer database.
 * Does NOT execute any DDL or create tables on demand.
 *
 * @param PDO    $custPdo
 * @param string $tableName
 * @return bool
 */
function checkCustomerTableExists(PDO $custPdo, string $tableName): bool {
    static $cache = [];
    $cacheKey = spl_object_hash($custPdo) . ':' . $tableName;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $custPdo->prepare("
            SELECT 1 
            FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = :tname
            LIMIT 1
        ");
        $stmt->execute([':tname' => $tableName]);
        $exists = (bool)$stmt->fetchColumn();
        $cache[$cacheKey] = $exists;
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Retrieves the catalogue of all 11 supported datasets with actual availability,
 * schema status, and record counts from the dedicated customer database.
 *
 * @param PDO   $custPdo Dedicated Customer DB connection
 * @param array $context ['company_code' => string, 'period_id' => int]
 * @return array Structured catalogue response
 */
function getReportableDatasetsCatalogue(PDO $custPdo, array $context = []): array {
    $registry = getReportableDatasetsCatalogueRegistry();
    $companyCode = !empty($context['company_code']) ? trim((string)$context['company_code']) : null;
    $periodId = !empty($context['period_id']) ? (int)$context['period_id'] : null;

    $catalogue = [];
    $totalRecordsAcrossAll = 0;
    $availableDatasetsCount = 0;

    // Fetch all existing public tables in one single query to eliminate round-trips
    try {
        $tablesStmt = $custPdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
        $existingTables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existingTablesMap = array_fill_keys(array_map('strtolower', $existingTables), true);
    } catch (Throwable $e) {
        $existingTablesMap = [];
    }

    foreach ($registry as $key => $def) {
        $tableName = $def['target_table'];
        $tableExists = isset($existingTablesMap[strtolower($tableName)]);

        $recordCount = 0;
        $scopedCount = 0;
        $latestRecordAt = null;

        if ($tableExists) {
            $availableDatasetsCount++;
            try {
                // Total record count in table
                $totalStmt = $custPdo->query("SELECT count(*) FROM public.{$tableName}");
                $recordCount = (int)($totalStmt->fetchColumn() ?: 0);
                $totalRecordsAcrossAll += $recordCount;

                // Scoped record count matching context
                $whereClauses = [];
                $params = [];
                if ($companyCode !== null) {
                    $whereClauses[] = "company_code = :company_code";
                    $params[':company_code'] = $companyCode;
                }
                if ($periodId !== null) {
                    $whereClauses[] = "period_id = :period_id";
                    $params[':period_id'] = $periodId;
                }

                if (!empty($whereClauses)) {
                    $scopedSql = "SELECT count(*) FROM public.{$tableName} WHERE " . implode(' AND ', $whereClauses);
                    $scopedStmt = $custPdo->prepare($scopedSql);
                    $scopedStmt->execute($params);
                    $scopedCount = (int)($scopedStmt->fetchColumn() ?: 0);
                } else {
                    $scopedCount = $recordCount;
                }

                // Latest timestamp
                $timeStmt = $custPdo->query("SELECT max(created_at) FROM public.{$tableName}");
                $latestRecordAt = $timeStmt->fetchColumn() ?: null;
            } catch (Throwable $e) {
                $recordCount = 0;
                $scopedCount = 0;
            }
        }

        $catalogue[] = [
            'dataset_key'          => $def['dataset_key'],
            'target_table'         => $def['target_table'],
            'primary_key'          => $def['primary_key'],
            'dataset_type'         => $def['dataset_type'],
            'display_name'         => $def['display_name'],
            'category'             => $def['category'],
            'description'          => $def['description'],
            'business_key'         => $def['business_key'],
            'table_exists'         => $tableExists,
            'total_record_count'   => $recordCount,
            'scoped_record_count'  => $scopedCount,
            'latest_record_at'     => $latestRecordAt,
            'allowed_sort_columns' => $def['allowed_sort_columns'],
            'filterable_columns'   => $def['filterable_columns']
        ];
    }

    return [
        'ok'                      => true,
        'summary'                 => [
            'total_datasets'      => count($registry),
            'available_datasets'  => $availableDatasetsCount,
            'total_records_all'   => $totalRecordsAcrossAll,
            'scope'               => [
                'company_code'    => $companyCode,
                'period_id'       => $periodId
            ]
        ],
        'datasets'                => $catalogue
    ];
}

/**
 * Returns an overall summary of upload activities, batch statistics, error totals,
 * and canonical database populations across both platform and customer databases.
 *
 * @param PDO   $globalPdo Platform DB connection
 * @param PDO   $custPdo   Customer DB connection
 * @param array $context   ['customer_id' => int, 'company_code' => string, 'period_id' => int]
 * @return array
 */
function getOverallUploadSummary(PDO $globalPdo, PDO $custPdo, array $context = []): array {
    $customerId = !empty($context['customer_id']) ? (int)$context['customer_id'] : null;
    $companyCode = !empty($context['company_code']) ? trim((string)$context['company_code']) : null;
    $periodId = !empty($context['period_id']) ? (int)$context['period_id'] : null;

    // 1. Query Upload Run Statistics from Global DB
    $uploadWhere = [];
    $uploadParams = [];
    $joinSql = "";

    if ($customerId !== null) {
        $joinSql = "INNER JOIN upload_scopes us ON u.id = us.upload_id";
        $uploadWhere[] = "us.customer_id = :cid";
        $uploadParams[':cid'] = $customerId;
    }
    if ($periodId !== null) {
        $uploadWhere[] = "u.period_id = :pid";
        $uploadParams[':pid'] = $periodId;
    }

    $whereSql = !empty($uploadWhere) ? "WHERE " . implode(' AND ', $uploadWhere) : "";

    $statsStmt = $globalPdo->prepare("
        SELECT 
            count(DISTINCT u.id) AS total_batches,
            coalesce(sum(u.total_rows), 0) AS total_rows_staged,
            coalesce(sum(u.inserted_rows), 0) AS total_rows_inserted,
            coalesce(sum(u.updated_rows), 0) AS total_rows_updated,
            coalesce(sum(u.error_rows), 0) AS total_rows_errored,
            coalesce(sum(CASE WHEN upper(u.status) IN ('COMPLETED', 'SUCCESS') THEN 1 ELSE 0 END), 0) AS completed_batches,
            coalesce(sum(CASE WHEN upper(u.status) LIKE 'COMPLETED_WITH_%' THEN 1 ELSE 0 END), 0) AS warning_batches,
            coalesce(sum(CASE WHEN upper(u.status) IN ('FAILED', 'ERROR') THEN 1 ELSE 0 END), 0) AS failed_batches,
            coalesce(sum(CASE WHEN upper(u.status) IN ('PROCESSING', 'PENDING', 'RUNNING') THEN 1 ELSE 0 END), 0) AS in_progress_batches,
            max(u.created_at) AS last_upload_at
        FROM uploads u
        {$joinSql}
        {$whereSql}
    ");
    $statsStmt->execute($uploadParams);
    $batchStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // 2. Query Total Import Errors Logged
    $errorCount = 0;
    try {
        if ($customerId !== null) {
            $errStmt = $globalPdo->prepare("
                SELECT count(ie.id) 
                FROM import_errors ie
                INNER JOIN upload_scopes us ON ie.upload_id = us.upload_id
                WHERE us.customer_id = :cid
            ");
            $errStmt->execute([':cid' => $customerId]);
            $errorCount = (int)($errStmt->fetchColumn() ?: 0);
        } else {
            $errStmt = $globalPdo->query("SELECT count(*) FROM import_errors");
            $errorCount = (int)($errStmt->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        $errorCount = 0;
    }

    // 3. Query Recent 10 Upload Batches
    $recentStmt = $globalPdo->prepare("
        SELECT 
            u.id,
            u.import_code,
            u.status,
            u.total_rows,
            u.inserted_rows,
            u.updated_rows,
            u.error_rows,
            u.period_id,
            u.created_at,
            u.completed_at
        FROM uploads u
        {$joinSql}
        {$whereSql}
        ORDER BY u.id DESC
        LIMIT 10
    ");
    $recentStmt->execute($uploadParams);
    $recentBatches = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 4. Summarize Customer DB Canonical Table Records
    $catalogueRes = getReportableDatasetsCatalogue($custPdo, $context);
    $datasetBreakdown = [];
    foreach ($catalogueRes['datasets'] as $ds) {
        $datasetBreakdown[] = [
            'dataset_key'   => $ds['dataset_key'],
            'display_name'  => $ds['display_name'],
            'target_table'  => $ds['target_table'],
            'dataset_type'  => $ds['dataset_type'],
            'table_exists'  => $ds['table_exists'],
            'record_count'  => $ds['scoped_record_count'],
            'total_count'   => $ds['total_record_count'],
            'latest_record' => $ds['latest_record_at']
        ];
    }

    return [
        'ok'               => true,
        'batch_statistics' => [
            'total_batches'       => (int)($batchStats['total_batches'] ?? 0),
            'completed_batches'   => (int)($batchStats['completed_batches'] ?? 0),
            'warning_batches'     => (int)($batchStats['warning_batches'] ?? 0),
            'failed_batches'      => (int)($batchStats['failed_batches'] ?? 0),
            'in_progress_batches' => (int)($batchStats['in_progress_batches'] ?? 0),
            'total_rows_staged'   => (int)($batchStats['total_rows_staged'] ?? 0),
            'total_rows_inserted' => (int)($batchStats['total_rows_inserted'] ?? 0),
            'total_rows_updated'  => (int)($batchStats['total_rows_updated'] ?? 0),
            'total_rows_errored'  => (int)($batchStats['total_rows_errored'] ?? 0),
            'total_import_errors' => $errorCount,
            'last_upload_at'      => $batchStats['last_upload_at'] ?? null
        ],
        'customer_records' => [
            'total_records_all'   => $catalogueRes['summary']['total_records_all'],
            'available_tables'    => $catalogueRes['summary']['available_datasets'],
            'dataset_breakdown'   => $datasetBreakdown
        ],
        'recent_batches'   => $recentBatches,
        'scope'            => [
            'customer_id'         => $customerId,
            'company_code'        => $companyCode,
            'period_id'           => $periodId
        ]
    ];
}

/**
 * Computes deep, dataset-specific summary metrics and business aggregations for a specified dataset.
 *
 * @param PDO    $custPdo Dedicated Customer DB connection
 * @param string $datasetKey
 * @param array  $filters ['company_code' => string, 'period_id' => int, ...]
 * @param array  $context Organization context
 * @return array
 */
function getDatasetSummary(PDO $custPdo, string $datasetKey, array $filters = [], array $context = []): array {
    $def = findReportableDataset($datasetKey);
    if (!$def) {
        return [
            'ok'      => false,
            'message' => "Unrecognized or unsupported dataset key: '{$datasetKey}'. Query rejected."
        ];
    }

    $tableName = $def['target_table'];
    if (!checkCustomerTableExists($custPdo, $tableName)) {
        return [
            'ok'           => true,
            'dataset_key'  => $def['dataset_key'],
            'target_table' => $tableName,
            'table_exists' => false,
            'record_count' => 0,
            'metrics'      => [
                'status'  => 'TABLE_NOT_INITIALIZED',
                'message' => "The target table '{$tableName}' does not exist in the customer database."
            ]
        ];
    }

    // Build parameterized WHERE clause based on allowed filterable columns
    $whereParts = [];
    $params = [];
    $paramIdx = 1;

    // Apply company scope
    $companyCode = !empty($filters['company_code']) ? trim((string)$filters['company_code']) : (!empty($context['company_code']) ? trim((string)$context['company_code']) : null);
    if ($companyCode !== null) {
        $whereParts[] = "company_code = :p{$paramIdx}";
        $params[":p{$paramIdx}"] = $companyCode;
        $paramIdx++;
    }

    // Apply period scope
    $periodId = !empty($filters['period_id']) ? (int)$filters['period_id'] : (!empty($context['period_id']) ? (int)$context['period_id'] : null);
    if ($periodId !== null) {
        $whereParts[] = "period_id = :p{$paramIdx}";
        $params[":p{$paramIdx}"] = $periodId;
        $paramIdx++;
    }

    // Apply date range filters if dataset has a date column
    $dateCol = $def['primary_date_column'];
    if ($dateCol) {
        if (!empty($filters['start_date'])) {
            $whereParts[] = "{$dateCol} >= :p{$paramIdx}";
            $params[":p{$paramIdx}"] = trim((string)$filters['start_date']);
            $paramIdx++;
        }
        if (!empty($filters['end_date'])) {
            $whereParts[] = "{$dateCol} <= :p{$paramIdx}";
            $params[":p{$paramIdx}"] = trim((string)$filters['end_date']);
            $paramIdx++;
        }
    }

    // Apply additional filterable columns
    foreach ($def['filterable_columns'] as $col) {
        if ($col === 'company_code' || $col === 'period_id') {
            continue;
        }
        if (isset($filters[$col]) && $filters[$col] !== '' && $filters[$col] !== null) {
            $whereParts[] = "{$col} = :p{$paramIdx}";
            $params[":p{$paramIdx}"] = trim((string)$filters[$col]);
            $paramIdx++;
        }
    }

    $whereSql = !empty($whereParts) ? "WHERE " . implode(' AND ', $whereParts) : "";

    // Base Record Count & Date Range
    $baseSql = "
        SELECT 
            count(*) AS total_records,
            min(created_at) AS earliest_record_at,
            max(created_at) AS latest_record_at
        FROM public.{$tableName}
        {$whereSql}
    ";
    $baseStmt = $custPdo->prepare($baseSql);
    $baseStmt->execute($params);
    $baseMetrics = $baseStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalRecords = (int)($baseMetrics['total_records'] ?? 0);

    // Compute Dataset-Tailored Metrics
    $specificMetrics = [];

    switch ($def['dataset_key']) {
        case '01_ITEM_PRODUCT_MASTER':
            // Breakdown by item_type, standard rates distribution, distinct groups & grades
            $typeStmt = $custPdo->prepare("
                SELECT coalesce(item_type, 'Unspecified') AS type_label, count(*) AS count
                FROM public.items
                {$whereSql}
                GROUP BY coalesce(item_type, 'Unspecified')
                ORDER BY count DESC
            ");
            $typeStmt->execute($params);
            $itemTypes = $typeStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

            $rateStmt = $custPdo->prepare("
                SELECT 
                    count(CASE WHEN std_rate > 0 THEN 1 END) AS positive_rates,
                    count(CASE WHEN std_rate = 0 OR std_rate IS NULL THEN 1 END) AS zero_or_null_rates,
                    count(CASE WHEN std_rate < 0 THEN 1 END) AS negative_rates,
                    coalesce(round(avg(std_rate), 4), 0) AS avg_std_rate,
                    coalesce(max(std_rate), 0) AS max_std_rate,
                    count(DISTINCT item_group_code) AS distinct_groups,
                    count(DISTINCT grade) AS distinct_grades
                FROM public.items
                {$whereSql}
            ");
            $rateStmt->execute($params);
            $specificMetrics = array_merge([
                'item_type_breakdown' => $itemTypes
            ], $rateStmt->fetch(PDO::FETCH_ASSOC) ?: []);
            break;

        case '02_WORK_CENTER_MASTER':
            $wcStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT wc_code) AS distinct_work_centers,
                    count(DISTINCT cc_code) AS distinct_cost_centers,
                    coalesce(round(avg(std_mhr), 4), 0) AS avg_std_mhr,
                    coalesce(round(avg(rated_hrs_day), 4), 0) AS avg_rated_hrs_day,
                    coalesce(round(avg(hourly_rate), 4), 0) AS avg_hourly_rate
                FROM public.work_centers
                {$whereSql}
            ");
            $wcStmt->execute($params);
            $specificMetrics = $wcStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            break;

        case '03_PARTY_MASTER':
            $partyStmt = $custPdo->prepare("
                SELECT coalesce(type, 'Unspecified') AS party_type, count(*) AS count
                FROM public.party_master
                {$whereSql}
                GROUP BY coalesce(type, 'Unspecified')
            ");
            $partyStmt->execute($params);
            $partyTypes = $partyStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

            $stateStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT state) AS distinct_states,
                    coalesce(round(avg(payment_terms_days), 2), 0) AS avg_payment_terms_days
                FROM public.party_master
                {$whereSql}
            ");
            $stateStmt->execute($params);
            $specificMetrics = array_merge([
                'party_type_breakdown' => $partyTypes
            ], $stateStmt->fetch(PDO::FETCH_ASSOC) ?: []);
            break;

        case '04_COST_CENTER_MASTER':
            $ccStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT cc_code) AS distinct_cost_centers,
                    count(CASE WHEN is_production = 1 THEN 1 END) AS production_cost_centers,
                    count(CASE WHEN is_production = 0 OR is_production IS NULL THEN 1 END) AS non_production_cost_centers,
                    coalesce(sum(shift_capacity_hrs_per_month), 0) AS total_monthly_capacity_hrs
                FROM public.cost_center_master
                {$whereSql}
            ");
            $ccStmt->execute($params);
            $specificMetrics = $ccStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            break;

        case '05_STANDARD_COST':
            $scStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT product_code) AS distinct_products,
                    coalesce(round(avg(std_material), 4), 0) AS avg_std_material,
                    coalesce(round(avg(std_conversion), 4), 0) AS avg_std_conversion,
                    coalesce(round(avg(std_total_cost), 4), 0) AS avg_std_total_cost,
                    coalesce(sum(std_total_cost), 0) AS sum_std_total_cost,
                    max(std_revision_date) AS latest_revision_date
                FROM public.standard_cost
                {$whereSql}
            ");
            $scStmt->execute($params);
            $specificMetrics = $scStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            break;

        case '05A_BOM':
            $bomStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT parent_item_code) AS distinct_parent_products,
                    count(DISTINCT item_code) AS distinct_component_items,
                    count(CASE WHEN scrap_item_code IS NOT NULL AND scrap_item_code != '' THEN 1 END) AS relationships_with_scrap,
                    count(CASE WHEN byproduct_item_code IS NOT NULL AND byproduct_item_code != '' THEN 1 END) AS relationships_with_byproduct,
                    coalesce(sum(scrap_qty), 0) AS total_scrap_qty,
                    coalesce(sum(byproduct_qty), 0) AS total_byproduct_qty
                FROM public.bom
                {$whereSql}
            ");
            $bomStmt->execute($params);
            $specificMetrics = $bomStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            break;

        case '05B_ROUTING':
            $rtStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT product_code) AS distinct_products,
                    count(DISTINCT cc_code) AS distinct_cost_centers,
                    count(DISTINCT machine_code) AS distinct_machines,
                    coalesce(round(sum(std_machine_hrs), 4), 0) AS total_std_machine_hrs,
                    coalesce(round(sum(std_labour_hrs), 4), 0) AS total_std_labour_hrs,
                    coalesce(round(sum(std_conversion_cost), 4), 0) AS total_std_conversion_cost
                FROM public.routing
                {$whereSql}
            ");
            $rtStmt->execute($params);
            $specificMetrics = $rtStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            break;

        case '06_MATERIAL_TRANSACTION':
            $docStmt = $custPdo->prepare("
                SELECT coalesce(doc_type, 'Unspecified') AS doc_type, count(*) AS count, coalesce(sum(amount), 0) AS amount
                FROM public.material_transaction
                {$whereSql}
                GROUP BY coalesce(doc_type, 'Unspecified')
                ORDER BY count DESC
            ");
            $docStmt->execute($params);
            $docTypes = $docStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $mtStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT item_code) AS distinct_items,
                    count(DISTINCT party_code) AS distinct_parties,
                    coalesce(round(sum(qty), 4), 0) AS total_qty,
                    coalesce(round(sum(rejected_qty), 4), 0) AS total_rejected_qty,
                    coalesce(round(sum(amount), 4), 0) AS total_amount,
                    count(CASE WHEN qty > 0 THEN 1 END) AS positive_qty_records,
                    count(CASE WHEN qty < 0 THEN 1 END) AS negative_qty_records,
                    min(doc_date) AS earliest_doc_date,
                    max(doc_date) AS latest_doc_date
                FROM public.material_transaction
                {$whereSql}
            ");
            $mtStmt->execute($params);
            $specificMetrics = array_merge([
                'doc_type_breakdown' => $docTypes
            ], $mtStmt->fetch(PDO::FETCH_ASSOC) ?: []);
            break;

        case '07_DOWNTIME':
            $dtStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT machine_code) AS distinct_machines,
                    count(DISTINCT cc_code) AS distinct_cost_centers,
                    coalesce(round(sum(rated_hrs), 4), 0) AS total_rated_hrs,
                    coalesce(round(sum(run_hrs), 4), 0) AS total_run_hrs,
                    coalesce(round(sum(breakdown_hrs), 4), 0) AS total_breakdown_hrs,
                    coalesce(round(sum(idle_hrs), 4), 0) AS total_idle_hrs,
                    coalesce(round(sum(power_units_kwh), 4), 0) AS total_power_units_kwh,
                    min(log_date) AS earliest_log_date,
                    max(log_date) AS latest_log_date
                FROM public.downtime
                {$whereSql}
            ");
            $dtStmt->execute($params);
            $specificMetrics = $dtStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            break;

        case '08_FINANCE_TRANSACTION':
            $ohStmt = $custPdo->prepare("
                SELECT coalesce(oh_behaviour, 'Unspecified') AS behaviour, count(*) AS count, coalesce(sum(amount), 0) AS amount
                FROM public.finance_transaction
                {$whereSql}
                GROUP BY coalesce(oh_behaviour, 'Unspecified')
            ");
            $ohStmt->execute($params);
            $ohBreakdown = $ohStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $ftStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT oh_code) AS distinct_overhead_codes,
                    count(DISTINCT cc_code) AS distinct_cost_centers,
                    coalesce(round(sum(amount), 4), 0) AS total_expense_amount,
                    min(date) AS earliest_voucher_date,
                    max(date) AS latest_voucher_date
                FROM public.finance_transaction
                {$whereSql}
            ");
            $ftStmt->execute($params);
            $specificMetrics = array_merge([
                'behaviour_breakdown' => $ohBreakdown
            ], $ftStmt->fetch(PDO::FETCH_ASSOC) ?: []);
            break;

        case '09_TRIAL_BALANCE':
            $tbStmt = $custPdo->prepare("
                SELECT 
                    count(DISTINCT acc_code) AS distinct_accounts,
                    coalesce(round(sum(transacted_debit), 4), 0) AS total_transacted_debit,
                    coalesce(round(sum(transacted_credit), 4), 0) AS total_transacted_credit,
                    coalesce(round(sum(CASE WHEN upper(trim(coalesce(closing_balance_sign_d_c, ''))) = 'D' THEN closing_balance ELSE 0 END), 4), 0) AS total_closing_debit,
                    coalesce(round(sum(CASE WHEN upper(trim(coalesce(closing_balance_sign_d_c, ''))) = 'C' THEN closing_balance ELSE 0 END), 4), 0) AS total_closing_credit
                FROM public.trial_balance
                {$whereSql}
            ");
            $tbStmt->execute($params);
            $tbData = $tbStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dr = (float)($tbData['total_closing_debit'] ?? 0);
            $cr = (float)($tbData['total_closing_credit'] ?? 0);
            $diff = round(abs($dr - $cr), 4);

            $specificMetrics = array_merge($tbData, [
                'net_closing_difference' => $diff,
                'is_balanced'            => ($diff === 0.0)
            ]);
            break;
    }

    return [
        'ok'            => true,
        'dataset_key'   => $def['dataset_key'],
        'target_table'  => $tableName,
        'display_name'  => $def['display_name'],
        'category'      => $def['category'],
        'dataset_type'  => $def['dataset_type'],
        'table_exists'  => true,
        'total_records' => $totalRecords,
        'date_bounds'   => [
            'earliest_record_at' => $baseMetrics['earliest_record_at'] ?? null,
            'latest_record_at'   => $baseMetrics['latest_record_at'] ?? null
        ],
        'metrics'       => $specificMetrics,
        'applied_scope' => [
            'company_code' => $companyCode,
            'period_id'    => $periodId
        ]
    ];
}

/**
 * Retrieves paginated, sorted, and filtered data rows for a specified reportable dataset.
 *
 * @param PDO    $custPdo Dedicated Customer DB connection
 * @param string $datasetKey
 * @param array  $params  ['page' => int, 'per_page' => int, 'sort_by' => string, 'sort_dir' => string, 'search' => string, ...]
 * @param array  $context
 * @return array
 */
function getDatasetDetails(PDO $custPdo, string $datasetKey, array $params = [], array $context = []): array {
    $def = findReportableDataset($datasetKey);
    if (!$def) {
        return [
            'ok'      => false,
            'message' => "Unrecognized or unsupported dataset key: '{$datasetKey}'. Query rejected."
        ];
    }

    $tableName = $def['target_table'];
    if (!checkCustomerTableExists($custPdo, $tableName)) {
        return [
            'ok'           => true,
            'dataset'      => [
                'dataset_key'  => $def['dataset_key'],
                'target_table' => $tableName,
                'display_name' => $def['display_name'],
                'category'     => $def['category'],
                'dataset_type' => $def['dataset_type']
            ],
            'pagination'   => [
                'total_records' => 0,
                'page'          => 1,
                'current_page'  => 1,
                'per_page'      => 50,
                'total_pages'   => 0,
                'has_prev'      => false,
                'has_next'      => false
            ],
            'columns'      => $def['display_columns'],
            'data'         => []
        ];
    }

    // 1. Pagination Parameters
    $page = max(1, (int)($params['page'] ?? 1));
    $perPage = min(500, max(1, (int)($params['per_page'] ?? 50)));
    $offset = ($page - 1) * $perPage;

    // 2. Strict Sorting Allowlist
    $sortBy = trim((string)($params['sort_by'] ?? ''));
    if (!in_array($sortBy, $def['allowed_sort_columns'], true)) {
        $sortBy = $def['primary_key']; // Safe fallback
    }

    $sortDir = strtoupper(trim((string)($params['sort_dir'] ?? 'ASC')));
    if ($sortDir !== 'DESC') {
        $sortDir = 'ASC';
    }

    // 3. Build Safe Parameterized WHERE Clause
    $whereParts = [];
    $queryParams = [];
    $paramIdx = 1;

    // Company scope
    $companyCode = !empty($params['company_code']) ? trim((string)$params['company_code']) : (!empty($context['company_code']) ? trim((string)$context['company_code']) : null);
    if ($companyCode !== null) {
        $whereParts[] = "company_code = :p{$paramIdx}";
        $queryParams[":p{$paramIdx}"] = $companyCode;
        $paramIdx++;
    }

    // Period scope
    $periodId = !empty($params['period_id']) ? (int)$params['period_id'] : (!empty($context['period_id']) ? (int)$context['period_id'] : null);
    if ($periodId !== null) {
        $whereParts[] = "period_id = :p{$paramIdx}";
        $queryParams[":p{$paramIdx}"] = $periodId;
        $paramIdx++;
    }

    // Date range filters
    $dateCol = $def['primary_date_column'];
    if ($dateCol) {
        if (!empty($params['start_date'])) {
            $whereParts[] = "{$dateCol} >= :p{$paramIdx}";
            $queryParams[":p{$paramIdx}"] = trim((string)$params['start_date']);
            $paramIdx++;
        }
        if (!empty($params['end_date'])) {
            $whereParts[] = "{$dateCol} <= :p{$paramIdx}";
            $queryParams[":p{$paramIdx}"] = trim((string)$params['end_date']);
            $paramIdx++;
        }
    }

    // Generic search query across searchable text columns
    $search = trim((string)($params['search'] ?? ''));
    if ($search !== '' && !empty($def['searchable_columns'])) {
        $searchOrs = [];
        foreach ($def['searchable_columns'] as $sCol) {
            $searchOrs[] = "coalesce({$sCol}::text, '') ILIKE :p{$paramIdx}";
        }
        $whereParts[] = "(" . implode(' OR ', $searchOrs) . ")";
        $queryParams[":p{$paramIdx}"] = '%' . $search . '%';
        $paramIdx++;
    }

    // Allowed column-specific filters
    foreach ($def['filterable_columns'] as $col) {
        if ($col === 'company_code' || $col === 'period_id') {
            continue;
        }
        if (isset($params[$col]) && $params[$col] !== '' && $params[$col] !== null) {
            $whereParts[] = "{$col} = :p{$paramIdx}";
            $queryParams[":p{$paramIdx}"] = trim((string)$params[$col]);
            $paramIdx++;
        }
    }

    $whereSql = !empty($whereParts) ? "WHERE " . implode(' AND ', $whereParts) : "";

    // 4. Query Total Matching Records
    $countSql = "SELECT count(*) FROM public.{$tableName} {$whereSql}";
    $countStmt = $custPdo->prepare($countSql);
    $countStmt->execute($queryParams);
    $totalRecords = (int)($countStmt->fetchColumn() ?: 0);
    $totalPages = (int)ceil($totalRecords / $perPage);

    // 5. Query Paginated Records
    // Project only allowed display columns
    $colList = implode(', ', $def['display_columns']);
    $dataSql = "
        SELECT {$colList} 
        FROM public.{$tableName} 
        {$whereSql} 
        ORDER BY {$sortBy} {$sortDir} 
        LIMIT :q_limit OFFSET :q_offset
    ";

    $dataStmt = $custPdo->prepare($dataSql);
    foreach ($queryParams as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':q_limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':q_offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'ok'              => true,
        'dataset'         => [
            'dataset_key'  => $def['dataset_key'],
            'target_table' => $tableName,
            'display_name' => $def['display_name'],
            'category'     => $def['category'],
            'dataset_type' => $def['dataset_type'],
            'primary_key'  => $def['primary_key']
        ],
        'pagination'      => [
            'total_records' => $totalRecords,
            'page'          => $page,
            'current_page'  => $page,
            'per_page'      => $perPage,
            'total_pages'   => $totalPages,
            'has_prev'      => ($page > 1),
            'has_next'      => ($page < $totalPages)
        ],
        'sort'            => [
            'sort_by'  => $sortBy,
            'sort_dir' => $sortDir
        ],
        'columns'         => $def['display_columns'],
        'numeric_columns' => $def['numeric_columns'],
        'data'            => $rows
    ];
}

/**
 * Streams dataset records matching criteria directly to a CSV output resource.
 *
 * @param PDO           $custPdo
 * @param string        $datasetKey
 * @param array         $params
 * @param array         $context
 * @param resource|null $outputResource Defaults to fopen('php://output', 'w')
 * @return void
 */
function exportDatasetCsv(PDO $custPdo, string $datasetKey, array $params = [], array $context = [], $outputResource = null): void {
    $def = findReportableDataset($datasetKey);
    if (!$def) {
        throw new InvalidArgumentException("Unrecognized dataset key: '{$datasetKey}'");
    }

    $tableName = $def['target_table'];
    if (!checkCustomerTableExists($custPdo, $tableName)) {
        throw new RuntimeException("Target table '{$tableName}' does not exist in customer database.");
    }

    // Build parameterized WHERE clause
    $whereParts = [];
    $queryParams = [];
    $paramIdx = 1;

    $companyCode = !empty($params['company_code']) ? trim((string)$params['company_code']) : (!empty($context['company_code']) ? trim((string)$context['company_code']) : null);
    if ($companyCode !== null) {
        $whereParts[] = "company_code = :p{$paramIdx}";
        $queryParams[":p{$paramIdx}"] = $companyCode;
        $paramIdx++;
    }

    $periodId = !empty($params['period_id']) ? (int)$params['period_id'] : (!empty($context['period_id']) ? (int)$context['period_id'] : null);
    if ($periodId !== null) {
        $whereParts[] = "period_id = :p{$paramIdx}";
        $queryParams[":p{$paramIdx}"] = $periodId;
        $paramIdx++;
    }

    $dateCol = $def['primary_date_column'];
    if ($dateCol) {
        if (!empty($params['start_date'])) {
            $whereParts[] = "{$dateCol} >= :p{$paramIdx}";
            $queryParams[":p{$paramIdx}"] = trim((string)$params['start_date']);
            $paramIdx++;
        }
        if (!empty($params['end_date'])) {
            $whereParts[] = "{$dateCol} <= :p{$paramIdx}";
            $queryParams[":p{$paramIdx}"] = trim((string)$params['end_date']);
            $paramIdx++;
        }
    }

    $search = trim((string)($params['search'] ?? ''));
    if ($search !== '' && !empty($def['searchable_columns'])) {
        $searchOrs = [];
        foreach ($def['searchable_columns'] as $sCol) {
            $searchOrs[] = "coalesce({$sCol}::text, '') ILIKE :p{$paramIdx}";
        }
        $whereParts[] = "(" . implode(' OR ', $searchOrs) . ")";
        $queryParams[":p{$paramIdx}"] = '%' . $search . '%';
        $paramIdx++;
    }

    foreach ($def['filterable_columns'] as $col) {
        if ($col === 'company_code' || $col === 'period_id') {
            continue;
        }
        if (isset($params[$col]) && $params[$col] !== '' && $params[$col] !== null) {
            $whereParts[] = "{$col} = :p{$paramIdx}";
            $queryParams[":p{$paramIdx}"] = trim((string)$params[$col]);
            $paramIdx++;
        }
    }

    $whereSql = !empty($whereParts) ? "WHERE " . implode(' AND ', $whereParts) : "";

    $sortBy = trim((string)($params['sort_by'] ?? ''));
    if (!in_array($sortBy, $def['allowed_sort_columns'], true)) {
        $sortBy = $def['primary_key'];
    }

    $sortDir = strtoupper(trim((string)($params['sort_dir'] ?? 'ASC')));
    if ($sortDir !== 'DESC') {
        $sortDir = 'ASC';
    }

    $colList = implode(', ', $def['display_columns']);
    $dataSql = "SELECT {$colList} FROM public.{$tableName} {$whereSql} ORDER BY {$sortBy} {$sortDir}";

    $stmt = $custPdo->prepare($dataSql);
    $stmt->execute($queryParams);

    $out = $outputResource ?: fopen('php://output', 'w');

    // UTF-8 BOM for Microsoft Excel compatibility
    fwrite($out, "\xEF\xBB\xBF");

    // Write header line with human-readable column titles
    $headerRow = array_map(function($col) {
        return ucwords(str_replace('_', ' ', $col));
    }, $def['display_columns']);
    fputcsv($out, $headerRow);

    // Stream rows
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, $row);
    }

    if (!$outputResource) {
        fclose($out);
    }
}
