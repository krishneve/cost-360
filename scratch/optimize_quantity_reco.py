with open('config/quantity_reco.php', 'r', encoding='utf-8') as f:
    code = f.read()

# 1. Replace saveQuantityRecoSourceTransactions
old_save = """function saveQuantityRecoSourceTransactions(PDO $pdo, int $plantId, int $periodId, array $records, ?int $userId = null): int {
    if ($plantId <= 0 || $periodId <= 0 || empty($records)) {
        return 0;
    }

    // Resolve period code if available
    $pStmt = $pdo->prepare("SELECT period_name FROM public.periods WHERE period_id = ? LIMIT 1");
    $pStmt->execute([$periodId]);
    $periodCode = $pStmt->fetchColumn() ?: "P{$periodId}";

    $stmt = $pdo->prepare("
        INSERT INTO public.quantity_reco_source_transactions (
            plant_id, period_id, period_code, source,
            account_code, item_code, description, amount,
            cost_basis, pnl_classification, nature, source_file, source_row_ref, is_active, created_by
        ) VALUES (
            :plant_id, :period_id, :period_code, :source,
            :account_code, :item_code, :description, :amount,
            :cost_basis, :pnl, :nature, :source_file, :source_row_ref, TRUE, :created_by
        )
    ");

    $count = 0;
    foreach ($records as $index => $row) {
        $desc = trim((string)($row['description'] ?? ''));
        $costBasis = trim((string)($row['cost_basis'] ?? ''));
        $amount = (float)($row['amount'] ?? 0.0);
        $source = trim((string)($row['source'] ?? 'TB')) ?: 'TB';
        $accCode = trim((string)($row['account_code'] ?? $row['acode'] ?? ''));
        $itemCode = trim((string)($row['item_code'] ?? ''));
        $pnl = trim((string)($row['pnl_classification'] ?? $row['pnl'] ?? 'Rev from Operations')) ?: 'Rev from Operations';
        $nature = trim((string)($row['nature'] ?? 'Variable')) ?: 'Variable';
        $sourceFile = trim((string)($row['source_file'] ?? 'Manual / TB Feed'));
        $rowRef = trim((string)($row['source_row_ref'] ?? ("ROW-" . ($index + 1))));

        if ($desc === '' && $costBasis === '' && $amount == 0.0) {
            continue;
        }

        $stmt->execute([
            ':plant_id' => $plantId,
            ':period_id' => $periodId,
            ':period_code' => $periodCode,
            ':source' => $source,
            ':account_code' => $accCode,
            ':item_code' => $itemCode,
            ':description' => $desc,
            ':amount' => $amount,
            ':cost_basis' => $costBasis,
            ':pnl' => $pnl,
            ':nature' => $nature,
            ':source_file' => $sourceFile,
            ':source_row_ref' => $rowRef,
            ':created_by' => $userId
        ]);
        $count++;
    }

    return $count;
}"""

