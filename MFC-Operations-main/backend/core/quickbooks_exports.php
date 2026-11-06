<?php

declare(strict_types=1);

function qb_export_types(): array
{
    return ['invoice', 'sales_order'];
}

function qb_export_is_authorized(array $user): bool
{
    return ($user['role'] ?? '') === 'Admin';
}

function qb_export_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function qb_export_money_cents(mixed $value): ?int
{
    $value = trim((string)$value);
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad($fraction, 2, '0');
    return ((int)$whole * 100) + (int)$fraction;
}

function qb_export_money_string(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function qb_export_preflight_summary_messages(array $errors): array
{
    $messages = [];
    $sharedFailures = [];
    foreach ($errors as $error) {
        if (!is_array($error)) {
            continue;
        }
        $message = (string)($error['message'] ?? 'A QuickBooks preflight requirement is not satisfied.');
        if (!empty($error['shared_failure'])) {
            $failureKey = implode('|', [
                (string)($error['diagnostic_code'] ?? $error['code'] ?? ''),
                (string)($error['failure_stage'] ?? ''),
                (string)($error['sdk_error_code'] ?? ''),
            ]);
            if (isset($sharedFailures[$failureKey])) {
                $sharedFailures[$failureKey]['count']++;
                continue;
            }
            $sharedFailures[$failureKey] = ['message' => $message, 'count' => 1, 'error' => $error];
            continue;
        }
        $reference = is_string($error['reference'] ?? null) ? trim($error['reference']) : '';
        $entity = is_string($error['entity'] ?? null) ? trim($error['entity']) : '';
        $messages[] = ($entity !== '' && $reference !== '' ? $entity . ' "' . $reference . '": ' : '') . $message;
    }
    foreach ($sharedFailures as $failure) {
        $error = $failure['error'];
        $message = $failure['message'];
        if (is_string($error['diagnostic_code'] ?? null) && $error['diagnostic_code'] !== '') {
            $message .= ' (' . $error['diagnostic_code'];
            if (is_string($error['failure_stage'] ?? null) && $error['failure_stage'] !== '') {
                $message .= ', stage ' . $error['failure_stage'];
            }
            if (is_string($error['sdk_error_code'] ?? null) && $error['sdk_error_code'] !== '') {
                $message .= ', ' . $error['sdk_error_code'];
            }
            $message .= ')';
        }
        if ($failure['count'] > 1) {
            $message .= ' This shared failure affected ' . $failure['count'] . ' reference queries; see Advanced Diagnostics for each reference.';
        }
        $messages[] = $message;
    }
    return $messages;
}

function qb_export_reference(PDO $pdo, string $type, string $localKey, ?string $listId): ?array
{
    $secret = getenv('WQ_QB_VERIFICATION_SECRET') ?: '';
    $companyFingerprint = strtolower(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: '');
    if (
        strlen($secret) < 32
        || !preg_match('/^[a-f0-9]{64}$/D', $companyFingerprint)
    ) {
        return null;
    }

    $requestedListId = $listId === null ? null : trim((string)$listId);
    if ($requestedListId === '') {
        $requestedListId = null;
    }

    $localListId = null;
    if (preg_match('/^(client|product):(\d+)$/D', $localKey, $localMatches)) {
        $localTable = $localMatches[1] === 'client' ? 'clients' : 'products';
        $localId = (int)$localMatches[2];
        $localQuery = $pdo->prepare('SELECT qb_list_id FROM ' . $localTable . ' WHERE id=?');
        $localQuery->execute([$localId]);
        $localRecord = $localQuery->fetch(PDO::FETCH_ASSOC);
        $localListId = trim((string)($localRecord['qb_list_id'] ?? ''));
    }

    $query = $pdo->prepare(
        'SELECT v.reference_type, v.local_key, v.qb_list_id, v.qb_full_name, v.active,
                v.verified_at, v.verified_by, v.company_file_fingerprint,
                v.diagnostic_request_id, v.evidence_hmac
         FROM qb_export_reference_verifications v
         JOIN employees e ON e.id=v.verified_by AND e.active=1 AND e.role="Admin"
         WHERE v.reference_type=? AND v.local_key=? AND v.active=1'
    );
    $query->execute([$type, $localKey]);
    $mapping = $query->fetch();
    if (
        $mapping
        && $requestedListId !== null
        && !hash_equals((string)$mapping['qb_list_id'], $requestedListId)
    ) {
        return null;
    }
    $preferStoredVerification = $mapping && (
        $requestedListId === null
        ? $localListId !== ''
        : hash_equals((string)$mapping['qb_list_id'], $requestedListId)
    );
    if ($mapping && $preferStoredVerification) {
        if (
            !preg_match('/^[a-f0-9]{64}$/D', (string)$mapping['company_file_fingerprint'])
            || !qb_export_verification_is_current($mapping, $companyFingerprint)
            || !preg_match('/^[a-f0-9-]{36}$/iD', (string)$mapping['diagnostic_request_id'])
            || !preg_match('/^[a-f0-9]{64}$/D', (string)$mapping['evidence_hmac'])
            || !hash_equals(
                qb_export_verification_signature($mapping, $secret),
                (string)$mapping['evidence_hmac']
            )
        ) {
            return null;
        }
        return $mapping;
    }

    return null;
}

function qb_export_verification_signature(array $mapping, string $secret): string
{
    $evidence = implode("\n", [
        (string)$mapping['reference_type'],
        (string)$mapping['local_key'],
        (string)$mapping['qb_list_id'],
        (string)($mapping['qb_full_name'] ?? ''),
        (string)$mapping['active'],
        (string)$mapping['company_file_fingerprint'],
        (string)$mapping['diagnostic_request_id'],
        (string)$mapping['verified_at'],
        (string)$mapping['verified_by'],
    ]);
    return hash_hmac('sha256', $evidence, $secret);
}

function qb_export_verification_is_current(array $mapping, string $expectedFingerprint): bool
{
    $verifiedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)($mapping['verified_at'] ?? ''));
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $verifiedAt !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $verifiedAt <= new DateTimeImmutable()
        && hash_equals($expectedFingerprint, strtolower((string)($mapping['company_file_fingerprint'] ?? '')));
}

