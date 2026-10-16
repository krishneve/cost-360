<?php
/**
 * Cost360 - Financial Reconciliation Engine (Phase 3)
 *
 * Implements Trial Balance reconciliation, Account & Period integrity validation,
 * and Inventory-to-General-Ledger relationship inspection according to CMA principles.
 *
 * Architecture Principles:
 *   - Strictly operates on the authenticated tenant's dedicated PostgreSQL database.
 *   - Read-only execution: NEVER modifies, posts, or forces financial records to balance.
 *   - Explicit, configurable tolerance (no silent arbitrary rounding).
 *   - Explicitly identifies and reports the schema gap for item-to-GL account mappings.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/upload_centre.php';

/**
 * Reconciles the Trial Balance for a customer database, company, and accounting period.
 *
 * @param PDO   $custPdo Dedicated Customer Database PDO instance
 * @param array $options [
 *     'company_code' => string,
 *     'period_id'    => int|null,
 *     'tolerance'    => float (default 0.00),
 *     'user'         => array|null
 * ]
 * @return array Structured reconciliation results with double-entry totals, status, and integrity findings.
 */
function reconcileTrialBalance(PDO $custPdo, array $options = []): array {
    $tolerance = isset($options['tolerance']) ? (float)$options['tolerance'] : 0.00;
    if ($tolerance < 0) {
        $tolerance = 0.00;
    }

    $companyCode = trim((string)($options['company_code'] ?? ''));
    if ($companyCode === '') {
        $orgContext = getActiveOrganizationContext();
        $companyCode = trim((string)($orgContext['company_code'] ?? ''));
    }

    $periodId = isset($options['period_id']) && $options['period_id'] !== '' && $options['period_id'] !== null ? (int)$options['period_id'] : null;
    $periodName = '';
    $user = $options['user'] ?? null;

    // Resolve period name if periodId is given or from context
    if ($periodId !== null) {
        $globalPdo = getDbConnection();
        $pStmt = $globalPdo->prepare("SELECT period_name, period_code FROM public.periods WHERE period_id = ? LIMIT 1");
        $pStmt->execute([$periodId]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $periodName = $pRow['period_name'] ?? ($pRow['period_code'] ?? ("Period " . $periodId));
        }
    } else {
        $authPeriod = getAuthoritativeUploadPeriod(null, $user);
        if ($authPeriod['ok'] && !empty($authPeriod['period'])) {
            $periodId = (int)$authPeriod['period']['period_id'];
            $periodName = $authPeriod['period']['period_name'] ?? ("Period " . $periodId);
        }
    }

    // Ensure table exists in customer DB
    ensureTargetTableExistsInCustomerDb($custPdo, 'trial_balance');

    // Build scoped query
    $whereClauses = [];
    $params = [];

    if ($companyCode !== '') {
        $whereClauses[] = "LOWER(company_code) = LOWER(?)";
        $params[] = $companyCode;
    }

    if ($periodId !== null) {
        $whereClauses[] = "period_id = ?";
        $params[] = $periodId;
    }

    $whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";
    $sql = "
        SELECT id, customer_code, company_code, division_code, location_code,
               acc_code, description,
               opening_balance, opening_balance_sign_d_c, opening_debit_opening_credit,
               transacted_debit, transacted_credit,
               closing_balance, closing_balance_sign_d_c, closing_debit_closing_credit,
               period_id, period_code, status
        FROM public.trial_balance
        {$whereSql}
        ORDER BY acc_code ASC, id ASC
    ";

    $stmt = $custPdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Empty dataset handling
    if (empty($rows)) {
        $scopeDesc = ($companyCode !== '' ? "Company '{$companyCode}'" : "All Companies") .
                     ($periodId !== null ? " and Period '" . ($periodName ?: $periodId) . "'" : "");
        return [
            'ok'             => true,
            'status'         => 'Unable to Reconcile',
            'status_code'    => 'EMPTY_DATASET',
            'message'        => "Unable to Reconcile: No Trial Balance records found in customer database for {$scopeDesc}.",
            'company_code'   => $companyCode,
            'period_id'      => $periodId,
            'period_name'    => $periodName,
            'tolerance'      => $tolerance,
            'record_count'   => 0,
            'closing'        => ['debit' => 0.0, 'credit' => 0.0, 'difference' => 0.0, 'is_balanced' => false],
            'transacted'     => ['debit' => 0.0, 'credit' => 0.0, 'difference' => 0.0, 'is_balanced' => false],
            'opening'        => ['debit' => 0.0, 'credit' => 0.0, 'difference' => 0.0, 'is_balanced' => false],
            'integrity'      => [
                'missing_accounts'   => [],
                'duplicates'         => [],
                'invalid_signs'      => [],
                'flow_discrepancies' => []
            ],
            'accounts_sample' => [],
            'explanations'   => [
                "No Trial Balance records have been uploaded or imported for this company and accounting period.",
                "Upload and approve the '09.TRIAL BALANCE' worksheet in the Upload Centre before performing reconciliation."
            ],
            'read_only_guarantee' => true
        ];
    }

    // Accumulators
    $totalClosingDebit = 0.0;
    $totalClosingCredit = 0.0;
    $totalTransactedDebit = 0.0;
    $totalTransactedCredit = 0.0;
    $totalOpeningDebit = 0.0;
    $totalOpeningCredit = 0.0;

    $seenAccounts = [];
    $duplicates = [];
    $missingAccounts = [];
    $invalidSigns = [];
    $flowDiscrepancies = [];
    $accountSummaries = [];

    foreach ($rows as $rowIdx => $r) {
        $rowNum = $rowIdx + 1;
        $accCode = trim((string)($r['acc_code'] ?? ''));
        $desc = trim((string)($r['description'] ?? ''));

        // 1. Account Identifier Integrity
        if ($accCode === '') {
            $missingAccounts[] = [
                'row_index'   => $rowNum,
                'record_id'   => (int)$r['id'],
                'description' => $desc,
                'reason'      => "Record ID {$r['id']} has an empty or missing account code (acc_code)."
            ];
        } else {
            if (isset($seenAccounts[$accCode])) {
                $duplicates[] = [
                    'acc_code'     => $accCode,
                    'record_id'    => (int)$r['id'],
                    'first_row'    => $seenAccounts[$accCode]['row_index'],
                    'description'  => $desc,
                    'reason'       => "Duplicate entry: Account code '{$accCode}' appears multiple times in the same company and period."
                ];
            } else {
                $seenAccounts[$accCode] = [
                    'row_index'   => $rowNum,
                    'record_id'   => (int)$r['id'],
                    'description' => $desc
                ];
            }
        }

        // 2. Closing Balance Debit & Credit Evaluation
        $cBal = (float)($r['closing_balance'] ?? 0.0);
        $cSignRaw = trim((string)($r['closing_balance_sign_d_c'] ?? ''));
        $cSign = strtoupper($cSignRaw);
        $cNet = isset($r['closing_debit_closing_credit']) && $r['closing_debit_closing_credit'] !== null ? (float)$r['closing_debit_closing_credit'] : null;

        $cDebit = 0.0;
        $cCredit = 0.0;

        if ($cSign === 'D' || $cSign === 'DR') {
            $cDebit = abs($cBal);
            $cCredit = 0.0;
        } elseif ($cSign === 'C' || $cSign === 'CR') {
            $cDebit = 0.0;
            $cCredit = abs($cBal);
        } elseif ($cSign === '') {
            // Sign column not provided: evaluate closing_debit_closing_credit or signed closing_balance
            if ($cNet !== null && abs($cNet) > 0.00001) {
                if ($cNet > 0) {
                    $cDebit = abs($cNet);
                    $cCredit = 0.0;
                } else {
                    $cDebit = 0.0;
                    $cCredit = abs($cNet);
                }
            } elseif ($cBal < 0) {
                $cDebit = 0.0;
                $cCredit = abs($cBal);
            } else {
                $cDebit = abs($cBal);
                $cCredit = 0.0;
            }
        } else {
            // Malformed sign value
            $invalidSigns[] = [
                'acc_code' => $accCode ?: "(Row {$rowNum})",
                'field'    => 'closing_balance_sign_d_c',
                'value'    => $cSignRaw,
                'reason'   => "Invalid closing balance sign '{$cSignRaw}'. Expected 'D' (Debit) or 'C' (Credit)."
            ];
            $cDebit = abs($cBal);
            $cCredit = 0.0;
        }

        $totalClosingDebit += $cDebit;
        $totalClosingCredit += $cCredit;

        // 3. Transacted Movements Evaluation
        $tDebit = (float)($r['transacted_debit'] ?? 0.0);
        $tCredit = (float)($r['transacted_credit'] ?? 0.0);
        $totalTransactedDebit += $tDebit;
        $totalTransactedCredit += $tCredit;

        // 4. Opening Balance Debit & Credit Evaluation
        $oBal = (float)($r['opening_balance'] ?? 0.0);
        $oSignRaw = trim((string)($r['opening_balance_sign_d_c'] ?? ''));
        $oSign = strtoupper($oSignRaw);
        $oNet = isset($r['opening_debit_opening_credit']) && $r['opening_debit_opening_credit'] !== null ? (float)$r['opening_debit_opening_credit'] : null;

        $oDebit = 0.0;
        $oCredit = 0.0;

        if ($oSign === 'D' || $oSign === 'DR') {
            $oDebit = abs($oBal);
            $oCredit = 0.0;
        } elseif ($oSign === 'C' || $oSign === 'CR') {
            $oDebit = 0.0;
            $oCredit = abs($oBal);
        } elseif ($oSign === '') {
            if ($oNet !== null && abs($oNet) > 0.00001) {
                if ($oNet > 0) {
                    $oDebit = abs($oNet);
                    $oCredit = 0.0;
                } else {
                    $oDebit = 0.0;
                    $oCredit = abs($oNet);
                }
            } elseif ($oBal < 0) {
                $oDebit = 0.0;
                $oCredit = abs($oBal);
            } else {
                $oDebit = abs($oBal);
                $oCredit = 0.0;
            }
        } else {
            $invalidSigns[] = [
                'acc_code' => $accCode ?: "(Row {$rowNum})",
                'field'    => 'opening_balance_sign_d_c',
                'value'    => $oSignRaw,
                'reason'   => "Invalid opening balance sign '{$oSignRaw}'. Expected 'D' (Debit) or 'C' (Credit)."
            ];
            $oDebit = abs($oBal);
            $oCredit = 0.0;
        }

        $totalOpeningDebit += $oDebit;
        $totalOpeningCredit += $oCredit;

        // 5. Account Flow Progression Identity:
        // Expected Closing Net = Opening Net + Transacted Debit - Transacted Credit
        $hasOpening = (abs($oDebit) > 0.0001 || abs($oCredit) > 0.0001);
        $hasMovements = (abs($tDebit) > 0.0001 || abs($tCredit) > 0.0001);
        $hasClosing = (abs($cDebit) > 0.0001 || abs($cCredit) > 0.0001);

        if ($hasOpening && ($hasMovements || $hasClosing)) {
            $openingNet = $oDebit - $oCredit;
            $movementNet = $tDebit - $tCredit;
            $expectedClosingNet = $openingNet + $movementNet;
            $actualClosingNet = $cDebit - $cCredit;
            $flowVariance = round(abs($actualClosingNet - $expectedClosingNet), 4);

            if ($flowVariance > $tolerance && $flowVariance > 0.01) {
                $flowDiscrepancies[] = [
                    'acc_code'             => $accCode ?: "(Row {$rowNum})",
                    'description'          => $desc,
                    'opening_net'          => round($openingNet, 4),
                    'transacted_movement'  => round($movementNet, 4),
                    'expected_closing_net' => round($expectedClosingNet, 4),
                    'actual_closing_net'   => round($actualClosingNet, 4),
                    'variance'             => $flowVariance,
                    'reason'               => "Account '{$accCode}' closing balance differs from [Opening + Net Movements] by " . number_format($flowVariance, 4)
                ];
            }
        }

        if (count($accountSummaries) < 50) {
            $accountSummaries[] = [
                'acc_code'       => $accCode,
                'description'    => $desc,
                'opening_debit'  => round($oDebit, 4),
                'opening_credit' => round($oCredit, 4),
                'trans_debit'    => round($tDebit, 4),
                'trans_credit'   => round($tCredit, 4),
                'closing_debit'  => round($cDebit, 4),
                'closing_credit' => round($cCredit, 4),
            ];
        }
    }

    // Differences & Precision
    $totalClosingDebit = round($totalClosingDebit, 4);
    $totalClosingCredit = round($totalClosingCredit, 4);
    $closingDiff = round(abs($totalClosingDebit - $totalClosingCredit), 4);
    $isClosingBalanced = ($closingDiff <= $tolerance);

    $totalTransactedDebit = round($totalTransactedDebit, 4);
    $totalTransactedCredit = round($totalTransactedCredit, 4);
    $transactedDiff = round(abs($totalTransactedDebit - $totalTransactedCredit), 4);
    $isTransactedBalanced = ($transactedDiff <= $tolerance);

    $totalOpeningDebit = round($totalOpeningDebit, 4);
    $totalOpeningCredit = round($totalOpeningCredit, 4);
    $openingDiff = round(abs($totalOpeningDebit - $totalOpeningCredit), 4);
    $isOpeningBalanced = ($openingDiff <= $tolerance);

    // Status Determination
    $status = 'Balanced';
    $statusCode = 'BALANCED';
    $explanations = [];

    if (!empty($missingAccounts)) {
        $status = 'Unable to Reconcile';
        $statusCode = 'MISSING_ACCOUNT_DATA';
        $explanations[] = count($missingAccounts) . " Trial Balance record(s) lack required account codes (acc_code). Account codes are essential for financial reconciliation.";
    } elseif (!empty($duplicates)) {
        // Duplicates represent structural integrity defects
        $explanations[] = "Notice: " . count($duplicates) . " duplicate account entry/entries detected in this period.";
    }

    if ($status !== 'Unable to Reconcile') {
        if ($isClosingBalanced) {
            $status = 'Balanced';
            $statusCode = 'BALANCED';
            $explanations[] = "Closing Trial Balance is perfectly balanced. Total Debits (" . number_format($totalClosingDebit, 4) . ") match Total Credits (" . number_format($totalClosingCredit, 4) . ") within configured tolerance of " . number_format($tolerance, 4) . ".";
        } else {
            $status = 'Unbalanced';
            $statusCode = 'UNBALANCED';
            $explanations[] = "Closing Trial Balance is UNBALANCED. Total Debits (" . number_format($totalClosingDebit, 4) . ") exceed/trail Total Credits (" . number_format($totalClosingCredit, 4) . ") by a net variance of " . number_format($closingDiff, 4) . ".";
        }
    }

    if (!$isTransactedBalanced && ($totalTransactedDebit > 0 || $totalTransactedCredit > 0)) {
        $explanations[] = "Transaction movements variance: Period debits (" . number_format($totalTransactedDebit, 4) . ") differ from period credits (" . number_format($totalTransactedCredit, 4) . ") by " . number_format($transactedDiff, 4) . ".";
    }

    if (!empty($flowDiscrepancies)) {
        $explanations[] = count($flowDiscrepancies) . " account(s) show discrepancies between Opening Balance + Movements vs. Reported Closing Balance.";
    }

    // Log reconciliation run to platform audit trail
    if ($user) {
        $uId = (int)($user['user_id'] ?? 1);
        $uName = $user['username'] ?? 'CMA Auditor';
        logUploadAudit($uId, $uName, 'TRIAL_BALANCE_RECONCILED', 'Financial Reconciliation', null, ($status === 'Balanced' ? 'Success' : 'Warning'), [
            'company_code'   => $companyCode,
            'period_id'      => $periodId,
            'status'         => $status,
            'tolerance'      => $tolerance,
            'closing_debit'  => $totalClosingDebit,
            'closing_credit' => $totalClosingCredit,
            'difference'     => $closingDiff,
            'records'        => count($rows)
        ]);
    }

    return [
        'ok'             => true,
        'status'         => $status,
        'status_code'    => $statusCode,
        'message'        => "Trial Balance Reconciliation: {$status}." . ($closingDiff > 0 ? " Variance: " . number_format($closingDiff, 4) : ""),
        'company_code'   => $companyCode,
        'period_id'      => $periodId,
        'period_name'    => $periodName,
        'tolerance'      => $tolerance,
        'record_count'   => count($rows),
        'closing'        => [
            'debit'        => $totalClosingDebit,
            'credit'       => $totalClosingCredit,
            'difference'   => $closingDiff,
            'is_balanced'  => $isClosingBalanced
        ],
        'transacted'     => [
            'debit'        => $totalTransactedDebit,
            'credit'       => $totalTransactedCredit,
            'difference'   => $transactedDiff,
            'is_balanced'  => $isTransactedBalanced
        ],
        'opening'        => [
            'debit'        => $totalOpeningDebit,
            'credit'       => $totalOpeningCredit,
            'difference'   => $openingDiff,
            'is_balanced'  => $isOpeningBalanced
        ],
        'integrity'      => [
            'missing_accounts'   => $missingAccounts,
            'duplicates'         => $duplicates,
            'invalid_signs'      => $invalidSigns,
            'flow_discrepancies' => $flowDiscrepancies
        ],
        'accounts_sample' => $accountSummaries,
        'explanations'   => $explanations,
        'read_only_guarantee' => true
    ];
}