new_save = """function saveQuantityRecoSourceTransactions(PDO $pdo, int $plantId, int $periodId, array $records, ?int $userId = null): int {
    if ($plantId <= 0 || $periodId <= 0 || empty($records)) {
        return 0;
    }

    // Resolve period code if available
    $pStmt = $pdo->prepare("SELECT period_name FROM public.periods WHERE period_id = ? LIMIT 1");
    $pStmt->execute([$periodId]);
    $periodCode = $pStmt->fetchColumn() ?: "P{$periodId}";

    $validRows = [];
    foreach ($records as $index => $row) {
        $desc = trim((string)($row['description'] ?? ''));
        $costBasis = trim((string)($row['cost_basis'] ?? ''));
        $amount = (float)($row['amount'] ?? 0.0);
        $source = trim((string)($row['source'] ?? 'TB')) ?: 'TB';
        $accCode = trim((string)($row['account_code'] ?? $row['acode'] ?? ''));
        $itemCode = trim((string)($row['item_code'] ?? ''));
        $pnl = trim((string)($row['pnl_classification'] ?? $row['pnl'] ?? 'Rev from Operations')) ?: 'Rev from Operations';
        $nature = trim((string)($row['nature'] ?? 'Variable')) ?: 'Variable';
        $sourceFile = trim((string)($row['source_file'] ?? 'Manual / TB Feed'));
        $rowRef = trim((string)($row['source_row_ref'] ?? ("ROW-" . ($index + 1))));

        if ($desc === '' && $costBasis === '' && $amount == 0.0) {
            continue;
        }

        $validRows[] = [
            'plant_id' => $plantId,
            'period_id' => $periodId,
            'period_code' => $periodCode,
            'source' => $source,
            'account_code' => $accCode,
            'item_code' => $itemCode,
            'description' => $desc,
            'amount' => $amount,
            'cost_basis' => $costBasis,
            'pnl' => $pnl,
            'nature' => $nature,
            'source_file' => $sourceFile,
            'source_row_ref' => $rowRef,
            'created_by' => $userId
        ];
    }

    if (empty($validRows)) {
        return 0;
    }

    $wasInTx = $pdo->inTransaction();
    if (!$wasInTx) {
        $pdo->beginTransaction();
    }

    try {
        $chunkSize = 250;
        $chunks = array_chunk($validRows, $chunkSize);
        foreach ($chunks as $chunk) {
            $valuePlaceholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $valuePlaceholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?)";
                $params[] = $row['plant_id'];
                $params[] = $row['period_id'];
                $params[] = $row['period_code'];
                $params[] = $row['source'];
                $params[] = $row['account_code'];
                $params[] = $row['item_code'];
                $params[] = $row['description'];
                $params[] = $row['amount'];
                $params[] = $row['cost_basis'];
                $params[] = $row['pnl'];
                $params[] = $row['nature'];
                $params[] = $row['source_file'];
                $params[] = $row['source_row_ref'];
                $params[] = $row['created_by'];
            }

            $sql = "INSERT INTO public.quantity_reco_source_transactions (
                plant_id, period_id, period_code, source,
                account_code, item_code, description, amount,
                cost_basis, pnl_classification, nature, source_file, source_row_ref, is_active, created_by
            ) VALUES " . implode(", ", $valuePlaceholders);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        if (!$wasInTx) {
            $pdo->commit();
        }
        return count($validRows);
    } catch (Throwable $e) {
        if (!$wasInTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}"""

assert old_save in code, "old_save not found"
code = code.replace(old_save, new_save, 1)