function qb_export_name_candidate_status(string $type, array $searchNames, array $record): string
{
    if (
        !in_array($type, ['customer', 'item'], true)
        || !$searchNames
        || !is_string($record['name'] ?? null)
        || !is_string($record['listId'] ?? null)
        || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['listId'])
        || !is_string($record['fullName'] ?? null)
        || trim($record['fullName']) === ''
    ) {
        return 'identity_mismatch';
    }
    $nameFields = $type === 'customer'
        ? ['name' => $record['name']]
        : ['name' => $record['name'], 'fullName' => $record['fullName']];
    $nameMatched = false;
    foreach ($nameFields as $value) {
        if (is_string($value) && in_array($value, $searchNames, true)) {
            $nameMatched = true;
            break;
        }
    }
    if (!$nameMatched) {
        return 'name_mismatch';
    }
    if (($record['active'] ?? null) === false) {
        return 'inactive';
    }
    if (($record['active'] ?? null) !== true) {
        return 'active_status_unknown';
    }
    return $type === 'item' ? 'item_code_source_unverified' : 'verified_candidate';
}

function qb_export_name_candidate_summary(string $type, array $searchNames, array $records): array
{
    $matches = [];
    $diagnosticMatches = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $status = qb_export_name_candidate_status($type, $searchNames, $record);
        if ($status !== 'name_mismatch' && $status !== 'identity_mismatch') {
            $diagnosticMatches[] = ['record' => $record, 'status' => $status];
        }
        if ($status === 'verified_candidate') {
            $matches[] = $record;
        }
    }
    if ($type === 'item') {
        $itemStatus = count($diagnosticMatches) === 0
            ? (count($records) === 0 ? 'not_found_by_name' : 'name_mismatch')
            : (count($diagnosticMatches) > 1
                ? 'ambiguous_name_candidates'
                : ($diagnosticMatches[0]['status'] === 'inactive'
                    ? 'inactive_name_candidate'
                    : ($diagnosticMatches[0]['status'] === 'active_status_unknown'
                        ? 'active_status_unknown'
                        : 'item_code_source_unverified')));
        return [
            'status' => $itemStatus,
            'matches' => [],
            'diagnostic_matches' => $diagnosticMatches,
            'candidate' => null,
        ];
    }
    return [
        'status' => count($records) === 0
            ? 'not_found'
            : (count($matches) > 1
                ? 'ambiguous'
                : (count($matches) === 1 ? 'candidate_found' : 'business_field_mismatch')),
        'matches' => $matches,
        'diagnostic_matches' => $diagnosticMatches,
        'candidate' => count($matches) === 1 ? $matches[0] : null,
    ];
}

function qb_export_store_reference_verification(PDO $pdo, array $verification): void
{
    $type = (string)($verification['reference_type'] ?? '');
    $localKey = (string)($verification['local_key'] ?? '');
    $listId = (string)($verification['qb_list_id'] ?? '');
    $fullName = $verification['qb_full_name'] ?? null;
    $fingerprint = (string)($verification['company_file_fingerprint'] ?? '');
    $requestId = (string)($verification['diagnostic_request_id'] ?? '');
    $secret = getenv('WQ_QB_VERIFICATION_SECRET') ?: '';
    $localKeyPattern = $type === 'customer' ? '/^client:\d+$/D' : '/^product:\d+$/D';
    $verifiedAt = is_string($verification['verified_at'] ?? null) ? $verification['verified_at'] : '';
    $verifiedAtDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $verifiedAt);
    $verifiedAtErrors = DateTimeImmutable::getLastErrors();
    if (
        !in_array($type, ['customer', 'item'], true)
        || !preg_match($localKeyPattern, $localKey)
        || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $listId)
        || !is_string($fullName)
        || trim($fullName) === ''
        || mb_strlen($fullName) > 255
        || ($verification['active'] ?? null) !== 1
        || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)
        || !preg_match('/^[a-f0-9-]{36}$/iD', $requestId)
        || strlen($secret) < 32
        || $verifiedAtDate === false
        || ($verifiedAtErrors !== false && ($verifiedAtErrors['warning_count'] !== 0 || $verifiedAtErrors['error_count'] !== 0))
        || $verifiedAtDate->format('Y-m-d H:i:s') !== $verifiedAt
        || !is_int($verification['verified_by'] ?? null)
        || $verification['verified_by'] < 1
        || !is_string($verification['evidence_hmac'] ?? null)
        || !hash_equals(qb_export_verification_signature($verification, $secret), $verification['evidence_hmac'])
    ) {
        throw new DomainException('The signed QuickBooks reference evidence is invalid.');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Reference verification persistence requires a database transaction.');
    }

    $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $existingQuery = $pdo->prepare(
        'SELECT id, local_key FROM qb_export_reference_verifications
         WHERE reference_type=? AND (local_key=? OR qb_list_id=?)' . $lockSuffix
    );
    $existingQuery->execute([$type, $localKey, $listId]);
    $existing = $existingQuery->fetchAll();
    foreach ($existing as $row) {
        if (!hash_equals($localKey, (string)$row['local_key'])) {
            throw new DomainException('That QuickBooks ListID is already assigned to a different local record.');
        }
    }
    $values = [
        $listId,
        $fullName,
        $verification['verified_at'],
        $verification['verified_by'],
        $fingerprint,
        $requestId,
        $verification['evidence_hmac'],
    ];
    if ($existing) {
        $save = $pdo->prepare(
            'UPDATE qb_export_reference_verifications
             SET qb_list_id=?, qb_full_name=?, verified_at=?, verified_by=?,
                  company_file_fingerprint=?, diagnostic_request_id=?, evidence_hmac=?, active=1
             WHERE id=?'
        );
        $save->execute(array_merge($values, [(int)$existing[0]['id']]));
    } else {
        $save = $pdo->prepare(
            'INSERT INTO qb_export_reference_verifications
             (reference_type, local_key, qb_list_id, qb_full_name, verified_at, verified_by,
               company_file_fingerprint, diagnostic_request_id, evidence_hmac, active)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $save->execute([
            $type,
            $localKey,
            $listId,
            $fullName,
            $verification['verified_at'],
            $verification['verified_by'],
            $fingerprint,
            $requestId,
            $verification['evidence_hmac'],
        ]);
    }

    $table = $type === 'customer' ? 'clients' : 'products';
    $localId = (int)substr($localKey, strpos($localKey, ':') + 1);
    $update = $pdo->prepare("UPDATE {$table} SET qb_list_id=? WHERE id=? AND active=1");
    $update->execute([$listId, $localId]);
    $check = $pdo->prepare("SELECT 1 FROM {$table} WHERE id=? AND active=1 AND qb_list_id=?");
    $check->execute([$localId, $listId]);
    if (!$check->fetchColumn()) {
        throw new DomainException('The local customer or product could not be updated with the verified QuickBooks ListID.');
    }
}