/**
 * Inspects Inventory-to-General-Ledger valuation and reports schema gaps & reconciliation status.
 *
 * Compares period material transaction flows (Purchase, Consumption, Production, Issue, Scrap Sales)
 * against candidate Inventory General Ledger control accounts in the Trial Balance.
 *
 * @param PDO   $custPdo Dedicated Customer Database PDO instance
 * @param array $options [
 *     'company_code' => string,
 *     'period_id'    => int|null,
 *     'tolerance'    => float,
 *     'user'         => array|null
 * ]
 * @return array Inventory-to-GL valuation inspection and architectural gap analysis.
 */
function reconcileInventoryToGeneralLedger(PDO $custPdo, array $options = []): array {
    $companyCode = trim((string)($options['company_code'] ?? ''));
    if ($companyCode === '') {
        $orgContext = getActiveOrganizationContext();
        $companyCode = trim((string)($orgContext['company_code'] ?? ''));
    }

    $periodId = isset($options['period_id']) && $options['period_id'] !== '' && $options['period_id'] !== null ? (int)$options['period_id'] : null;
    $periodName = '';

    if ($periodId !== null) {
        $globalPdo = getDbConnection();
        $pStmt = $globalPdo->prepare("SELECT period_name, period_code FROM public.periods WHERE period_id = ? LIMIT 1");
        $pStmt->execute([$periodId]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $periodName = $pRow['period_name'] ?? ($pRow['period_code'] ?? ("Period " . $periodId));
        }
    }

    // Ensure canonical tables exist
    ensureTargetTableExistsInCustomerDb($custPdo, 'material_transaction');
    ensureTargetTableExistsInCustomerDb($custPdo, 'trial_balance');

    // 1. Aggregate Material Transactions by Transaction Type for the scoped period
    $mtWhere = [];
    $mtParams = [];

    if ($companyCode !== '') {
        $mtWhere[] = "LOWER(company_code) = LOWER(?)";
        $mtParams[] = $companyCode;
    }
    if ($periodId !== null) {
        $mtWhere[] = "period_id = ?";
        $mtParams[] = $periodId;
    }
    $mtWhereSql = !empty($mtWhere) ? "WHERE " . implode(' AND ', $mtWhere) : "";

    $stmtMt = $custPdo->prepare("
        SELECT 
            COALESCE(NULLIF(transaction_type, ''), NULLIF(doc_type, ''), 'Unspecified') as txn_type,
            COUNT(*) as record_count,
            COALESCE(SUM(qty), 0) as total_qty,
            COALESCE(SUM(amount), 0) as total_amount
        FROM public.material_transaction
        {$mtWhereSql}
        GROUP BY COALESCE(NULLIF(transaction_type, ''), NULLIF(doc_type, ''), 'Unspecified')
        ORDER BY txn_type ASC
    ");
    $stmtMt->execute($mtParams);
    $mtRows = $stmtMt->fetchAll(PDO::FETCH_ASSOC);

    $materialSummaryByType = [];
    $totalPurchaseAmount = 0.0;
    $totalConsumptionAmount = 0.0;
    $totalProductionAmount = 0.0;
    $totalIssueAmount = 0.0;
    $totalScrapSalesAmount = 0.0;
    $totalMaterialAmount = 0.0;
    $totalMaterialQty = 0.0;
    $totalMaterialRecords = 0;

    foreach ($mtRows as $m) {
        $type = strtoupper(trim((string)$m['txn_type']));
        $amt = round((float)$m['total_amount'], 4);
        $qty = round((float)$m['total_qty'], 4);
        $cnt = (int)$m['record_count'];

        $materialSummaryByType[$type] = [
            'type'   => $m['txn_type'],
            'count'  => $cnt,
            'qty'    => $qty,
            'amount' => $amt
        ];

        $totalMaterialRecords += $cnt;
        $totalMaterialQty += $qty;
        $totalMaterialAmount += $amt;

        if (strpos($type, 'PURCHASE') !== false || strpos($type, 'INWARD') !== false || strpos($type, 'RECEIPT') !== false) {
            $totalPurchaseAmount += $amt;
        } elseif (strpos($type, 'CONSUMPTION') !== false) {
            $totalConsumptionAmount += $amt;
        } elseif (strpos($type, 'PRODUCTION') !== false) {
            $totalProductionAmount += $amt;
        } elseif (strpos($type, 'ISSUE') !== false) {
            $totalIssueAmount += $amt;
        } elseif (strpos($type, 'SCRAP') !== false) {
            $totalScrapSalesAmount += $amt;
        }
    }

    // 2. Identify candidate Inventory Control Accounts in Trial Balance
    $tbWhere = [];
    $tbParams = [];
    if ($companyCode !== '') {
        $tbWhere[] = "LOWER(company_code) = LOWER(?)";
        $tbParams[] = $companyCode;
    }
    if ($periodId !== null) {
        $tbWhere[] = "period_id = ?";
        $tbParams[] = $periodId;
    }
    $tbWhere[] = "(
        description ILIKE '%inventory%' OR 
        description ILIKE '%stock%' OR 
        description ILIKE '%raw material%' OR 
        description ILIKE '%finished good%' OR 
        description ILIKE '%wip%' OR 
        description ILIKE '%work in progress%' OR
        description ILIKE '%stores%'
    )";

    $tbWhereSql = "WHERE " . implode(' AND ', $tbWhere);
    $stmtTb = $custPdo->prepare("
        SELECT id, acc_code, description, 
               opening_balance, opening_balance_sign_d_c,
               transacted_debit, transacted_credit,
               closing_balance, closing_balance_sign_d_c
        FROM public.trial_balance
        {$tbWhereSql}
        ORDER BY acc_code ASC
    ");
    $stmtTb->execute($tbParams);
    $candidateAccounts = $stmtTb->fetchAll(PDO::FETCH_ASSOC);

    $totalCandidateOpening = 0.0;
    $totalCandidateTransDebit = 0.0;
    $totalCandidateTransCredit = 0.0;
    $totalCandidateClosing = 0.0;

    foreach ($candidateAccounts as &$ca) {
        $cSign = strtoupper(trim((string)($ca['closing_balance_sign_d_c'] ?? 'D')));
        $caBal = (float)($ca['closing_balance'] ?? 0.0);
        $signedClosing = ($cSign === 'C' || $cSign === 'CR') ? -$caBal : $caBal;

        $totalCandidateClosing += $signedClosing;
        $totalCandidateTransDebit += (float)($ca['transacted_debit'] ?? 0.0);
        $totalCandidateTransCredit += (float)($ca['transacted_credit'] ?? 0.0);
        $ca['signed_closing'] = round($signedClosing, 4);
    }
    unset($ca);

    // 3. Clear CMA Architectural Assessment and Gap Identification
    $gapExplanation = "The current canonical schema does not define an explicit mapping table linking individual item types or item codes to Trial Balance GL control accounts. Automated item-level inventory valuation reconciliation cannot be inferred without user-approved account mappings.";

    return [
        'ok'                      => true,
        'company_code'            => $companyCode,
        'period_id'               => $periodId,
        'period_name'             => $periodName,
        'mapping_status'          => 'UNMAPPED_ARCHITECTURAL_GAP',
        'gap_identified'          => true,
        'gap_report'              => $gapExplanation,
        'material_transactions'   => [
            'total_records'          => $totalMaterialRecords,
            'total_qty'              => round($totalMaterialQty, 4),
            'total_amount'           => round($totalMaterialAmount, 4),
            'purchase_amount'        => round($totalPurchaseAmount, 4),
            'consumption_amount'     => round($totalConsumptionAmount, 4),
            'production_amount'      => round($totalProductionAmount, 4),
            'issue_amount'           => round($totalIssueAmount, 4),
            'scrap_sales_amount'     => round($totalScrapSalesAmount, 4),
            'by_transaction_type'    => $materialSummaryByType
        ],
        'candidate_gl_inventory'  => [
            'accounts_count'         => count($candidateAccounts),
            'candidate_accounts'     => $candidateAccounts,
            'total_closing_net'      => round($totalCandidateClosing, 4),
            'total_transacted_debit' => round($totalCandidateTransDebit, 4),
            'total_transacted_credit'=> round($totalCandidateTransCredit, 4)
        ],
        'recommendation'          => "To enable automated 1-to-1 inventory valuation reconciliation against the General Ledger, introduce a persistent Inventory Control Account Mapping module in Phase 4 where CMAs can bind item categories (Raw Material, WIP, Finished Goods) to specific General Ledger account codes."
    ];
}

/**
 * Returns a complete Financial Reconciliation overview (TB double-entry & Inventory-to-GL assessment).
 */
function getFinancialReconciliationOverview(PDO $custPdo, array $options = []): array {
    $tbRes = reconcileTrialBalance($custPdo, $options);
    $invRes = reconcileInventoryToGeneralLedger($custPdo, $options);

    return [
        'ok'             => true,
        'company_code'   => $tbRes['company_code'],
        'period_id'      => $tbRes['period_id'],
        'period_name'    => $tbRes['period_name'],
        'tolerance'      => $tbRes['tolerance'],
        'trial_balance'  => $tbRes,
        'inventory_gl'   => $invRes,
        'cma_summary'    => [
            'overall_tb_status'     => $tbRes['status'],
            'tb_variance'           => $tbRes['closing']['difference'] ?? 0.0,
            'material_txn_records'  => $invRes['material_transactions']['total_records'] ?? 0,
            'material_net_movement' => $invRes['material_transactions']['total_amount'] ?? 0.0,
            'inventory_gap_status'  => $invRes['mapping_status']
        ]
    ];
}