# 2. Optimize Cost Centre lookups and batch detail inserts in generateQuantityReco
old_loop_section = """        // 5. Fetch all Cost Centres for this Plant (active)
        $costCentres = getPlantCostCentres($pdo, $plantId, true);
        $costCentreById = [];
        $directLookup = []; // Normalized string -> CC ID

        foreach ($costCentres as $cc) {
            $cid = (int)$cc['id'];
            $costCentreById[$cid] = $cc;

            $cCode = strtolower(trim($cc['cost_center_code']));
            $cName = strtolower(trim($cc['cost_center_name']));
            $bCode = strtolower(trim($cc['cost_basis_code'] ?? ''));

            if ($cCode !== '') $directLookup[$cCode] = $cid;
            if ($cName !== '') $directLookup[$cName] = $cid;
            if ($bCode !== '') $directLookup[$bCode] = $cid;
        }

        // 6. Fetch all Cost Basis Mappings for this Plant (including inactive for audit/exception reporting)
        $configuredMappings = getPlantCostBasisMappings($pdo, $plantId, false);
        $mappingLookup = []; // Normalized source_cost_basis -> mapping record

        foreach ($configuredMappings as $m) {
            $normBasis = strtolower(trim($m['source_cost_basis']));
            if ($normBasis !== '' && !isset($mappingLookup[$normBasis])) {
                $mappingLookup[$normBasis] = $m;
            }
        }

        // 7. Fetch eligible source transactions
        $srcStmt = $pdo->prepare("
            SELECT source_txn_id, plant_id, period_id, source, account_code, item_code,
                   description, amount, cost_basis,
                   COALESCE(pnl_classification, 'Rev from Operations') AS pnl_classification,
                   COALESCE(nature, 'Variable') AS nature,
                   source_file, source_row_ref
            FROM public.quantity_reco_source_transactions
            WHERE plant_id = :plant_id 
              AND period_id = :period_id
              AND is_active = TRUE
            ORDER BY source_txn_id ASC
        ");
        $srcStmt->execute([':plant_id' => $plantId, ':period_id' => $periodId]);
        $sourceRows = $srcStmt->fetchAll(PDO::FETCH_ASSOC);

        // Track totals
        $totalRecords = 0;
        $mappedCount = 0;
        $unmappedCount = 0;
        $totalAmount = 0.0;
        $mappedAmount = 0.0;
        $unmappedAmount = 0.0;

        $insDetail = $pdo->prepare("
            INSERT INTO public.quantity_reco_detail (
                run_id, source_transaction_id, source_row_ref,
                source_description, source_cost_basis, cost_centre_id,
                amount, mapping_id, account_code, pnl_classification, nature, created_at
            ) VALUES (
                :run_id, :source_txn_id, :row_ref,
                :desc, :basis, :cc_id,
                :amount, :mapping_id, :account_code, :pnl, :nature, CURRENT_TIMESTAMP
            )
        ");

        $insException = $pdo->prepare("
            INSERT INTO public.quantity_reco_exception (
                run_id, source_transaction_id, plant_id, period_id,
                source_reference, source_description, cost_basis,
                amount, reason, status, created_at, updated_at
            ) VALUES (
                :run_id, :source_txn_id, :plant_id, :period_id,
                :source_ref, :desc, :basis,
                :amount, :reason, 'UNMAPPED', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        // 8. Processing Loop
        foreach ($sourceRows as $row) {
            $totalRecords++;
            $txnId = (int)$row['source_txn_id'];
            $amount = (float)$row['amount'];
            $costBasis = trim((string)$row['cost_basis']);
            $desc = trim((string)$row['description']);
            $rowRef = trim((string)$row['source_row_ref']) ?: ("ROW-" . $txnId);
            $normBasis = strtolower($costBasis);

            $totalAmount += $amount;

            $resolvedCCId = null;
            $resolvedMappingId = null;
            $unmappedReason = null;

            // Step 8a: Check explicit mapping configuration
            if (isset($mappingLookup[$normBasis])) {
                $m = $mappingLookup[$normBasis];
                if (!$m['is_active']) {
                    $unmappedReason = "Cost basis mapping for '{$costBasis}' is marked Inactive.";
                } elseif (isset($m['cost_centre_is_active']) && !$m['cost_centre_is_active']) {
                    $unmappedReason = "Target Cost Centre '{$m['cost_center_name']}' is marked Inactive.";
                } else {
                    $resolvedCCId = (int)$m['cost_centre_id'];
                    $resolvedMappingId = (int)$m['mapping_id'];
                }
            } 
            // Step 8b: Fallback to exact direct Cost Centre match
            elseif (isset($directLookup[$normBasis])) {
                $resolvedCCId = $directLookup[$normBasis];
            } else {
                // Check if matches an inactive cost centre in plant
                $chkInact = $pdo->prepare("SELECT cost_center_name, is_active FROM public.cost_center_master WHERE plant_id = ? AND (LOWER(cost_center_code) = ? OR LOWER(cost_center_name) = ? OR LOWER(cost_basis_code) = ?) LIMIT 1");
                $chkInact->execute([$plantId, $normBasis, $normBasis, $normBasis]);
                $inactRow = $chkInact->fetch(PDO::FETCH_ASSOC);
                if ($inactRow && !$inactRow['is_active']) {
                    $unmappedReason = "Target Cost Centre '{$inactRow['cost_center_name']}' is marked Inactive.";
                } else {
                    $unmappedReason = "No Cost Basis Mapping or Cost Centre found matching '{$costBasis}'.";
                }
            }

            // Step 8d: Insert into Detail or Exception
            if ($resolvedCCId !== null) {
                $insDetail->execute([
                    ':run_id'        => $runId,
                    ':source_txn_id' => $txnId,
                    ':row_ref'       => $rowRef,
                    ':desc'          => $desc,
                    ':basis'         => $costBasis,
                    ':cc_id'         => $resolvedCCId,
                    ':amount'        => $amount,
                    ':mapping_id'    => $resolvedMappingId,
                    ':account_code'  => trim((string)($row['account_code'] ?? '')),
                    ':pnl'           => trim((string)($row['pnl_classification'] ?? 'Rev from Operations')) ?: 'Rev from Operations',
                    ':nature'        => trim((string)($row['nature'] ?? 'Variable')) ?: 'Variable'
                ]);
                $mappedCount++;
                $mappedAmount += $amount;
            } else {
                // UNMAPPED RECORD: DO NOT DISCARD, DO NOT FORCE RANDOMLY
                $insException->execute([
                    ':run_id'        => $runId,
                    ':source_txn_id' => $txnId,
                    ':plant_id'      => $plantId,
                    ':period_id'     => $periodId,
                    ':source_ref'    => $rowRef,
                    ':desc'          => $desc,
                    ':basis'         => $costBasis,
                    ':amount'        => $amount,
                    ':reason'        => $unmappedReason
                ]);
                $unmappedCount++;
                $unmappedAmount += $amount;
            }
        }"""