function qb_export_load(PDO $pdo, string $type, int $id): ?array
{
    if ($type === 'invoice') {
        $header = $pdo->prepare(
            'SELECT i.id, i.withdrawal_id, i.customer_id, i.invoice_number AS reference_number,
                    i.invoice_date AS transaction_date, i.po_no, i.memo, i.status,
                    c.client_name, c.qb_list_id AS customer_list_id, c.active AS customer_active,
                    w.withdrawal_no
             FROM invoices i
             JOIN clients c ON c.id=i.customer_id
             JOIN withdrawals w ON w.id=i.withdrawal_id
             WHERE i.id=?'
        );
        $header->execute([$id]);
        $transaction = $header->fetch();
        if (!$transaction) {
            return null;
        }

        $lineQuery = $pdo->prepare(
            'SELECT ii.id, ii.withdrawal_item_id, ii.source_type, ii.product_id, ii.site_id, ii.product_name, ii.quantity,
                    ii.lot_number, ii.item_code, ii.description, ii.u_m,
                    ii.price_each AS rate, ii.amount, ii.price_source, ii.tax_code, ii.expiration_date,
                    p.qb_list_id AS item_list_id, p.qb_code AS current_qb_code,
                    p.qb_description AS current_qb_description, p.description AS current_product_description,
                    p.u_m AS current_product_u_m, p.active AS product_active,
                    s.site_name, s.active AS site_active
             FROM invoice_items ii
             LEFT JOIN products p ON p.id=ii.product_id
             LEFT JOIN sites s ON s.id=ii.site_id
             WHERE ii.invoice_id=?
             ORDER BY ii.id'
        );
        $lineQuery->execute([$id]);
    } elseif ($type === 'sales_order') {
        $header = $pdo->prepare(
            'SELECT so.id, so.withdrawal_id, so.customer_id, so.so_number AS reference_number,
                    so.sales_order_date AS transaction_date, so.po_no, so.memo, so.status,
                    so.qb_customer_list_id AS customer_list_snapshot,
                    c.client_name, c.qb_list_id AS customer_list_id, c.active AS customer_active,
                    w.withdrawal_no
             FROM sales_orders so
             JOIN clients c ON c.id=so.customer_id
             JOIN withdrawals w ON w.id=so.withdrawal_id
             WHERE so.id=?'
        );
        $header->execute([$id]);
        $transaction = $header->fetch();
        if (!$transaction) {
            return null;
        }

        $lineQuery = $pdo->prepare(
            'SELECT soi.id, soi.line_no, soi.product_id, soi.site_id, soi.item_name AS product_name,
                    soi.ordered_quantity AS quantity, soi.lot_number, soi.item_code,
                    soi.description, soi.u_m, soi.rate, soi.amount, soi.tax_code,
                    soi.expiration_date, soi.class_name, soi.line_status, soi.fulfilled_quantity,
                    soi.qb_item_list_id AS item_list_snapshot,
                    p.qb_list_id AS item_list_id, p.qb_code AS current_qb_code,
                    p.qb_description AS current_qb_description, p.description AS current_product_description,
                    p.u_m AS current_product_u_m, p.active AS product_active,
                    s.site_name, s.active AS site_active
             FROM sales_order_items soi
             LEFT JOIN products p ON p.id=soi.product_id
             LEFT JOIN sites s ON s.id=soi.site_id
             WHERE soi.sales_order_id=?
             ORDER BY soi.line_no'
        );
        $lineQuery->execute([$id]);
    } else {
        throw new InvalidArgumentException('Unsupported QuickBooks transaction type.');
    }

    $transaction['transaction_type'] = $type;
    $transaction['lines'] = $lineQuery->fetchAll();
    return $transaction;
}

function qb_export_source_revision(array $transaction): string
{
    return hash('sha256', json_encode($transaction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function qb_export_bridge_request(array $preview): array
{
    if (($preview['valid'] ?? false) !== true || !empty($preview['errors'])) {
        throw new DomainException('A validation-only bridge request requires a fully valid local preview.');
    }
    $transaction = $preview['transaction'] ?? null;
    if (!is_array($transaction) || !in_array($transaction['transaction_type'] ?? null, qb_export_types(), true)) {
        throw new InvalidArgumentException('A typed transaction preview is required.');
    }
    $lines = [];
    foreach ($transaction['lines'] as $line) {
        if (($line['site_mapping_status'] ?? 'unresolved') !== 'verified'
            || ($line['tax_mapping_status'] ?? 'unverified') !== 'verified'
            || ($line['item_mapping_status'] ?? 'unverified') !== 'verified'
            || (($transaction['transaction_type'] ?? null) === 'sales_order'
                && trim((string)($line['unit_of_measure'] ?? '')) !== '')
            || trim((string)($line['lot_number'] ?? '')) !== ''
            || trim((string)($line['expiration_date'] ?? '')) !== ''
            || trim((string)($line['class_name'] ?? '')) !== ''
            || trim((string)($line['line_status'] ?? '')) !== ''
            || trim((string)($line['fulfilled_quantity'] ?? '')) !== ''
        ) {
            throw new DomainException('A line contains unresolved QuickBooks mappings and cannot be sent to the bridge.');
        }
        $item = ['listId' => $line['item_list_id'] ?? null];
        $taxCode = $line['tax_list_id'] ?? null;
        $lineRequest = [
            'item' => $item,
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'rate' => $line['rate'],
            'expectedAmount' => $line['expected_amount'],
            'salesTaxCode' => $taxCode === null ? null : ['listId' => $taxCode],
        ];
        $lines[] = $lineRequest;
    }
    return [
        'protocolVersion' => '1',
        'requestId' => qb_export_uuid(),
        'operation' => 'validate',
        'validationOnly' => true,
        'idempotencyKey' => null,
        'transactionType' => $transaction['transaction_type'],
        'source' => ['id' => $transaction['source_id'], 'revision' => $preview['source_revision']],
        'transaction' => [
            'customer' => ['listId' => $transaction['customer_list_id']],
            'txnDate' => $transaction['transaction_date'],
            'refNumber' => $transaction['reference_number'],
            'poNumber' => $transaction['po_number'],
            'memo' => $transaction['memo'],
            'lines' => $lines,
            'expectedSubtotal' => $transaction['expected_subtotal'],
            'expectedTax' => null,
            'expectedTotal' => null,
        ],
    ];
}

function qb_export_reference_preflight_transaction(array $transaction): array
{
    $preparationMode = $transaction['preparation_mode'] ?? 'legacy_mapped';
    if (
        ($transaction['transaction_type'] ?? null) !== 'invoice'
        || !in_array($preparationMode, ['quickbooks_managed_defaults', 'legacy_mapped'], true)
    ) {
        throw new DomainException('A valid invoice preview is required for read-only QuickBooks reference checks.');
    }

    $lines = [];
    foreach ($transaction['lines'] ?? [] as $line) {
        if (
            !is_array($line)
            || !in_array(($line['item_mapping_status'] ?? null), [
                'locally recorded; current company check required',
                'exact FullName candidate; current company check required',
            ], true)
            || !in_array(($line['price_source'] ?? null), ['mfc_entered', 'explicit_zero'], true)
            || (($line['price_source'] ?? null) === 'explicit_zero'
                && qb_export_money_cents($line['rate'] ?? null) !== 0)
            || ($preparationMode === 'legacy_mapped'
                && ($line['tax_mapping_status'] ?? null) !== 'locally recorded; current company check required')
            || trim((string)($line['site'] ?? '')) === ''
            || mb_strlen((string)$line['site']) > 31
            || trim((string)($line['expiration_date'] ?? '')) !== ''
        ) {
            throw new DomainException('The invoice preview contains a reference or line value that is not eligible for read-only QuickBooks checks.');
        }
        $lineRequest = [
            'item' => is_string($line['item_full_name'] ?? null)
                ? ['fullName' => $line['item_full_name']]
                : ['listId' => $line['item_list_id'] ?? null],
            'site' => $line['site'],
            'lotNumber' => $line['lot_number'] ?? null,
            'expirationDate' => null,
        ];
        if ($preparationMode === 'legacy_mapped') {
            $lineRequest['salesTaxCode'] = ['listId' => $line['tax_list_id'] ?? null];
        }
        $lines[] = $lineRequest;
    }

    if ((is_string($transaction['customer_list_id'] ?? null)
            === is_string($transaction['customer_full_name'] ?? null))
        || (is_string($transaction['customer_list_id'] ?? null)
            && trim($transaction['customer_list_id']) === '')
        || (is_string($transaction['customer_full_name'] ?? null)
            && (trim($transaction['customer_full_name']) === ''
                || mb_strlen($transaction['customer_full_name']) > 209))
    ) {
        throw new DomainException('The invoice preview is missing its customer reference.');
    }

    return [
        'preparationMode' => $preparationMode,
        'customer' => is_string($transaction['customer_full_name'] ?? null)
            ? ['fullName' => $transaction['customer_full_name']]
            : ['listId' => $transaction['customer_list_id']],
        'lines' => $lines,
    ];
}

function qb_export_validate(
    PDO $pdo,
    string $type,
    int $id,
    ?string $excludeExportId = null,
    ?string $invoiceMode = null
): array {
    $errors = [];
    if ($type === 'invoice') {
        $invoiceMode ??= 'quickbooks_managed_defaults';
        if (!in_array($invoiceMode, ['quickbooks_managed_defaults', 'legacy_mapped'], true)) {
            throw new InvalidArgumentException('Unsupported QuickBooks invoice preparation mode.');
        }
    } else {
        $invoiceMode = 'legacy_mapped';
    }
    $quickBooksManagedDefaults = $type === 'invoice' && $invoiceMode === 'quickbooks_managed_defaults';
    $transaction = qb_export_load($pdo, $type, $id);
    if (!$transaction) {
        return [
            'valid' => false,
            'transaction' => null,
            'source_revision' => null,
            'errors' => [['code' => 'SOURCE_NOT_FOUND', 'path' => 'source', 'message' => 'The selected source transaction does not exist.']],
        ];
    }

    $addError = static function (string $code, string $path, string $message) use (&$errors): void {
        $errors[] = ['code' => $code, 'path' => $path, 'message' => $message];
    };

    $eligibleStatuses = $type === 'invoice' ? ['Draft', 'Ready'] : ['Draft'];
    if (!in_array($transaction['status'], $eligibleStatuses, true)) {
        $addError('SOURCE_NOT_ELIGIBLE', 'status', 'This transaction is not in an export-eligible local status.');
    }
    if ($type === 'invoice') {
        $legacy = $pdo->prepare('SELECT 1 FROM qb_jobs WHERE invoice_id=? LIMIT 1');
        $legacy->execute([$id]);
        if ($legacy->fetchColumn()) {
            $addError('LEGACY_JOB_EXISTS', 'source', 'An invoice-specific QuickBooks job already exists; review its legacy status before creating a typed export.');
        }
    }
    $queued = $pdo->prepare('SELECT export_id, status FROM qb_exports WHERE transaction_type=? AND source_transaction_id=? LIMIT 1');
    $queued->execute([$type, $id]);
    $existingExport = $queued->fetch();
    if ($existingExport && $existingExport['export_id'] !== $excludeExportId) {
        $addError('EXPORT_ALREADY_EXISTS', 'source', 'A typed export record already exists for this transaction; duplicate export creation is not allowed.');
    }

    $customerMapping = qb_export_reference(
        $pdo,
        'customer',
        'client:' . (int)$transaction['customer_id'],
        $transaction['customer_list_id'] ?? null
    );
    $mappingRevision = ['customer' => $customerMapping];
    if ((int)($transaction['customer_active'] ?? 0) !== 1) {
        $addError('CUSTOMER_INACTIVE', 'customer', 'The selected customer is inactive in the local client master.');
    }
    $customerFullName = null;
    if (!$customerMapping && $type === 'invoice' && trim((string)($transaction['customer_list_id'] ?? '')) === '') {
        $candidate = (string)($transaction['client_name'] ?? '');
        if (
            $candidate !== ''
            && $candidate === trim($candidate)
            && mb_strlen($candidate) <= 209
            && preg_match('/[\x00-\x1F\x7F]/', $candidate) !== 1
        ) {
            $customerFullName = $candidate;
        } else {
            $customerFullName = null;
        }
    }
    if (!$customerMapping && $customerFullName === null) {
        $addError('CUSTOMER_MAPPING_UNVERIFIED', 'customer.reference', 'A verified QuickBooks customer ListID or a valid exact FullName candidate is required.');
    }
    if (
        $type === 'sales_order'
        && trim((string)($transaction['customer_list_snapshot'] ?? '')) !== trim((string)($transaction['customer_list_id'] ?? ''))
    ) {
        $addError('CUSTOMER_MAPPING_STALE', 'customer.listId', 'The sales-order customer mapping snapshot differs from the current customer mapping.');
    }
    if (!qb_export_valid_date((string)$transaction['transaction_date'])) {
        $addError('INVALID_TRANSACTION_DATE', 'transactionDate', 'The saved transaction date is invalid.');
    }
    if (trim((string)($transaction['reference_number'] ?? '')) === '') {
        $addError('REFERENCE_NUMBER_MISSING', 'referenceNumber', 'A local invoice or sales-order reference number is required.');
    }
    if (
        $type === 'invoice'
        && mb_strlen((string)($transaction['reference_number'] ?? '')) > 11
    ) {
        $addError('REFERENCE_NUMBER_TOO_LONG', 'referenceNumber', 'QuickBooks Desktop RefNumber supports at most 11 characters; the source number must not be truncated or rewritten.');
    }
    if ($type === 'invoice' && mb_strlen((string)($transaction['po_no'] ?? '')) > 25) {
        $addError('PO_NUMBER_TOO_LONG', 'poNumber', 'QuickBooks Desktop PONumber supports at most 25 characters.');
    }
    if ($type === 'invoice' && mb_strlen((string)($transaction['memo'] ?? '')) > 4095) {
        $addError('MEMO_TOO_LONG', 'memo', 'QuickBooks Desktop invoice Memo supports at most 4095 characters.');
    }
    if (!$transaction['lines']) {
        $addError('LINES_MISSING', 'lines', 'At least one transaction line is required.');
    }
    if ($type === 'sales_order') {
        $addError('TAX_TOTAL_UNRESOLVED', 'taxTotal', 'The application does not calculate QuickBooks sales tax or a reconciled transaction total.');
    }

    $previewLines = [];
    $subtotalCents = 0;
    $subtotalOverflow = false;
    $subtotalUnresolved = false;
    foreach ($transaction['lines'] as $index => $line) {
        $lineNo = $index + 1;
        $path = 'lines[' . $index . ']';
        $quantityRaw = (string)($line['quantity'] ?? '');
        $quantity = preg_match('/^[1-9]\d{0,8}$/D', $quantityRaw) ? (int)$quantityRaw : null;
        if ($quantity === null) {
            $addError('INVALID_QUANTITY', $path . '.quantity', 'Quantity must be a positive whole number with no more than nine digits.');
        }

        $priceSource = $type === 'invoice' ? (string)($line['price_source'] ?? 'unresolved') : 'mfc_entered';
        $rateCents = qb_export_money_cents($line['rate'] ?? null);
        $hasUsableInvoicePrice = in_array($priceSource, ['mfc_entered', 'explicit_zero'], true);
        if ($type === 'invoice') {
            if (!$hasUsableInvoicePrice) {
                $subtotalUnresolved = true;
            }
            if ($priceSource === 'quickbooks_pending') {
                $addError('PRICE_UNRESOLVED', $path . '.rate', 'Enter a Unit Price or complete a company-verified QuickBooks price review before queueing.');
            } elseif ($priceSource === 'unresolved') {
                $addError('PRICE_SOURCE_UNRESOLVED', $path . '.rate', 'This historical price has no reliable source. Review and re-enter the intended Unit Price; it will not be replaced automatically.');
            } elseif ($priceSource === 'quickbooks_verified') {
                $addError('QUICKBOOKS_PRICE_EVIDENCE_MISSING', $path . '.rate', 'QuickBooks pricing evidence is not supported by the current verified-price workflow; enter an MFC Unit Price.');
            } elseif (!$hasUsableInvoicePrice) {
                $addError('INVALID_PRICE_SOURCE', $path . '.rate', 'The invoice line has an unsupported pricing source.');
            } elseif ($priceSource === 'explicit_zero' && $rateCents !== 0) {
                $addError('PRICE_SOURCE_MISMATCH', $path . '.rate', 'An explicitly zero-priced line must retain a Unit Price of 0.00.');
            }
        }
        if ($rateCents === null && ($type !== 'invoice' || $hasUsableInvoicePrice)) {
            $addError('INVALID_RATE', $path . '.rate', 'A non-negative rate with up to two decimal places is required.');
        }
        if ($type !== 'invoice' && $rateCents === 0) {
            $addError('ZERO_RATE_UNAPPROVED', $path . '.rate', 'A zero-price invoice line requires an explicit, recorded free-of-charge approval; QuickBooks price defaults have not been verified.');
        }
        $savedAmountCents = qb_export_money_cents($line['amount'] ?? null);
        if (
            $savedAmountCents === null
            && ($type !== 'invoice' || $hasUsableInvoicePrice)
        ) {
            $addError('INVALID_AMOUNT', $path . '.amount', 'The saved line amount is invalid.');
        }

        $expectedAmountCents = null;
        if (
            $quantity !== null && $rateCents !== null
            && ($type !== 'invoice' || $hasUsableInvoicePrice)
        ) {
            $maximumCents = 99999999999999;
            if ($rateCents > 0 && $quantity > intdiv($maximumCents, $rateCents)) {
                $addError('AMOUNT_OUT_OF_RANGE', $path . '.amount', 'Calculated line amount exceeds the supported database precision.');
            } else {
                $expectedAmountCents = $quantity * $rateCents;
                if ($savedAmountCents !== null && $expectedAmountCents !== $savedAmountCents) {
                    $addError('AMOUNT_MISMATCH', $path . '.amount', 'The saved line amount does not equal quantity multiplied by rate to two decimal places.');
                }
            }
        }
        if ($expectedAmountCents !== null && $expectedAmountCents <= 99999999999999) {
            if ($subtotalCents > 99999999999999 - $expectedAmountCents) {
                if (!$subtotalOverflow) {
                    $addError('SUBTOTAL_OUT_OF_RANGE', 'subtotal', 'The sum of line amounts exceeds the supported monetary precision.');
                }
                $subtotalOverflow = true;
            } elseif (!$subtotalOverflow) {
                $subtotalCents += $expectedAmountCents;
            }
        }

        $productId = (int)($line['product_id'] ?? 0);
        $itemListId = trim((string)($line['item_list_id'] ?? ''));
        $itemMapping = $productId > 0
            ? qb_export_reference($pdo, 'item', 'product:' . $productId, $itemListId)
            : null;
        $mappingRevision['items'][$index] = $itemMapping;
        if ($productId < 1 || (int)($line['product_active'] ?? 0) !== 1) {
            $addError('PRODUCT_INVALID', $path . '.product', 'The line must reference an active product in Product Master.');
        }
        $itemFullName = null;
        if (!$itemMapping && $type === 'invoice' && $itemListId === '') {
            $candidate = (string)($line['item_code'] ?? '');
            if (trim($candidate) === '') {
                $candidate = (string)($line['current_qb_code'] ?? '');
            }
            if (
                $candidate !== ''
                && $candidate === trim($candidate)
                && mb_strlen($candidate) <= 159
                && preg_match('/[\x00-\x1F\x7F]/', $candidate) !== 1
            ) {
                $itemFullName = $candidate;
            }
        }
        if (!$itemMapping && $itemFullName === null) {
            $addError('ITEM_MAPPING_UNVERIFIED', $path . '.item.reference', 'A verified QuickBooks item ListID or a valid exact FullName candidate is required.');
        }
        if (
            $type === 'sales_order'
            && trim((string)($line['item_list_snapshot'] ?? '')) !== $itemListId
        ) {
            $addError('ITEM_MAPPING_STALE', $path . '.item.listId', 'The sales-order item mapping snapshot differs from the current Product Master mapping.');
        }

        $description = trim((string)($line['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 255) {
            $addError('INVALID_DESCRIPTION', $path . '.description', 'A non-empty line description of 255 characters or fewer is required.');
        }

        $siteId = (int)($line['site_id'] ?? 0);
        if ($siteId < 1 || (int)($line['site_active'] ?? 0) !== 1) {
            $addError('SITE_REQUIRED', $path . '.site', 'Each line must reference an active local Site.');
        }
        if ($type === 'invoice' && mb_strlen((string)($line['site_name'] ?? '')) > 31) {
            $addError('SITE_NAME_TOO_LONG', $path . '.site', 'QuickBooks Desktop InventorySiteRef supports at most 31 characters.');
        }
        if ($type === 'sales_order') {
            $addError('SITE_DESTINATION_UNVERIFIED', $path . '.site', 'The QuickBooks destination for the local Site is unresolved; no Site value will be discarded.');
        }

        $unit = trim((string)($line['u_m'] ?? ''));
        if ($unit !== '' && $type === 'sales_order') {
            $addError('UNIT_DESTINATION_UNVERIFIED', $path . '.unitOfMeasure', 'The local U/M value has no verified QuickBooks unit mapping.');
        }
        $lotNumber = trim((string)($line['lot_number'] ?? ''));
        if ($type === 'invoice' && mb_strlen($lotNumber) > 40) {
            $addError('LOT_NUMBER_TOO_LONG', $path . '.lotNumber', 'QuickBooks Desktop InvoiceLineAdd LotNumber supports at most 40 characters; the value must not be truncated.');
        }
        if ($lotNumber !== '' && $type === 'sales_order') {
            $addError('LOT_DESTINATION_UNVERIFIED', $path . '.lotNumber', 'Lot Number support and destination are not verified; the value will not be silently omitted.');
        }
        if (trim((string)($line['expiration_date'] ?? '')) !== '') {
            if (!qb_export_valid_date((string)$line['expiration_date'])) {
                $addError('INVALID_EXPIRATION_DATE', $path . '.expirationDate', 'The saved expiration date is invalid.');
            }
            $addError('EXPIRATION_DESTINATION_UNVERIFIED', $path . '.expirationDate', 'Expiration Date support and destination are not verified; the value will not be silently omitted.');
        }
        if ($type === 'sales_order' && trim((string)($line['item_code'] ?? '')) !== '') {
            $addError('ITEM_CODE_DESTINATION_UNVERIFIED', $path . '.itemCode', 'The local item code is not a QuickBooks ListID and has no verified writable destination.');
        }
        foreach (
            [
                'class_name' => ['CLASS_DESTINATION_UNVERIFIED', 'class'],
                'line_status' => ['LINE_STATUS_DESTINATION_UNVERIFIED', 'lineStatus'],
                'fulfilled_quantity' => ['FULFILLED_QUANTITY_DESTINATION_UNVERIFIED', 'fulfilledQuantity'],
            ] as $field => [$code, $destination]
        ) {
            if (trim((string)($line[$field] ?? '')) !== '') {
                $addError($code, $path . '.' . $destination, 'This local Sales Order value has no verified QuickBooks destination.');
            }
        }

        $taxCode = trim((string)($line['tax_code'] ?? ''));
        if ($taxCode !== '' && !in_array($taxCode, ['Non', 'Tax'], true)) {
            $addError('INVALID_LOCAL_TAX_CODE', $path . '.taxCode', 'The local tax value must be Non or Tax; Exempt is not an active application choice.');
        }
        $taxMapping = !$quickBooksManagedDefaults && $taxCode !== ''
            ? qb_export_reference($pdo, 'tax_code', 'tax:' . $taxCode, null)
            : null;
        $mappingRevision['tax'][$index] = $quickBooksManagedDefaults ? null : $taxMapping;
        if (!$quickBooksManagedDefaults && ($taxCode === '' || !$taxMapping)) {
            $addError('TAX_MAPPING_UNVERIFIED', $path . '.taxCode', 'An explicit approved mapping from the local tax value to a QuickBooks tax code is required.');
        }

        $previewLine = [
            'line' => $lineNo,
            'local_line_id' => (int)$line['id'],
            'product_name' => (string)$line['product_name'],
            'item_list_id' => $itemMapping && $itemListId !== '' ? $itemListId : null,
            'item_code' => $line['item_code'] ?? null,
            'item_mapping_status' => $itemMapping
                ? 'locally recorded; current company check required'
                : ($itemFullName !== null ? 'exact FullName candidate; current company check required' : 'unverified'),
            'tax_list_id' => $quickBooksManagedDefaults ? null : ($taxMapping['qb_list_id'] ?? null),
            'description' => $description,
            'quantity' => $quantityRaw,
            'rate' => $rateCents === null ? (string)($line['rate'] ?? '') : qb_export_money_string($rateCents),
            'expected_amount' => $expectedAmountCents === null ? null : qb_export_money_string($expectedAmountCents),
            'saved_amount' => $savedAmountCents === null ? (string)($line['amount'] ?? '') : qb_export_money_string($savedAmountCents),
            'price_source' => $type === 'invoice' ? $priceSource : 'sales_order_rate',
            'original_user_price' => $type === 'invoice' && $hasUsableInvoicePrice && $rateCents !== null
                ? qb_export_money_string($rateCents)
                : null,
            'pricing_status' => $type === 'invoice'
                ? match ($priceSource) {
                    'mfc_entered' => 'MFC-entered price',
                    'explicit_zero' => 'Explicit zero — valid',
                    'quickbooks_verified' => 'QuickBooks price evidence unsupported',
                    'quickbooks_pending' => 'Price required; QuickBooks fallback unverified',
                    default => 'Historical price source unresolved',
                }
                : 'Sales Order rate',
            'tax_code' => $taxCode !== '' ? $taxCode : null,
            'tax_mapping_status' => $quickBooksManagedDefaults
                ? 'QuickBooks-managed; live behavior unverified'
                : ($taxMapping ? 'locally recorded; current company check required' : 'unverified'),
            'site' => $line['site_name'] ?? null,
            'site_mapping_status' => 'unresolved',
            'lot_number' => $line['lot_number'] ?? null,
            'expiration_date' => $line['expiration_date'] ?? null,
            'unit_of_measure' => $unit !== '' ? $unit : null,
            'unit_mapping_status' => $unit === '' ? 'not supplied' : 'unresolved',
            'class_name' => $line['class_name'] ?? null,
            'line_status' => $line['line_status'] ?? null,
            'fulfilled_quantity' => $line['fulfilled_quantity'] ?? null,
        ];
        if ($itemFullName !== null) {
            $previewLine['item_full_name'] = $itemFullName;
        }
        $previewLines[] = $previewLine;
    }

    $preview = [
        'transaction_type' => $type,
        'source_id' => (int)$transaction['id'],
        'withdrawal_no' => (string)$transaction['withdrawal_no'],
        'customer' => (string)$transaction['client_name'],
        'customer_list_id' => $customerMapping
            ? (trim((string)($transaction['customer_list_id'] ?? '')) ?: null)
            : null,
        'customer_mapping_status' => $customerMapping
            ? 'locally recorded; current company check required'
            : ($customerFullName !== null ? 'exact FullName candidate; current company check required' : 'unverified'),
        'reference_number' => (string)($transaction['reference_number'] ?? ''),
        'transaction_date' => (string)$transaction['transaction_date'],
        'po_number' => $transaction['po_no'],
        'memo' => $transaction['memo'],
        'status' => (string)$transaction['status'],
        'lines' => $previewLines,
        'expected_subtotal' => $subtotalOverflow
            ? 'exceeds supported precision'
            : ($subtotalUnresolved ? 'unresolved pricing' : qb_export_money_string($subtotalCents)),
        'tax_total' => null,
        'expected_total' => null,
        'tax_total_status' => $type === 'invoice'
            ? ($quickBooksManagedDefaults
                ? 'QuickBooks-managed; default behavior and returned tax require live verification'
                : 'QuickBooks calculates tax from the verified line tax codes; tax rules must pass live company preflight')
            : 'unresolved: local application does not calculate tax',
    ];
    if ($quickBooksManagedDefaults) {
        $preview['preparation_mode'] = $invoiceMode;
    }
    if ($customerFullName !== null) {
        $preview['customer_full_name'] = $customerFullName;
    }

    return [
        'valid' => count($errors) === 0,
        'transaction' => $preview,
        'source_revision' => qb_export_source_revision([
            'transaction' => $transaction,
            'verified_mappings' => $mappingRevision,
        ]),
        'errors' => $errors,
    ];
}

function qb_export_create(
    PDO $pdo,
    string $type,
    int $id,
    string $expectedRevision,
    int $userId,
    ?string $invoiceMode = null
): array {
    if (!in_array($type, qb_export_types(), true) || $id < 1 || !preg_match('/^[a-f0-9]{64}$/D', $expectedRevision)) {
        throw new InvalidArgumentException('Invalid export source or revision.');
    }

    $pdo->beginTransaction();
    try {
        $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $actor = $pdo->prepare("SELECT 1 FROM employees WHERE id=? AND role='Admin' AND active=1" . $lockSuffix);
        $actor->execute([$userId]);
        if (!$actor->fetchColumn()) {
            throw new DomainException('An active administrator is required to create an export preview record.');
        }
        qb_export_lock_source($pdo, $type, $id);

        $validation = qb_export_validate($pdo, $type, $id, null, $invoiceMode);
        if (!$validation['valid']) {
            throw new DomainException('The transaction has blocking validation errors.');
        }
        if (!hash_equals($expectedRevision, $validation['source_revision'])) {
            throw new DomainException('The transaction changed after its preview. Refresh and review it again.');
        }
        $finalValidation = qb_export_validate($pdo, $type, $id, null, $invoiceMode);
        if (
            !$finalValidation['valid']
            || !hash_equals($validation['source_revision'], (string)$finalValidation['source_revision'])
        ) {
            throw new DomainException('The transaction or its mappings changed during queue creation. Refresh the preview and try again.');
        }
        $existing = $pdo->prepare('SELECT export_id, status FROM qb_exports WHERE transaction_type=? AND source_transaction_id=?');
        $existing->execute([$type, $id]);
        $duplicate = $existing->fetch();
        if ($duplicate) {
            throw new DomainException('An export record already exists for this source transaction (' . $duplicate['status'] . ').');
        }

        $exportId = qb_export_uuid();
        $insert = $pdo->prepare(
            'INSERT INTO qb_exports
             (export_id, transaction_type, source_transaction_id, source_revision, snapshot_json, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $exportId,
            $type,
            $id,
            $validation['source_revision'],
            json_encode($validation['transaction'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'pending',
            $userId,
        ]);
        qb_export_insert_event($pdo, $exportId, 'created', null, 'pending', $userId, null, null);
        $audit = $pdo->prepare(
            'INSERT INTO activity_logs(employee_id, action, entity_type, entity_id, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $audit->execute([
            $userId,
            'QUEUE_PREVIEWED_EXPORT',
            'qb_export',
            $id,
            'Created pending preview-only ' . $type . ' export ' . $exportId,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
        $pdo->commit();
        return ['export_id' => $exportId, 'status' => 'pending'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function qb_export_lock_source(PDO $pdo, string $type, int $id): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }

    $sourceTable = $type === 'invoice' ? 'invoices' : 'sales_orders';
    $lineTable = $type === 'invoice' ? 'invoice_items' : 'sales_order_items';
    $lineForeignKey = $type === 'invoice' ? 'invoice_id' : 'sales_order_id';
    $source = $pdo->prepare("SELECT id, customer_id, withdrawal_id FROM {$sourceTable} WHERE id=? FOR UPDATE");
    $source->execute([$id]);
    $header = $source->fetch();
    if (!$header) {
        throw new RuntimeException('The source transaction no longer exists.');
    }

    $withdrawal = $pdo->prepare('SELECT id FROM withdrawals WHERE id=? FOR UPDATE');
    $withdrawal->execute([(int)$header['withdrawal_id']]);
    $withdrawal->fetchColumn();

    $customer = $pdo->prepare('SELECT id FROM clients WHERE id=? FOR UPDATE');
    $customer->execute([(int)$header['customer_id']]);
    $customer->fetchColumn();

    $lineQuery = $pdo->prepare("SELECT id, product_id, site_id, tax_code FROM {$lineTable} WHERE {$lineForeignKey}=? ORDER BY id FOR UPDATE");
    $lineQuery->execute([$id]);
    $lines = $lineQuery->fetchAll();
    $productIds = array_values(array_unique(array_filter(array_map(static fn(array $line): int => (int)($line['product_id'] ?? 0), $lines))));
    $siteIds = array_values(array_unique(array_filter(array_map(static fn(array $line): int => (int)($line['site_id'] ?? 0), $lines))));
    sort($productIds);
    sort($siteIds);
    foreach ([['products', $productIds], ['sites', $siteIds]] as [$table, $ids]) {
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $lock = $pdo->prepare("SELECT id FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
            $lock->execute($ids);
            $lock->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $referenceKeys = [['customer', 'client:' . (int)$header['customer_id']]];
    foreach ($productIds as $productId) {
        $referenceKeys[] = ['item', 'product:' . $productId];
    }
    foreach ($lines as $line) {
        $taxCode = trim((string)($line['tax_code'] ?? ''));
        if ($taxCode !== '') {
            $referenceKeys[] = ['tax_code', 'tax:' . $taxCode];
        }
    }
    foreach ($referenceKeys as [$referenceType, $localKey]) {
        $mapping = $pdo->prepare(
            'SELECT id, verified_by FROM qb_export_reference_verifications
             WHERE reference_type=? AND local_key=? FOR UPDATE'
        );
        $mapping->execute([$referenceType, $localKey]);
        $verification = $mapping->fetch();
        if ($verification) {
            $approver = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE');
            $approver->execute([(int)$verification['verified_by']]);
            $approver->fetchColumn();
        }
    }
}

function qb_export_insert_event(
    PDO $pdo,
    string $exportId,
    string $eventType,
    ?string $fromStatus,
    string $toStatus,
    ?int $actorId,
    ?string $requestId,
    ?string $diagnosticCode
): void {
    if ($actorId !== null) {
        $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $authorization = $pdo->prepare("SELECT 1 FROM employees WHERE id=? AND role='Admin' AND active=1" . $lockSuffix);
        $authorization->execute([$actorId]);
        if (!$authorization->fetchColumn()) {
            throw new DomainException('An active administrator is required for a manual export state change.');
        }
    }
    $event = $pdo->prepare(
        'INSERT INTO qb_export_events
         (export_id, event_type, from_status, to_status, actor_id, request_id, diagnostic_code)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $event->execute([$exportId, $eventType, $fromStatus, $toStatus, $actorId, $requestId, $diagnosticCode]);
}

function qb_export_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function qb_export_revalidate_record(PDO $pdo, string $exportId): array
{
    $query = $pdo->prepare(
        'SELECT transaction_type, source_transaction_id, source_revision, snapshot_json, status
         FROM qb_exports WHERE export_id=?'
    );
    $query->execute([$exportId]);
    $record = $query->fetch();
    if (!$record) {
        return ['current' => false, 'code' => 'EXPORT_NOT_FOUND', 'message' => 'The export record does not exist.'];
    }
    if ($record['status'] !== 'pending') {
        return ['current' => false, 'code' => 'EXPORT_NOT_PENDING', 'message' => 'Only pending export records can be revalidated.'];
    }

    $snapshot = json_decode((string)$record['snapshot_json'], true);
    $invoiceMode = is_array($snapshot) && isset($snapshot['preparation_mode'])
        ? $snapshot['preparation_mode']
        : 'legacy_mapped';
    $validation = qb_export_validate(
        $pdo,
        $record['transaction_type'],
        (int)$record['source_transaction_id'],
        $exportId,
        $invoiceMode
    );
    if (!hash_equals((string)$record['source_revision'], (string)$validation['source_revision'])) {
        return ['current' => false, 'code' => 'SOURCE_CHANGED', 'message' => 'The source transaction or mappings changed after the export snapshot was created.'];
    }
    if (!$validation['valid']) {
        return [
            'current' => false,
            'code' => 'SOURCE_NOT_VALID',
            'message' => 'Current source data or mappings have blocking validation errors.',
            'errors' => $validation['errors'],
        ];
    }
    return ['current' => true, 'transaction' => $validation['transaction']];
}

function qb_export_reference_preflight(PDO $pdo, string $exportId, QuickBooksBridgeClient $bridge): array
{
    if (!preg_match('/^[a-f0-9-]{36}$/iD', $exportId)) {
        return ['ready' => false, 'errors' => [['code' => 'INVALID_EXPORT_ID', 'message' => 'The export identifier is invalid.']]];
    }
    $query = $pdo->prepare(
        'SELECT transaction_type,source_transaction_id,source_revision,snapshot_json,status
         FROM qb_exports WHERE export_id=?'
    );
    $query->execute([$exportId]);
    $record = $query->fetch();
    if (!$record) {
        return ['ready' => false, 'errors' => [['code' => 'EXPORT_NOT_FOUND', 'message' => 'The saved export does not exist.']]];
    }
    if ($record['transaction_type'] !== 'invoice' || $record['status'] !== 'pending') {
        return ['ready' => false, 'errors' => [['code' => 'EXPORT_NOT_PENDING', 'message' => 'Only pending invoice previews can be checked.']]];
    }
    try {
        $snapshot = json_decode((string)$record['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ready' => false, 'errors' => [['code' => 'SNAPSHOT_INVALID', 'message' => 'The immutable invoice snapshot is invalid.']]];
    }
    if (
        !is_array($snapshot)
        || ($snapshot['transaction_type'] ?? null) !== 'invoice'
        || (int)($snapshot['source_id'] ?? 0) !== (int)$record['source_transaction_id']
        || !preg_match('/^[a-f0-9]{64}$/D', (string)$record['source_revision'])
    ) {
        return ['ready' => false, 'errors' => [['code' => 'SNAPSHOT_INVALID', 'message' => 'The immutable invoice snapshot does not match its export record.']]];
    }
    $invoiceMode = $snapshot['preparation_mode'] ?? 'legacy_mapped';
    if (!in_array($invoiceMode, ['quickbooks_managed_defaults', 'legacy_mapped'], true)) {
        return ['ready' => false, 'errors' => [['code' => 'SNAPSHOT_INVALID', 'message' => 'The invoice snapshot has an unsupported preparation mode.']]];
    }

    $validation = qb_export_validate(
        $pdo,
        'invoice',
        (int)$record['source_transaction_id'],
        $exportId,
        $invoiceMode
    );
    if (
        !$validation['valid']
        || !hash_equals((string)$record['source_revision'], (string)$validation['source_revision'])
        || $snapshot !== $validation['transaction']
    ) {
        $errors = $validation['errors'] ?? [];
        if (!$errors) {
            $errors[] = ['code' => 'SOURCE_CHANGED', 'path' => 'source', 'message' => 'The saved invoice or approved mappings changed after the preview was created.'];
        }
        return ['ready' => false, 'errors' => $errors];
    }

    try {
        $transaction = qb_export_reference_preflight_transaction($snapshot);
    } catch (Throwable $error) {
        return ['ready' => false, 'errors' => [['code' => 'SNAPSHOT_INVALID', 'message' => $error->getMessage()]]];
    }
    $preflight = $bridge->preflightInvoice($transaction, $transaction['preparationMode']);
    $errors = is_array($preflight['errors'] ?? null) ? $preflight['errors'] : [];
    return [
        'ready' => ($preflight['ready'] ?? false) === true && $errors === [],
        'errors' => $errors,
        'snapshot' => $snapshot,
    ];
}