new_loop_section = """        // 5. Fetch all Cost Centres for this Plant (active & inactive)
        $costCentres = getPlantCostCentres($pdo, $plantId, true);
        $allCostCentres = getPlantCostCentres($pdo, $plantId, false);
        $costCentreById = [];
        $directLookup = []; // Normalized string -> CC ID
        $inactiveLookup = []; // Normalized string -> CC Name

        foreach ($costCentres as $cc) {
            $cid = (int)$cc['id'];
            $costCentreById[$cid] = $cc;

            $cCode = strtolower(trim($cc['cost_center_code']));
            $cName = strtolower(trim($cc['cost_center_name']));
            $bCode = strtolower(trim($cc['cost_basis_code'] ?? ''));

            if ($cCode !== '') $directLookup[$cCode] = $cid;
            if ($cName !== '') $directLookup[$cName] = $cid;
            if ($bCode !== '') $directLookup[$bCode] = $cid;
        }

        foreach ($allCostCentres as $cc) {
            if (!$cc['is_active']) {
                $cCode = strtolower(trim($cc['cost_center_code']));
                $cName = strtolower(trim($cc['cost_center_name']));
                $bCode = strtolower(trim($cc['cost_basis_code'] ?? ''));
                if ($cCode !== '') $inactiveLookup[$cCode] = $cc['cost_center_name'];
                if ($cName !== '') $inactiveLookup[$cName] = $cc['cost_center_name'];
                if ($bCode !== '') $inactiveLookup[$bCode] = $cc['cost_center_name'];
            }
        }

        // 6. Fetch all Cost Basis Mappings for this Plant (including inactive for audit/exception reporting)
        $configuredMappings = getPlantCostBasisMappings($pdo, $plantId, false);
        $mappingLookup = []; // Normalized source_cost_basis -> mapping record

        foreach ($configuredMappings as $m) {
            $normBasis = strtolower(trim($m['source_cost_basis']));
            if ($normBasis !== '' && !isset($mappingLookup[$normBasis])) {
                $mappingLookup[$normBasis] = $m;
            }
        }

        // 7. Fetch eligible source transactions
        $srcStmt = $pdo->prepare("
            SELECT source_txn_id, plant_id, period_id, source, account_code, item_code,
                   description, amount, cost_basis,
                   COALESCE(pnl_classification, 'Rev from Operations') AS pnl_classification,
                   COALESCE(nature, 'Variable') AS nature,
                   source_file, source_row_ref
            FROM public.quantity_reco_source_transactions
            WHERE plant_id = :plant_id 
              AND period_id = :period_id
              AND is_active = TRUE
            ORDER BY source_txn_id ASC
        ");
        $srcStmt->execute([':plant_id' => $plantId, ':period_id' => $periodId]);
        $sourceRows = $srcStmt->fetchAll(PDO::FETCH_ASSOC);

        // Track totals
        $totalRecords = 0;
        $mappedCount = 0;
        $unmappedCount = 0;
        $totalAmount = 0.0;
        $mappedAmount = 0.0;
        $unmappedAmount = 0.0;

        $detailsToInsert = [];
        $exceptionsToInsert = [];

        // 8. Processing Loop
        foreach ($sourceRows as $row) {
            $totalRecords++;
            $txnId = (int)$row['source_txn_id'];
            $amount = (float)$row['amount'];
            $costBasis = trim((string)$row['cost_basis']);
            $desc = trim((string)$row['description']);
            $rowRef = trim((string)$row['source_row_ref']) ?: ("ROW-" . $txnId);
            $normBasis = strtolower($costBasis);

            $totalAmount += $amount;

            $resolvedCCId = null;
            $resolvedMappingId = null;
            $unmappedReason = null;

            // Step 8a: Check explicit mapping configuration
            if (isset($mappingLookup[$normBasis])) {
                $m = $mappingLookup[$normBasis];
                if (!$m['is_active']) {
                    $unmappedReason = "Cost basis mapping for '{$costBasis}' is marked Inactive.";
                } elseif (isset($m['cost_centre_is_active']) && !$m['cost_centre_is_active']) {
                    $unmappedReason = "Target Cost Centre '{$m['cost_center_name']}' is marked Inactive.";
                } else {
                    $resolvedCCId = (int)$m['cost_centre_id'];
                    $resolvedMappingId = (int)$m['mapping_id'];
                }
            } 
            // Step 8b: Fallback to exact direct Cost Centre match
            elseif (isset($directLookup[$normBasis])) {
                $resolvedCCId = $directLookup[$normBasis];
            } else {
                // Check if matches an inactive cost centre in plant (in-memory lookup)
                if (isset($inactiveLookup[$normBasis])) {
                    $unmappedReason = "Target Cost Centre '{$inactiveLookup[$normBasis]}' is marked Inactive.";
                } else {
                    $unmappedReason = "No Cost Basis Mapping or Cost Centre found matching '{$costBasis}'.";
                }
            }

            // Step 8d: Buffer into Detail or Exception
            if ($resolvedCCId !== null) {
                $detailsToInsert[] = [
                    'run_id'        => $runId,
                    'source_txn_id' => $txnId,
                    'row_ref'       => $rowRef,
                    'desc'          => $desc,
                    'basis'         => $costBasis,
                    'cc_id'         => $resolvedCCId,
                    'amount'        => $amount,
                    'mapping_id'    => $resolvedMappingId,
                    'account_code'  => trim((string)($row['account_code'] ?? '')),
                    'pnl'           => trim((string)($row['pnl_classification'] ?? 'Rev from Operations')) ?: 'Rev from Operations',
                    'nature'        => trim((string)($row['nature'] ?? 'Variable')) ?: 'Variable'
                ];
                $mappedCount++;
                $mappedAmount += $amount;
            } else {
                // UNMAPPED RECORD: DO NOT DISCARD, DO NOT FORCE RANDOMLY
                $exceptionsToInsert[] = [
                    'run_id'        => $runId,
                    'source_txn_id' => $txnId,
                    'plant_id'      => $plantId,
                    'period_id'     => $periodId,
                    'source_ref'    => $rowRef,
                    'desc'          => $desc,
                    'basis'         => $costBasis,
                    'amount'        => $amount,
                    'reason'        => $unmappedReason
                ];
                $unmappedCount++;
                $unmappedAmount += $amount;
            }
        }

        // Batch insert details in chunks of 250
        if (!empty($detailsToInsert)) {
            $chunks = array_chunk($detailsToInsert, 250);
            foreach ($chunks as $chunk) {
                $placeholders = [];
                $params = [];
                foreach ($chunk as $d) {
                    $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                    $params[] = $d['run_id'];
                    $params[] = $d['source_txn_id'];
                    $params[] = $d['row_ref'];
                    $params[] = $d['desc'];
                    $params[] = $d['basis'];
                    $params[] = $d['cc_id'];
                    $params[] = $d['amount'];
                    $params[] = $d['mapping_id'];
                    $params[] = $d['account_code'];
                    $params[] = $d['pnl'];
                    $params[] = $d['nature'];
                }
                $sql = "INSERT INTO public.quantity_reco_detail (
                    run_id, source_transaction_id, source_row_ref,
                    source_description, source_cost_basis, cost_centre_id,
                    amount, mapping_id, account_code, pnl_classification, nature, created_at
                ) VALUES " . implode(", ", $placeholders);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
        }

        // Batch insert exceptions in chunks of 250
        if (!empty($exceptionsToInsert)) {
            $chunks = array_chunk($exceptionsToInsert, 250);
            foreach ($chunks as $chunk) {
                $placeholders = [];
                $params = [];
                foreach ($chunk as $e) {
                    $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, 'UNMAPPED', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
                    $params[] = $e['run_id'];
                    $params[] = $e['source_txn_id'];
                    $params[] = $e['plant_id'];
                    $params[] = $e['period_id'];
                    $params[] = $e['source_ref'];
                    $params[] = $e['desc'];
                    $params[] = $e['basis'];
                    $params[] = $e['amount'];
                    $params[] = $e['reason'];
                }
                $sql = "INSERT INTO public.quantity_reco_exception (
                    run_id, source_transaction_id, plant_id, period_id,
                    source_reference, source_description, cost_basis,
                    amount, reason, status, created_at, updated_at
                ) VALUES " . implode(", ", $placeholders);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
        }"""

assert old_loop_section in code, "old_loop_section not found"
code = code.replace(old_loop_section, new_loop_section, 1)

with open('config/quantity_reco.php', 'w', encoding='utf-8') as f:
    f.write(code)

print("Successfully applied batch optimizations to config/quantity_reco.php!")

