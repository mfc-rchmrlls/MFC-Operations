<?php

declare(strict_types=1);
require_once __DIR__ . '/../backend/core/quickbooks_exports.php';
require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';
require_once __DIR__ . '/../backend/core/functions.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function test_has_error(array $result, string $code): bool
{
    foreach ($result['errors'] as $error) {
        if ($error['code'] === $code) {
            return true;
        }
    }
    return false;
}

function test_add_verification(PDO $pdo, string $type, string $key, string $listId): void
{
    $record = [
        'reference_type' => $type,
        'local_key' => $key,
        'qb_list_id' => $listId,
        'qb_full_name' => 'Verified test display name',
        'active' => 1,
        'company_file_fingerprint' => str_repeat('b', 64),
        'diagnostic_request_id' => qb_export_uuid(),
        'verified_at' => '2020-01-01 12:00:00',
        'verified_by' => 1,
    ];
    $record['evidence_hmac'] = qb_export_verification_signature($record, str_repeat('v', 40));
    $insert = $pdo->prepare(
        'INSERT INTO qb_export_reference_verifications
         (reference_type,local_key,qb_list_id,qb_full_name,verified_at,verified_by,
          company_file_fingerprint,diagnostic_request_id,evidence_hmac,active)
         VALUES (?,?,?,?,?,?,?,?,?,1)'
    );
    $insert->execute([
        $record['reference_type'], $record['local_key'], $record['qb_list_id'],
        'Verified test display name', $record['verified_at'], $record['verified_by'],
        $record['company_file_fingerprint'], $record['diagnostic_request_id'],
        $record['evidence_hmac'],
    ]);
}

function test_signed_response(int $status, string $body, string $requestId, string $secret, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $decoded = json_decode($body, true);
    if (
        is_array($decoded)
        && ($decoded['status'] ?? null) === 'ok'
        && ($decoded['companyIdentityVerified'] ?? null) === true
        && is_string($decoded['companyFingerprint'] ?? null)
        && !array_key_exists('companyIdentity', $decoded)
    ) {
        $decoded['companyIdentity'] = [
            'companyName' => 'Verified Test Company',
            'legalCompanyName' => 'Verified Test Company LLC',
        ];
        $body = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    return [
        'status' => $status,
        'body' => $body,
        'headers' => [
            'x-mfc-timestamp' => (string)$timestamp,
            'x-mfc-signature' => hash_hmac(
                'sha256',
                $timestamp . "\nresponse\n" . $requestId . "\n" . $status . "\n" . hash('sha256', $body),
                $secret
            ),
        ],
    ];
}

function test_database(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, client_name TEXT, qb_list_id TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, product_name TEXT, qb_list_id TEXT, qb_code TEXT, item_code TEXT, qb_description TEXT, description TEXT, u_m TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY, site_name TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE withdrawals (id INTEGER PRIMARY KEY, withdrawal_no TEXT)');
    $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, withdrawal_id INTEGER, customer_id INTEGER, invoice_number TEXT, invoice_date TEXT, po_no TEXT, memo TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, withdrawal_item_id INTEGER, source_type TEXT, product_id INTEGER, site_id INTEGER, product_name TEXT, quantity INTEGER, lot_number TEXT, item_code TEXT, description TEXT, u_m TEXT, price_each TEXT, amount TEXT, price_source TEXT, tax_code TEXT, expiration_date TEXT)');
    $pdo->exec('CREATE TABLE sales_orders (id INTEGER PRIMARY KEY, withdrawal_id INTEGER, customer_id INTEGER, qb_customer_list_id TEXT, so_number TEXT, sales_order_date TEXT, po_no TEXT, memo TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE sales_order_items (id INTEGER PRIMARY KEY, sales_order_id INTEGER, line_no INTEGER, product_id INTEGER, site_id INTEGER, item_name TEXT, ordered_quantity INTEGER, lot_number TEXT, item_code TEXT, description TEXT, u_m TEXT, rate TEXT, amount TEXT, tax_code TEXT, expiration_date TEXT, class_name TEXT, line_status TEXT, fulfilled_quantity INTEGER, qb_item_list_id TEXT)');
    $pdo->exec('CREATE TABLE qb_jobs (id INTEGER PRIMARY KEY, invoice_id INTEGER)');
    $pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY, role TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE qb_export_reference_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT, reference_type TEXT, local_key TEXT, qb_list_id TEXT, qb_full_name TEXT, verified_at TEXT, verified_by INTEGER, company_file_fingerprint TEXT, diagnostic_request_id TEXT, evidence_hmac TEXT, active INTEGER, UNIQUE(reference_type, local_key), UNIQUE(reference_type, qb_list_id))');
    $pdo->exec('CREATE TABLE qb_exports (export_id TEXT PRIMARY KEY, transaction_type TEXT, source_transaction_id INTEGER, source_revision TEXT, snapshot_json TEXT, status TEXT, attempt_count INTEGER DEFAULT 0, created_by INTEGER, qb_txn_id TEXT, qb_ref_number TEXT, claim_token TEXT, lease_until TEXT, updated_at TEXT, completed_at TEXT, UNIQUE(transaction_type, source_transaction_id))');
    $pdo->exec('CREATE TABLE qb_export_attempts (export_id TEXT, attempt_number INTEGER, request_id TEXT UNIQUE, outcome TEXT, started_at TEXT, completed_at TEXT, diagnostic_code TEXT, diagnostic_message TEXT, UNIQUE(export_id, attempt_number))');
    $pdo->exec('CREATE TABLE qb_export_events (export_id TEXT, event_type TEXT, from_status TEXT, to_status TEXT, actor_id INTEGER, request_id TEXT, diagnostic_code TEXT)');
    $pdo->exec('CREATE TABLE activity_logs (employee_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address TEXT, created_at TEXT)');

    $pdo->exec("INSERT INTO clients VALUES (1,'Example Customer','CUST-LIST-1',1)");
    $pdo->exec("INSERT INTO clients VALUES (2,'Second Customer',NULL,1)");
    $pdo->exec("INSERT INTO products VALUES (1,'Example Product','ITEM-LIST-1','ITEM-CODE-1','ITEM-CODE-1','QB description','Product description','BOX',1)");
    $pdo->exec("INSERT INTO sites VALUES (1,'Example Site',1)");
    $pdo->exec("INSERT INTO withdrawals VALUES (1,'W-1')");
    $pdo->exec("INSERT INTO invoices VALUES (1,1,1,'INV-1','2026-10-23','PO-1',NULL,'Draft')");
    $pdo->exec("INSERT INTO invoice_items VALUES (1,1,NULL,'WITHDRAWAL',1,1,'Example Product',2,'LOT-1','ITEM-CODE-1','Approved description','BOX','10.00','20.00','mfc_entered','Tax','2026-12-31')");
    $pdo->exec('INSERT INTO employees VALUES (2,"Employee",1)');
    $pdo->exec("INSERT INTO sales_orders VALUES (1,1,1,'CUST-LIST-1','SO-1','2026-10-23','PO-1',NULL,'Draft')");
    $pdo->exec("INSERT INTO sales_order_items VALUES (1,1,1,1,1,'Example Product',2,'','ITEM-CODE-1','Approved description','', '10.00','20.00','Tax',NULL,NULL,NULL,NULL,'ITEM-LIST-1')");
    $pdo->exec("INSERT INTO employees VALUES (1,'Admin',1)");
    return $pdo;
}

try {
    putenv('WQ_QB_VERIFICATION_SECRET=' . str_repeat('v', 40));
    putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('b', 64));
    $pdo = test_database();

    test_assert(product_codes_match(' CODE-1 ', 'code-1'), 'Item Code and QB Code match after the established trim/case-insensitive comparison');
    test_assert(product_codes_match(null, null) && product_codes_match('', ''), 'matching missing or blank code pairs remain equivalent');
    test_assert(!product_codes_match(null, ''), 'null and blank aliases are distinguished for conflict review');
    test_assert(!product_codes_match('CODE-1', 'CODE-2'), 'different nonblank product codes are a conflict');

    test_assert(qb_export_is_authorized(['role' => 'Admin']), 'administrator can manage export previews');
    test_assert(!qb_export_is_authorized(['role' => 'Employee']), 'employee cannot manage export previews');
    test_assert(csrf_token_matches('session-token', 'session-token'), 'CSRF validation accepts the current token');
    test_assert(!csrf_token_matches('session-token', 'attacker-token'), 'CSRF validation rejects a mismatched token');
    test_assert(!csrf_token_matches('', ''), 'CSRF validation rejects an absent session token');

    $invoice = qb_export_validate($pdo, 'invoice', 1);
    test_assert(!$invoice['valid'] && $invoice['transaction']['transaction_type'] === 'invoice', 'invoice preview is explicitly typed');
    test_assert(test_has_error($invoice, 'CUSTOMER_MAPPING_UNVERIFIED'), 'unverified stored customer ListID cannot fall back to a name');
    test_assert(test_has_error($invoice, 'ITEM_MAPPING_UNVERIFIED'), 'unverified stored item ListID cannot fall back to a name');
    test_assert(!test_has_error($invoice, 'TAX_MAPPING_UNVERIFIED')
        && $invoice['transaction']['preparation_mode'] === 'quickbooks_managed_defaults',
        'invoice managed-defaults mode does not require per-line tax mappings');
    test_assert(!test_has_error($invoice, 'SITE_DESTINATION_UNVERIFIED')
        && $invoice['transaction']['lines'][0]['site_mapping_status'] === 'unresolved',
        'invoice Site references remain pending exact live-company verification');
    test_assert(!test_has_error($invoice, 'LOT_DESTINATION_UNVERIFIED'), 'invoice lot references are gated by live QuickBooks lot-tracking verification');
    test_assert(test_has_error($invoice, 'EXPIRATION_DESTINATION_UNVERIFIED'), 'unresolved expiration field blocks export');
    test_assert(!test_has_error($invoice, 'ITEM_CODE_DESTINATION_UNVERIFIED'), 'invoice Item Code remains local-only and is not required as an InvoiceAdd field');
    test_assert(!test_has_error($invoice, 'TAX_TOTAL_UNRESOLVED'), 'QuickBooks-calculated sales tax is gated by verified tax codes and company preferences');
    test_assert(test_has_error($invoice, 'LEGACY_JOB_EXISTS') === false, 'invoice export is independent when no legacy qb_jobs record exists');
    test_assert($invoice['transaction']['expected_subtotal'] === '20.00', 'expected invoice subtotal is calculated from quantity and rate');
    $pdo->exec("UPDATE invoice_items SET quantity=10,price_each='150.00',amount='1500.00' WHERE id=1");
    $enteredPrice = qb_export_validate($pdo, 'invoice', 1);
    test_assert($enteredPrice['transaction']['lines'][0]['rate'] === '150.00'
        && $enteredPrice['transaction']['lines'][0]['expected_amount'] === '1500.00'
        && $enteredPrice['transaction']['lines'][0]['pricing_status'] === 'MFC-entered price',
        'an explicit MFC price takes priority and uses exact quantity-times-rate cents');
    $pdo->exec("UPDATE invoice_items SET quantity=2,price_each='10.00',amount='20.00' WHERE id=1");
    $pdo->exec("UPDATE invoice_items SET price_each=NULL,amount=NULL,price_source='quickbooks_pending' WHERE id=1");
    $pendingPrice = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($pendingPrice, 'PRICE_UNRESOLVED')
        && $pendingPrice['transaction']['expected_subtotal'] === 'unresolved pricing'
        && !test_has_error($pendingPrice, 'ZERO_RATE_UNAPPROVED'),
        'a blank price remains distinct from zero and blocks queueing');
    $pdo->exec("UPDATE invoice_items SET price_each='10.00',amount='20.00',price_source='mfc_entered' WHERE id=1");
    $pdo->exec("UPDATE invoice_items SET price_each='0.00',amount='0.00',price_source='explicit_zero' WHERE id=1");
    $explicitZero = qb_export_validate($pdo, 'invoice', 1);
    test_assert(!test_has_error($explicitZero, 'ZERO_RATE_UNAPPROVED')
        && !test_has_error($explicitZero, 'PRICE_UNRESOLVED')
        && $explicitZero['transaction']['lines'][0]['rate'] === '0.00'
        && $explicitZero['transaction']['lines'][0]['expected_amount'] === '0.00'
        && $explicitZero['transaction']['lines'][0]['pricing_status'] === 'Explicit zero — valid'
        && $explicitZero['transaction']['expected_subtotal'] === '0.00',
        'an explicit zero-priced invoice line is valid and retains a zero rate and amount');
    $pdo->exec("UPDATE invoice_items SET price_source='mfc_entered' WHERE id=1");
    $legacyZero = qb_export_validate($pdo, 'invoice', 1);
    test_assert(!test_has_error($legacyZero, 'ZERO_RATE_UNAPPROVED')
        && $legacyZero['transaction']['lines'][0]['rate'] === '0.00'
        && $legacyZero['transaction']['lines'][0]['expected_amount'] === '0.00',
        'a saved zero rate tagged as MFC-entered remains exportable for legacy compatibility');
    $pdo->exec("UPDATE invoice_items SET price_source='explicit_zero' WHERE id=1");
    $pdo->exec("INSERT INTO invoice_items VALUES (2,1,NULL,'ADDITIONAL',1,1,'Example Product',20,'','ITEM-CODE-1','Approved description','BOX','0.00','0.00','explicit_zero','Tax',NULL)");
    $multipleZero = qb_export_validate($pdo, 'invoice', 1);
    test_assert(count($multipleZero['transaction']['lines']) === 2
        && $multipleZero['transaction']['expected_subtotal'] === '0.00'
        && array_column($multipleZero['transaction']['lines'], 'expected_amount') === ['0.00', '0.00'],
        'multiple zero-priced invoice lines remain present in their original order with zero amounts');
    $pdo->exec("UPDATE invoice_items SET price_each='150.00',amount='3000.00',price_source='mfc_entered' WHERE id=2");
    $mixedPrices = qb_export_validate($pdo, 'invoice', 1);
    test_assert($mixedPrices['transaction']['expected_subtotal'] === '3000.00'
        && array_column($mixedPrices['transaction']['lines'], 'rate') === ['0.00', '150.00']
        && array_column($mixedPrices['transaction']['lines'], 'expected_amount') === ['0.00', '3000.00'],
        'mixed zero and positive invoice rates preserve line order and reconcile their amounts');
    $pdo->exec('DELETE FROM invoice_items WHERE id=2');
    $pdo->exec("UPDATE invoice_items SET price_each='0.00',amount='0.00',price_source='unresolved' WHERE id=1");
    $historicalZero = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($historicalZero, 'PRICE_SOURCE_UNRESOLVED')
        && $historicalZero['transaction']['lines'][0]['rate'] === '0.00'
        && $historicalZero['transaction']['lines'][0]['saved_amount'] === '0.00'
        && !test_has_error($historicalZero, 'ZERO_RATE_UNAPPROVED'),
        'a historical unresolved zero is preserved but blocked until intent is explicitly confirmed');
    $pdo->exec("UPDATE invoice_items SET price_each='10.00',amount='20.00',price_source='mfc_entered' WHERE id=1");
    test_assert($invoice['transaction']['tax_total'] === null
        && $invoice['transaction']['expected_total'] === null
        && str_contains($invoice['transaction']['tax_total_status'], 'live verification'),
        'invoice tax defaults remain explicitly unverified until live behavior is checked');
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1, null, 'legacy_mapped'), 'TAX_MAPPING_UNVERIFIED'),
        'legacy invoice mode continues to require its approved tax mapping');
    $pdo->exec('UPDATE clients SET qb_list_id=NULL WHERE id=1');
    $pdo->exec('UPDATE products SET qb_list_id=NULL WHERE id=1');
    $fullnamePreview = qb_export_validate($pdo, 'invoice', 1);
    test_assert($fullnamePreview['transaction']['customer_full_name'] === 'Example Customer'
        && $fullnamePreview['transaction']['customer_list_id'] === null
        && $fullnamePreview['transaction']['lines'][0]['item_full_name'] === 'ITEM-CODE-1'
        && $fullnamePreview['transaction']['lines'][0]['item_list_id'] === null,
        'invoice snapshots keep missing-ListID customer and item FullName candidates distinct from ListIDs');
    $pdo->exec("UPDATE invoice_items SET item_code='' WHERE id=1");
    $pdo->exec("UPDATE products SET qb_code='' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'ITEM_MAPPING_UNVERIFIED'),
        'a local product description is not substituted for a missing Item Code FullName candidate');
    $pdo->exec("UPDATE products SET qb_code='ITEM-CODE-1' WHERE id=1");
    $currentCodePreview = qb_export_validate($pdo, 'invoice', 1);
    test_assert(($currentCodePreview['transaction']['lines'][0]['item_full_name'] ?? null) === 'ITEM-CODE-1',
        'the current Product Master QB Code is used only when the saved invoice Item Code is blank');
    $pdo->exec("UPDATE invoice_items SET item_code='ITEM-CODE-1' WHERE id=1");
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-1' WHERE id=1");
    $pdo->exec("UPDATE products SET qb_list_id='ITEM-LIST-1' WHERE id=1");

    $pdo->prepare('UPDATE invoices SET invoice_number=? WHERE id=1')->execute([str_repeat('R', 12)]);
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'REFERENCE_NUMBER_TOO_LONG'),
        'invoice references exceeding the QBSDK 16.0 11-character RefNumber limit are blocked, not truncated');
    $pdo->exec("UPDATE invoices SET invoice_number='INV-1' WHERE id=1");
    $pdo->prepare('UPDATE invoices SET po_no=? WHERE id=1')->execute([str_repeat('P', 26)]);
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'PO_NUMBER_TOO_LONG'),
        'invoice P.O. numbers exceeding the SDK limit are blocked');
    $pdo->exec("UPDATE invoices SET po_no='PO-1' WHERE id=1");
    $pdo->prepare('UPDATE invoices SET memo=? WHERE id=1')->execute([str_repeat('M', 4096)]);
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'MEMO_TOO_LONG'),
        'invoice memos exceeding the SDK limit are blocked');
    $pdo->exec('UPDATE invoices SET memo=NULL WHERE id=1');
    $pdo->prepare('UPDATE sites SET site_name=? WHERE id=1')->execute([str_repeat('S', 32)]);
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'SITE_NAME_TOO_LONG'),
        'inventory site references exceeding the SDK FullName limit are blocked');
    $pdo->exec("UPDATE sites SET site_name='Example Site' WHERE id=1");
    $pdo->prepare('UPDATE invoice_items SET u_m=? WHERE id=1')->execute([str_repeat('U', 32)]);
    test_assert(!test_has_error(qb_export_validate($pdo, 'invoice', 1), 'UNIT_OF_MEASURE_TOO_LONG'),
        'locally maintained invoice U/M values do not block preflight, regardless of length');
    $pdo->exec("UPDATE invoice_items SET u_m='BOX' WHERE id=1");
    $pdo->prepare('UPDATE invoice_items SET lot_number=? WHERE id=1')->execute([str_repeat('L', 41)]);
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'LOT_NUMBER_TOO_LONG'),
        'lot numbers exceeding the SDK 40-character limit are blocked without truncation');
    $pdo->exec("UPDATE invoice_items SET lot_number='LOT-1' WHERE id=1");

    $bridgeRequestRejected = false;
    try {
        qb_export_bridge_request($invoice);
    } catch (DomainException) {
        $bridgeRequestRejected = true;
    }
    test_assert($bridgeRequestRejected, 'unresolved preview data cannot be silently dropped into a bridge request');

    $pdo->exec("UPDATE clients SET active=0 WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'CUSTOMER_INACTIVE'), 'inactive local customer blocks export');
    $pdo->exec("UPDATE clients SET active=1 WHERE id=1");
    $pdo->exec("UPDATE invoice_items SET tax_code='Other' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'INVALID_LOCAL_TAX_CODE'), 'unsupported local tax labels are rejected');
    $pdo->exec("UPDATE invoice_items SET tax_code='Exempt' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'INVALID_LOCAL_TAX_CODE'), 'Exempt is rejected because it is not an invoice schema or UI value');
    $pdo->exec("UPDATE invoice_items SET tax_code='Tax' WHERE id=1");
    $pdo->exec("UPDATE sales_order_items SET tax_code='Exempt' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'sales_order', 1), 'INVALID_LOCAL_TAX_CODE'), 'legacy Exempt sales-order values are preserved but blocked');
    $pdo->exec("UPDATE sales_order_items SET tax_code='Tax' WHERE id=1");

    $pdo->exec(
        "INSERT INTO qb_export_reference_verifications
         (reference_type,local_key,qb_list_id,qb_full_name,verified_at,verified_by,
          company_file_fingerprint,diagnostic_request_id,evidence_hmac,active)
         VALUES('customer','client:1','CUST-LIST-1','Example Customer','2020-01-01 12:00:00',
          1,'" . str_repeat('b', 64) . "','" . qb_export_uuid() . "',NULL,1)"
    );
    $unsignedMappingPreview = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($unsignedMappingPreview, 'CUSTOMER_MAPPING_UNVERIFIED')
        && $unsignedMappingPreview['transaction']['customer_list_id'] === null
        && !isset($unsignedMappingPreview['transaction']['customer_full_name']),
        'an unsigned arbitrary verification row cannot approve a stored ListID or silently switch it to FullName mode');
    $pdo->exec('DELETE FROM qb_export_reference_verifications');
    test_add_verification($pdo, 'customer', 'client:1', 'CUST-LIST-1');
    test_add_verification($pdo, 'item', 'product:1', 'ITEM-LIST-1');
    test_add_verification($pdo, 'tax_code', 'tax:Tax', 'TAX-LIST-1');
    $verifiedPreview = qb_export_validate($pdo, 'invoice', 1);
    test_assert(!test_has_error($verifiedPreview, 'CUSTOMER_MAPPING_UNVERIFIED')
        && !test_has_error($verifiedPreview, 'ITEM_MAPPING_UNVERIFIED')
        && !test_has_error($verifiedPreview, 'TAX_MAPPING_UNVERIFIED'), 'signed evidence from an active Admin approves exact customer, item, and tax references');
    putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('c', 64));
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'CUSTOMER_MAPPING_UNVERIFIED'), 'a verification for another QuickBooks company fingerprint is stale');
    putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('b', 64));
    $pdo->exec('UPDATE employees SET active=0 WHERE id=1');
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'ITEM_MAPPING_UNVERIFIED'), 'inactive Admin approvers cannot authorize mappings');
    $pdo->exec('UPDATE employees SET active=1 WHERE id=1');

    $pdo->exec("UPDATE qb_export_reference_verifications SET qb_list_id='ITEM-LIST-OTHER' WHERE reference_type='item'");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'ITEM_MAPPING_UNVERIFIED'), 'modified verification records fail their evidence signature');
    $pdo->exec("UPDATE qb_export_reference_verifications SET qb_list_id='ITEM-LIST-1' WHERE reference_type='item'");
    $mappingRevision = qb_export_validate($pdo, 'invoice', 1)['source_revision'];
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-2' WHERE id=1");
    $changedCustomerMapping = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($changedCustomerMapping, 'CUSTOMER_MAPPING_UNVERIFIED')
        && $changedCustomerMapping['source_revision'] !== $mappingRevision, 'changed customer ListID invalidates verification and changes the source revision');
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-1' WHERE id=1");
    $productRevision = qb_export_validate($pdo, 'invoice', 1)['source_revision'];
    $pdo->exec("UPDATE products SET qb_list_id='ITEM-LIST-2' WHERE id=1");
    $changedItemMapping = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($changedItemMapping, 'ITEM_MAPPING_UNVERIFIED')
        && $changedItemMapping['source_revision'] !== $productRevision, 'changed product ListID invalidates verification and changes the source revision');
    $pdo->exec("UPDATE products SET qb_list_id='ITEM-LIST-1',qb_description='Updated QB description' WHERE id=1");
    $updatedProductDescription = qb_export_validate($pdo, 'invoice', 1);
    test_assert($updatedProductDescription['source_revision'] !== $productRevision, 'current product descriptions are included in the source revision');
    $pdo->exec("UPDATE products SET qb_description='QB description' WHERE id=1");

    $unauthorizedQueueCreation = false;
    try {
        qb_export_create($pdo, 'invoice', 1, $verifiedPreview['source_revision'], 2);
    } catch (DomainException) {
        $unauthorizedQueueCreation = true;
    }
    test_assert($unauthorizedQueueCreation, 'server-side queue creation rejects non-Admin users');
    test_assert((int)$pdo->query('SELECT COUNT(*) FROM qb_exports')->fetchColumn() === 0, 'unauthorized queue creation writes no export');
    $pdo->exec('INSERT INTO qb_jobs(invoice_id) VALUES(1)');
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'LEGACY_JOB_EXISTS'), 'legacy invoice qb_jobs records continue to block duplicate typed export');
    $pdo->exec('DELETE FROM qb_jobs WHERE invoice_id=1');
    $pdo->exec("UPDATE invoice_items SET tax_code='Tax' WHERE id=1");
    $pdo->exec("UPDATE invoice_items SET quantity=999999999, price_each='9999999999.99', amount='999999999.00' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'AMOUNT_OUT_OF_RANGE'), 'calculated line amount overflow is rejected');
    $pdo->exec("UPDATE invoice_items SET quantity=2, price_each='10.00', amount='20.00' WHERE id=1");

    $order = qb_export_validate($pdo, 'sales_order', 1);
    test_assert($order['transaction']['transaction_type'] === 'sales_order', 'sales-order preview remains separate from invoices');
    test_assert(!test_has_error($order, 'CUSTOMER_MAPPING_UNVERIFIED'), 'sales-order customer mapping is verified independently');
    $pdo->exec("UPDATE sales_order_items SET class_name='LOCAL-CLASS' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'sales_order', 1), 'CLASS_DESTINATION_UNVERIFIED'), 'unresolved sales-order class values block export');
    $pdo->exec("UPDATE sales_order_items SET class_name=NULL,line_status='Open' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'sales_order', 1), 'LINE_STATUS_DESTINATION_UNVERIFIED'), 'unresolved sales-order line status values block export');
    $pdo->exec("UPDATE sales_order_items SET line_status=NULL,fulfilled_quantity=1 WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'sales_order', 1), 'FULFILLED_QUANTITY_DESTINATION_UNVERIFIED'), 'unresolved fulfilled quantity values block export');
    $pdo->exec('UPDATE sales_order_items SET fulfilled_quantity=NULL WHERE id=1');

    $pdo->exec("UPDATE invoice_items SET amount='21.00' WHERE id=1");
    $invalidAmount = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($invalidAmount, 'AMOUNT_MISMATCH'), 'invalid saved amount is rejected');
    $pdo->exec("UPDATE invoice_items SET amount='20.00' WHERE id=1");

    $oldRevision = qb_export_validate($pdo, 'invoice', 1)['source_revision'];
    $uuid = qb_export_uuid();
    $insert = $pdo->prepare('INSERT INTO qb_exports (export_id,transaction_type,source_transaction_id,source_revision,snapshot_json,status,created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$uuid, 'invoice', 1, $oldRevision, '{}', 'pending', 1]);
    $pendingRecheck = qb_export_revalidate_record($pdo, $uuid);
    $duplicateErrorReturned = false;
    foreach ($pendingRecheck['errors'] ?? [] as $error) {
        if ($error['code'] === 'EXPORT_ALREADY_EXISTS') $duplicateErrorReturned = true;
    }
    test_assert(!$duplicateErrorReturned, 'revalidation excludes the export record being revalidated');
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-CHANGED' WHERE id=1");
    test_assert(qb_export_revalidate_record($pdo, $uuid)['code'] === 'SOURCE_CHANGED', 'customer mapping changes after snapshot require reconciliation');
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-1' WHERE id=1");
    $pdo->exec("UPDATE invoice_items SET quantity=3,amount='30.00' WHERE id=1");
    test_assert(qb_export_revalidate_record($pdo, $uuid)['code'] === 'SOURCE_CHANGED', 'line quantity and amount changes after snapshot require reconciliation');
    $pdo->exec("UPDATE invoice_items SET quantity=2,amount='20.00' WHERE id=1");
    $duplicate = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($duplicate, 'EXPORT_ALREADY_EXISTS'), 'duplicate source transaction is blocked by application validation');
    try {
        $insert->execute([qb_export_uuid(), 'invoice', 1, $oldRevision, '{}', 'pending', 1]);
        test_assert(false, 'database unique constraint prevents duplicate source export');
    } catch (PDOException) {
        test_assert(true, 'database unique constraint prevents duplicate source export');
    }

    $pdo->exec("UPDATE invoices SET status='Draft' WHERE id=1");
    $oldRevision = qb_export_validate($pdo, 'invoice', 1)['source_revision'];
    $pdo->prepare('UPDATE qb_exports SET source_revision=? WHERE export_id=?')->execute([$oldRevision, $uuid]);
    $pdo->exec("UPDATE invoices SET po_no='PO-CHANGED' WHERE id=1");
    $changed = qb_export_revalidate_record($pdo, $uuid);
    test_assert($changed['code'] === 'SOURCE_CHANGED', 'source changes after snapshot require revalidation');
    test_assert(!function_exists('qb_export_claim') && !function_exists('qb_export_transition'),
        'posting claim and status-transition services are structurally absent');

    $unconfigured = (new QuickBooksBridgeClient('http://127.0.0.1:8765', null))->health();
    test_assert(!$unconfigured['available'] && $unconfigured['diagnostic_code'] === 'BRIDGE_NOT_CONFIGURED', 'missing bridge secret is reported unavailable');
    $secret = str_repeat('s', 40);
    $authFailure = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'error',
                'diagnosticCode' => 'AUTHENTICATION_FAILED',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(401, $responseBody, $request['requestId'], $secret);
        }
    );
    $authResult = $authFailure->health();
    test_assert($authResult['diagnostic_code'] === 'AUTHENTICATION_FAILED', 'signed bridge authentication failure is sanitized and explicit');

    $callerRequestId = qb_export_uuid();
    $sentRequestId = '';
    $requestSignatureValid = false;
    $validationClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body, array $headers) use ($secret, &$sentRequestId, &$requestSignatureValid): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $sentRequestId = $request['requestId'];
            $headerMap = [];
            foreach ($headers as $header) {
                [$name, $value] = explode(':', $header, 2);
                $headerMap[strtolower($name)] = trim($value);
            }
            $expectedRequestSignature = hash_hmac(
                'sha256',
                $headerMap['x-mfc-timestamp'] . "\nvalidate\n" . $sentRequestId . "\n" . hash('sha256', $body),
                $secret
            );
            $requestSignatureValid = hash_equals($expectedRequestSignature, $headerMap['x-mfc-signature']);
            $responseBody = json_encode(['requestId' => $request['requestId'], 'valid' => false], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    $validationResult = $validationClient->validate([
        'protocolVersion' => '1',
        'requestId' => $callerRequestId,
        'operation' => 'validate',
        'validationOnly' => true,
        'idempotencyKey' => null,
        'transactionType' => 'invoice',
        'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
        'transaction' => [
            'customer' => ['listId' => 'CUST-LIST-1'],
            'txnDate' => '2026-10-23',
            'refNumber' => 'INV-1',
            'poNumber' => null,
            'memo' => null,
            'lines' => [['item' => ['listId' => 'ITEM-LIST-1'], 'description' => 'Item', 'quantity' => '2', 'rate' => '10.00', 'expectedAmount' => '20.00', 'salesTaxCode' => ['listId' => 'TAX-LIST-1']]],
            'expectedSubtotal' => '20.00',
            'expectedTax' => null,
            'expectedTotal' => null,
        ],
    ]);
    test_assert($validationResult['available'] && $validationResult['response']['valid'] === false, 'bridge validation-only requests return a matched response without posting');
    test_assert($sentRequestId === $callerRequestId && $validationResult['request_id'] === $sentRequestId, 'bridge preserves the request ID for signed request and response correlation');
    test_assert($requestSignatureValid, 'bridge request HMAC covers timestamp, operation, request ID, and body digest');

    $validValidationRequest = [
        'protocolVersion' => '1',
        'requestId' => qb_export_uuid(),
        'operation' => 'validate',
        'validationOnly' => true,
        'idempotencyKey' => null,
        'transactionType' => 'invoice',
        'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
        'transaction' => [
            'customer' => ['listId' => 'CUST-LIST-1'],
            'txnDate' => '2026-10-23',
            'refNumber' => 'INV-VALID',
            'poNumber' => null,
            'memo' => null,
            'lines' => [[
                'item' => ['listId' => 'ITEM-LIST-1'],
                'description' => 'Validation line',
                'quantity' => '2',
                'rate' => '10.00',
                'expectedAmount' => '20.00',
                'salesTaxCode' => ['listId' => 'TAX-LIST-1'],
            ]],
            'expectedSubtotal' => '20.00',
            'expectedTax' => null,
            'expectedTotal' => null,
        ],
    ];
    $validValidationResult = $validationClient->validate($validValidationRequest);
    test_assert($validValidationResult['available'] && $validValidationResult['response']['valid'] === false, 'valid validation-only requests are accepted by the request schema');

    $malformedValidationRequest = $validValidationRequest;
    $malformedValidationRequest['validationOnly'] = false;
    $validationMismatchThrown = false;
    try {
        $validationClient->validate($malformedValidationRequest);
    } catch (InvalidArgumentException) {
        $validationMismatchThrown = true;
    }
    test_assert($validationMismatchThrown, 'malformed validation-only requests are rejected before transport');

    test_assert(!method_exists(QuickBooksBridgeClient::class, 'submitInvoice'),
        'PHP bridge client has no transaction-submission method');
    $writeOperationRejected = false;
    try {
        $validationClient->validate(array_replace($validValidationRequest, ['operation' => 'submit']));
    } catch (InvalidArgumentException) {
        $writeOperationRejected = true;
    }
    test_assert($writeOperationRejected, 'PHP bridge client rejects transaction-writing operations before transport');

    $diagnosticRequest = null;
    $diagnosticClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body, array $headers) use ($secret, &$diagnosticRequest): array {
            $diagnosticRequest = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $diagnosticRequest['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'records' => [['listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'active' => true, 'found' => true]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $diagnosticRequest['requestId'], $secret);
        }
    );
    $diagnosticResult = $diagnosticClient->diagnose('customer', ['CUST-LIST-1']);
    test_assert($diagnosticResult['available']
        && $diagnosticResult['response']['records'][0]['listId'] === 'CUST-LIST-1'
        && $diagnosticRequest['operation'] === 'diagnose'
        && $diagnosticRequest['listIds'] === ['CUST-LIST-1'], 'PHP diagnostic request matches the bounded bridge query contract');
    $nameLookupRequest = null;
    $nameLookupClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret, &$nameLookupRequest): array {
            $nameLookupRequest = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $nameLookupRequest['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'searchName' => 'Example Customer',
                'records' => [[
                    'listId' => 'CUST-LIST-1',
                    'name' => 'Example Customer',
                    'fullName' => 'Example Customer',
                    'companyName' => 'Example Company, Inc.',
                    'active' => true,
                    'found' => true,
                ]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $nameLookupRequest['requestId'], $secret);
        }
    );
    $nameLookupResult = $nameLookupClient->lookupName('customer', 'Example Customer');
    test_assert($nameLookupResult['available']
        && $nameLookupResult['response']['records'][0]['companyName'] === 'Example Company, Inc.'
        && $nameLookupResult['response']['records'][0]['name'] !== $nameLookupResult['response']['records'][0]['companyName'],
        'customer name lookup keeps QuickBooks Name distinct from CompanyName');
    test_assert($nameLookupRequest['searchName'] === 'Example Customer'
        && !array_key_exists('listIds', $nameLookupRequest),
        'name lookup accepts business-facing text and does not require manual ListIDs');
    $siteLookupRequest = null;
    $siteLookupClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret, &$siteLookupRequest): array {
            $siteLookupRequest = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $siteLookupRequest['requestId'],
                'status' => 'ok',
                'entity' => 'inventory_site',
                'searchName' => 'Warehouse:North',
                'records' => [[
                    'listId' => 'SITE-LIST-1',
                    'name' => 'North',
                    'fullName' => 'Warehouse:North',
                    'active' => true,
                    'found' => true,
                ]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $siteLookupRequest['requestId'], $secret);
        }
    );
    $siteLookup = $siteLookupClient->lookupInventorySiteFullName('Warehouse:North');
    test_assert($siteLookup['available']
        && $siteLookup['response']['records'][0]['fullName'] === 'Warehouse:North'
        && $siteLookupRequest['searchName'] === 'Warehouse:North'
        && !array_key_exists('listIds', $siteLookupRequest),
        'inventory-site diagnostics request an exact FullName without inventing a ListID');

    $unitDiagnosticRequest = null;
    $unitDiagnosticClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret, &$unitDiagnosticRequest): array {
            $unitDiagnosticRequest = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $unitDiagnosticRequest['requestId'],
                'status' => 'ok',
                'entity' => 'item_uom',
                'records' => [[
                    'listId' => 'ITEM-LIST-1',
                    'found' => true,
                    'itemName' => 'Example Product',
                    'active' => true,
                    'unitSetListId' => 'UOM-SET-1',
                    'unitSetName' => 'Weight',
                    'unitSetActive' => true,
                    'unitType' => 'Weight',
                    'units' => [
                        ['name' => 'Pound', 'abbreviation' => 'lb'],
                        ['name' => 'Ton', 'abbreviation' => 'tn'],
                    ],
                    'salesDefaultUnit' => 'Pound',
                ]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $unitDiagnosticRequest['requestId'], $secret);
        }
    );
    $unitDiagnostic = $unitDiagnosticClient->diagnose('item_uom', ['ITEM-LIST-1']);
    test_assert($unitDiagnostic['available']
        && $unitDiagnostic['response']['records'][0]['salesDefaultUnit'] === 'Pound'
        && $unitDiagnosticRequest['entity'] === 'item_uom'
        && $unitDiagnosticRequest['listIds'] === ['ITEM-LIST-1'],
        'item U/M diagnostics are ListID-bound and return the company unit set');

    $scanRequest = null;
    $scanClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret, &$scanRequest): array {
            $scanRequest = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $preferences = [
                'customerTaxCodeEnabled' => null,
                'vendorTaxCodeEnabled' => null,
                'amountsIncludeTax' => null,
                'defaultTaxableCode' => ['listId' => null, 'fullName' => null],
                'defaultNonTaxableCode' => ['listId' => null, 'fullName' => null],
                'defaultTaxItem' => ['listId' => null, 'fullName' => null],
                'multiLocationAvailable' => null,
                'multiLocationEnabled' => null,
                'lotTracking' => null,
                'expirationDateEnabled' => null,
                'salesTransactionTrackingEnabled' => null,
            ];
            $category = static fn(string $name, string $scope, int $limit, array $records = []): array => [
                'category' => $name,
                'scope' => $scope,
                'status' => 'complete',
                'diagnosticCode' => null,
                'recordCount' => count($records),
                'limit' => $limit,
                'records' => $records,
            ];
            $categories = [
                $category('customers', 'customer', 100),
                $category('items', 'item', 100),
                $category('inventory_sites', 'local_exact_names', 20),
                $category('mapped_item_units', 'saved_item_list_ids', 20),
                $category('saved_tax_codes', 'saved_tax_code_list_ids', 20),
                $category('company_preferences', 'company_preferences', 1, [$preferences]),
            ];
            $responseBody = json_encode([
                'requestId' => $scanRequest['requestId'],
                'status' => 'ok',
                'entity' => 'reference_scan',
                'records' => $categories,
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $scanRequest['requestId'], $secret);
        }
    );
    $scanResult = $scanClient->scanReferences(['Warehouse:North'], ['ITEM-LIST-1'], ['TAX-LIST-1']);
    test_assert($scanResult['available']
        && count($scanResult['response']['records']) === 6
        && $scanRequest['entity'] === 'reference_scan'
        && $scanRequest['siteNames'] === ['Warehouse:North']
        && $scanRequest['itemListIds'] === ['ITEM-LIST-1']
        && $scanRequest['taxCodeListIds'] === ['TAX-LIST-1'],
        'reference scan signs the bounded supported-category scopes and validates the company-bound report');
    $scanLimitRejected = false;
    try {
        $scanClient->scanReferences(array_fill(0, 21, 'Warehouse'), [], []);
    } catch (InvalidArgumentException) {
        $scanLimitRejected = true;
    }
    test_assert($scanLimitRejected, 'PHP reference scan rejects oversized exact-query scopes before transport');

    $preferencesDiagnosticClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'company_preferences',
                'records' => [[
                    'customerTaxCodeEnabled' => true,
                    'vendorTaxCodeEnabled' => false,
                    'amountsIncludeTax' => false,
                    'defaultTaxableCode' => ['listId' => 'TAX-CODE-1', 'fullName' => 'Tax'],
                    'defaultNonTaxableCode' => ['listId' => 'TAX-CODE-2', 'fullName' => 'Non'],
                    'defaultTaxItem' => ['listId' => 'TAX-ITEM-1', 'fullName' => 'Sales Tax'],
                    'multiLocationAvailable' => true,
                    'multiLocationEnabled' => true,
                    'lotTracking' => 'LotNumber',
                    'expirationDateEnabled' => true,
                    'salesTransactionTrackingEnabled' => true,
                ]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    $preferencesDiagnostic = $preferencesDiagnosticClient->diagnoseCompanyConfiguration('company_preferences');
    test_assert($preferencesDiagnostic['available']
        && $preferencesDiagnostic['response']['records'][0]['lotTracking'] === 'LotNumber'
        && $preferencesDiagnostic['response']['companyIdentity']['legalCompanyName'] === 'Verified Test Company LLC',
        'company preferences diagnostics require and expose a verified company identity');

    $diagnosticFailureClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'unavailable',
                'diagnosticCode' => 'COMPANY_SESSION_UNAVAILABLE',
                'failureStage' => 'begin_session',
                'sdkErrorCode' => 'SDK_COM_80040418',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(503, $responseBody, $request['requestId'], $secret);
        }
    );
    $diagnosticFailure = $diagnosticFailureClient->diagnoseCompanyConfiguration('company_preferences');
    test_assert(
        !$diagnosticFailure['available']
            && $diagnosticFailure['diagnostic_code'] === 'COMPANY_SESSION_UNAVAILABLE'
            && $diagnosticFailure['failure_stage'] === 'begin_session'
            && $diagnosticFailure['sdk_error_code'] === 'SDK_COM_80040418',
        'signed diagnostic failures preserve only allowlisted SDK stage and HRESULT details'
    );

    $unsafeDiagnosticFailureClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'unavailable',
                'diagnosticCode' => 'COMPANY_SESSION_UNAVAILABLE',
                'failureStage' => 'Sensitive company path',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(503, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert(
        $unsafeDiagnosticFailureClient->diagnoseCompanyConfiguration('company_preferences')['diagnostic_code']
            === 'BRIDGE_RESPONSE_MISMATCH',
        'signed diagnostic failures reject unrecognized stage values'
    );

    $missingCompanyIdentityClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'company_preferences',
                'records' => [[
                    'customerTaxCodeEnabled' => true,
                    'vendorTaxCodeEnabled' => false,
                    'amountsIncludeTax' => false,
                    'defaultTaxableCode' => ['listId' => null, 'fullName' => null],
                    'defaultNonTaxableCode' => ['listId' => null, 'fullName' => null],
                    'defaultTaxItem' => ['listId' => null, 'fullName' => null],
                    'multiLocationAvailable' => false,
                    'multiLocationEnabled' => false,
                    'lotTracking' => 'None',
                    'expirationDateEnabled' => false,
                    'salesTransactionTrackingEnabled' => false,
                ]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'companyIdentity' => null,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    $missingCompanyIdentity = $missingCompanyIdentityClient->diagnoseCompanyConfiguration('company_preferences');
    test_assert(
        !$missingCompanyIdentity['available']
            && $missingCompanyIdentity['diagnostic_code'] === 'BRIDGE_RESPONSE_MISMATCH',
        'company configuration diagnostics reject responses without verified identity fields'
    );

    $customFieldsDiagnosticClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'invoice_custom_fields',
                'records' => [[
                    'ownerId' => null,
                    'dataExtId' => '17',
                    'name' => 'Expiration Date',
                    'type' => 'DATETIMETYPE',
                    'assignToObject' => ['Invoice'],
                    'transactionRequired' => false,
                    'listRequired' => false,
                    'format' => null,
                ]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    $customFieldsDiagnostic = $customFieldsDiagnosticClient->diagnoseCompanyConfiguration('invoice_custom_fields');
    test_assert($customFieldsDiagnostic['available']
        && $customFieldsDiagnostic['response']['records'][0]['assignToObject'] === ['Invoice'],
        'invoice custom-field diagnostics expose definitions without treating them as line-level proof');
    $productLookupClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'item',
                'searchName' => 'Example Product',
                'records' => [[
                    'listId' => 'ITEM-LIST-1',
                    'name' => 'Example Product',
                    'fullName' => 'Example Product',
                    'manufacturerPartNumber' => null,
                    'salesDescription' => 'Example Product',
                    'active' => true,
                    'found' => true,
                ]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    $productLookup = $productLookupClient->lookupName('item', 'Example Product');
    test_assert($productLookup['available']
        && $productLookup['response']['records'][0]['salesDescription'] === 'Example Product',
        'product lookup returns SalesDesc separately from the QuickBooks item Name');
    test_assert(qb_export_name_candidate_status('item', ['Example Product'], $productLookup['response']['records'][0]) === 'item_code_source_unverified',
        'an exact active item-name candidate remains unverified without a proven Item Code field relationship');
    test_assert(qb_export_name_candidate_status('item', ['Example Product'], [
        'listId' => 'ITEM-LIST-1', 'name' => 'Example Product', 'fullName' => 'Example Product',
        'salesDescription' => 'Different sales text', 'active' => true,
    ]) === 'item_code_source_unverified',
        'a SalesDesc difference does not falsely verify or reject an item-code relationship');
    test_assert(qb_export_name_candidate_status('customer', ['Example Customer'], [
        'listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'fullName' => 'Example Customer',
        'companyName' => 'Different Company', 'active' => true,
    ]) === 'verified_candidate',
        'customer matching uses Name and preserves CompanyName as a separate field');
    test_assert(qb_export_name_candidate_status('customer', ['Example Customer'], [
        'listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'fullName' => 'Example Customer',
        'companyName' => null, 'active' => null,
    ]) === 'active_status_unknown',
        'unknown customer active status remains unverified');
    test_assert(qb_export_name_candidate_status('item', ['Example Product'], [
        'listId' => 'ITEM-LIST-1', 'name' => 'Example Product', 'fullName' => 'Example Product',
        'salesDescription' => 'Example Product', 'active' => false,
    ]) === 'inactive',
        'inactive product candidates cannot be verified');
    $itemCandidates = qb_export_name_candidate_summary('item', ['Example Product'], [
        $productLookup['response']['records'][0],
        [
            'listId' => 'ITEM-LIST-2', 'name' => 'Example Product', 'fullName' => 'Example Product:Second',
            'salesDescription' => 'Different invoice text', 'active' => true,
        ],
    ]);
    test_assert($itemCandidates['status'] === 'ambiguous_name_candidates'
        && $itemCandidates['candidate'] === null
        && $itemCandidates['matches'] === []
        && count($itemCandidates['diagnostic_matches']) === 2,
        'duplicate item candidates remain unselected but are available for explicit Admin review');
    test_assert(qb_export_name_candidate_summary('item', ['Absent Product'], [])['status'] === 'not_found_by_name',
        'no matching item name is reported without enumerating the item list');
    test_assert(qb_export_name_candidate_summary('item', ['Different Product'], [
        ['listId' => 'ITEM-3', 'name' => 'Different Product', 'fullName' => 'Different Product', 'active' => null],
    ])['status'] === 'active_status_unknown',
        'item candidates with an unknown active value remain unverified');
    $notFoundLookupClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'searchName' => $request['searchName'],
                'records' => [],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert($notFoundLookupClient->lookupName('customer', 'Absent Customer')['response']['records'] === [],
        'name lookup represents not-found without enumerating records');
    $ambiguousLookupClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'searchName' => $request['searchName'],
                'records' => [
                    ['listId' => 'CUST-1', 'name' => $request['searchName'], 'fullName' => 'Customer:1', 'companyName' => null, 'active' => true, 'found' => true],
                    ['listId' => 'CUST-2', 'name' => $request['searchName'], 'fullName' => 'Customer:2', 'companyName' => null, 'active' => true, 'found' => true],
                ],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert(count($ambiguousLookupClient->lookupName('customer', 'Duplicate Customer')['response']['records']) === 2,
        'ambiguous exact-name candidates are returned separately for explicit Admin resolution');
    $malformedLookupClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'item',
                'searchName' => $request['searchName'],
                'records' => [[
                    'name' => $request['searchName'],
                    'fullName' => $request['searchName'],
                    'salesDescription' => $request['searchName'],
                    'active' => true,
                    'found' => true,
                ]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert($malformedLookupClient->lookupName('item', 'Example Product')['diagnostic_code'] === 'BRIDGE_RESPONSE_MISMATCH',
        'name lookup rejects responses missing a QuickBooks internal ListID');
    $companyMismatchLookupClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'searchName' => $request['searchName'],
                'records' => [],
                'companyFingerprint' => str_repeat('c', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert($companyMismatchLookupClient->lookupName('customer', 'Example Customer')['diagnostic_code'] === 'COMPANY_IDENTITY_MISMATCH',
        'name lookup rejects a different QuickBooks company identity');
    $lookupFingerprintBeforeTest = getenv('WQ_QB_COMPANY_FINGERPRINT');
    putenv('WQ_QB_COMPANY_FINGERPRINT');
    $nameLookupTransportCalled = false;
    $unconfiguredNameLookup = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function () use (&$nameLookupTransportCalled): array {
            $nameLookupTransportCalled = true;
            return [];
        }
    );
    $unconfiguredNameResult = $unconfiguredNameLookup->lookupName('item', 'Example Product');
    if ($lookupFingerprintBeforeTest !== false) {
        putenv('WQ_QB_COMPANY_FINGERPRINT=' . $lookupFingerprintBeforeTest);
    }
    test_assert($unconfiguredNameResult['diagnostic_code'] === 'COMPANY_FINGERPRINT_NOT_CONFIGURED'
        && !$nameLookupTransportCalled,
        'name lookup fails closed before bridge transport when company identity is unconfigured');
    $invalidNameLookupThrown = false;
    try {
        $nameLookupClient->lookupName('item', ' Example Product ');
    } catch (InvalidArgumentException) {
        $invalidNameLookupThrown = true;
    }
    test_assert($invalidNameLookupThrown, 'name lookup rejects whitespace-padded input instead of normalizing silently');
    $invalidLookupEntityThrown = false;
    try {
        $nameLookupClient->lookupName('tax_code', 'Taxable');
    } catch (InvalidArgumentException) {
        $invalidLookupEntityThrown = true;
    }
    test_assert($invalidLookupEntityThrown, 'name lookup rejects unsupported entity types');
    $mismatchedCompanyClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'records' => [['listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'active' => true, 'found' => true]],
                'companyFingerprint' => str_repeat('c', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert(
        $mismatchedCompanyClient->diagnose('customer', ['CUST-LIST-1'])['diagnostic_code'] === 'COMPANY_IDENTITY_MISMATCH',
        'diagnostic evidence for a different company is rejected'
    );
    $unverifiedCompanyClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'records' => [['listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'active' => true, 'found' => true]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => false,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert(
        $unverifiedCompanyClient->diagnose('customer', ['CUST-LIST-1'])['diagnostic_code'] === 'COMPANY_IDENTITY_MISMATCH',
        'diagnostics reject responses without a verified company identity'
    );
    $previousExpectedFingerprint = getenv('WQ_QB_COMPANY_FINGERPRINT');
    putenv('WQ_QB_COMPANY_FINGERPRINT');
    $diagnosticTransportCalled = false;
    $missingCompanyClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function () use (&$diagnosticTransportCalled): array {
            $diagnosticTransportCalled = true;
            return [];
        }
    );
    $missingCompany = $missingCompanyClient->diagnose('customer', ['CUST-LIST-1']);
    if ($previousExpectedFingerprint !== false) {
        putenv('WQ_QB_COMPANY_FINGERPRINT=' . $previousExpectedFingerprint);
    }
    test_assert(
        $missingCompany['diagnostic_code'] === 'COMPANY_FINGERPRINT_NOT_CONFIGURED'
            && !$diagnosticTransportCalled,
        'diagnostics fail closed before HTTP when expected company identity is not configured'
    );
    putenv('WQ_QB_COMPANY_FINGERPRINT=invalid');
    $invalidCompany = (new QuickBooksBridgeClient('http://127.0.0.1:8765', $secret))->diagnose('customer', ['CUST-LIST-1']);
    if ($previousExpectedFingerprint !== false) {
        putenv('WQ_QB_COMPANY_FINGERPRINT=' . $previousExpectedFingerprint);
    } else {
        putenv('WQ_QB_COMPANY_FINGERPRINT');
    }
    test_assert(
        $invalidCompany['diagnostic_code'] === 'COMPANY_FINGERPRINT_INVALID',
        'diagnostics reject malformed expected company fingerprints'
    );
    foreach ([
        ['listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'found' => true],
        ['listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'active' => null, 'found' => true],
        ['listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'active' => 'unknown', 'found' => true],
    ] as $unverifiedRecord) {
        $unverifiedClient = new QuickBooksBridgeClient(
            'http://127.0.0.1:8765',
            $secret,
            static function (string $url, string $body) use ($secret, $unverifiedRecord): array {
                $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
                $responseBody = json_encode([
                    'requestId' => $request['requestId'],
                    'status' => 'ok',
                    'entity' => 'customer',
                    'records' => [$unverifiedRecord],
                    'companyFingerprint' => str_repeat('b', 64),
                    'companyIdentityVerified' => true,
                    'qbxmlVersion' => '16.0',
                ], JSON_THROW_ON_ERROR);
                return test_signed_response(200, $responseBody, $request['requestId'], $secret);
            }
        );
        test_assert(
            !$unverifiedClient->diagnose('customer', ['CUST-LIST-1'])['available'],
            'diagnostics reject missing, null, and malformed QuickBooks active status'
        );
    }
    $inactiveDiagnosticClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'records' => [['listId' => 'CUST-LIST-1', 'name' => 'Inactive Customer', 'active' => false, 'found' => true]],
                'companyFingerprint' => str_repeat('b', 64),
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert(
        $inactiveDiagnosticClient->diagnose('customer', ['CUST-LIST-1'])['response']['records'][0]['active'] === false,
        'explicit inactive QuickBooks status is preserved as false'
    );
    $diagnosticBadIdRejected = false;
    try {
        $diagnosticClient->diagnose('customer', ['customer name']);
    } catch (InvalidArgumentException) {
        $diagnosticBadIdRejected = true;
    }
    test_assert($diagnosticBadIdRejected, 'diagnostic requests reject names and invalid ListID syntax');
    $diagnosticDuplicateRejected = false;
    try {
        $diagnosticClient->diagnose('item', ['ITEM-1', 'ITEM-1']);
    } catch (InvalidArgumentException) {
        $diagnosticDuplicateRejected = true;
    }
    test_assert($diagnosticDuplicateRejected, 'diagnostic requests reject duplicate ListIDs');
    $diagnosticUnavailable = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'unavailable',
                'diagnosticCode' => 'QUICKBOOKS_NOT_RUNNING',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(503, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert($diagnosticUnavailable->diagnose('item', ['ITEM-1'])['diagnostic_code'] === 'QUICKBOOKS_NOT_RUNNING', 'signed unavailable diagnostics preserve sanitized QuickBooks readiness codes');

    $malformedClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            return test_signed_response(200, '{broken', $request['requestId'], $secret);
        }
    );
    $malformed = $malformedClient->validate([
        'protocolVersion' => '1', 'requestId' => qb_export_uuid(), 'operation' => 'validate',
        'validationOnly' => true, 'idempotencyKey' => null, 'transactionType' => 'invoice',
        'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
        'transaction' => [
            'customer' => ['listId' => 'CUST-LIST-1'], 'txnDate' => '2026-10-23',
            'refNumber' => 'INV-1', 'poNumber' => null, 'memo' => null,
            'lines' => [['item' => ['listId' => 'ITEM-LIST-1'], 'description' => 'Item', 'quantity' => '2', 'rate' => '10.00', 'expectedAmount' => '20.00', 'salesTaxCode' => ['listId' => 'TAX-LIST-1']]],
            'expectedSubtotal' => '20.00', 'expectedTax' => null, 'expectedTotal' => null,
        ],
    ]);
    test_assert(!$malformed['available'] && $malformed['diagnostic_code'] === 'BRIDGE_INVALID_RESPONSE', 'malformed signed bridge responses are rejected');

    $oversizedClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            return test_signed_response(200, str_repeat('x', 65537), $request['requestId'], $secret);
        }
    );
    $oversized = $oversizedClient->health();
    test_assert(!$oversized['available'] && $oversized['diagnostic_code'] === 'BRIDGE_RESPONSE_TOO_LARGE', 'oversized bridge responses are rejected before JSON decoding');

    $tamperedClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $signed = test_signed_response(200, json_encode(['requestId' => $request['requestId'], 'status' => 'ok'], JSON_THROW_ON_ERROR), $request['requestId'], $secret);
            $signed['headers']['x-mfc-signature'] = str_repeat('0', 64);
            return $signed;
        }
    );
    test_assert($tamperedClient->health()['diagnostic_code'] === 'BRIDGE_RESPONSE_AUTH_FAILED', 'tampered bridge responses fail HMAC validation');

    $transportCalled = false;
    $nonLoopbackClient = new QuickBooksBridgeClient(
        'http://example.com:8765',
        $secret,
        static function () use (&$transportCalled): array {
            $transportCalled = true;
            return ['status' => 200, 'body' => '{}'];
        }
    );
    $nonLoopback = $nonLoopbackClient->health();
    test_assert(!$nonLoopback['available']
        && $nonLoopback['diagnostic_code'] === 'BRIDGE_DESTINATION_REJECTED'
        && !$transportCalled, 'non-loopback bridge destinations are rejected before HTTP transport');
    $ipv6Client = new QuickBooksBridgeClient('http://[::1]:8765', $secret);
    test_assert(
        $ipv6Client->health()['diagnostic_code'] === 'BRIDGE_DESTINATION_REJECTED',
        'PHP rejects IPv6 loopback because the configured bridge listens only on IPv4 loopback'
    );

    $redirectDestination = (new QuickBooksBridgeClient('http://127.0.0.1:8765/redirect', $secret))->health();
    test_assert($redirectDestination['diagnostic_code'] === 'BRIDGE_DESTINATION_REJECTED', 'path-based redirect endpoints are rejected');
    $redirectResponse = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static fn(): array => ['status' => 302, 'body' => '', 'headers' => ['location' => 'http://example.com']]
    );
    test_assert($redirectResponse->health()['diagnostic_code'] === 'BRIDGE_RESPONSE_AUTH_FAILED', 'unsigned redirect responses are rejected without following redirects');

    $badOperationRejected = false;
    try {
        $authFailure->validate([
            'protocolVersion' => '1',
            'requestId' => qb_export_uuid(),
            'operation' => 'export',
            'validationOnly' => false,
            'transactionType' => 'invoice',
            'source' => [],
            'transaction' => ['lines' => []],
        ]);
    } catch (InvalidArgumentException) {
        $badOperationRejected = true;
    }
    test_assert($badOperationRejected, 'bridge client rejects transaction creation operations');

    test_assert(!function_exists('qb_export_post') && !method_exists(QuickBooksBridgeClient::class, 'submitInvoice'),
        'no PHP posting service or bridge submission method is available');

    $invalidBridgeDataRejected = false;
    try {
        $invalidRequest = [
            'protocolVersion' => '1', 'requestId' => qb_export_uuid(), 'operation' => 'validate',
            'validationOnly' => true, 'idempotencyKey' => null, 'transactionType' => 'invoice',
            'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
            'unexpected' => 'not allowed',
            'transaction' => [],
        ];
        $validationClient->validate($invalidRequest);
    } catch (InvalidArgumentException) {
        $invalidBridgeDataRejected = true;
    }
    test_assert($invalidBridgeDataRejected, 'unexpected bridge request JSON fields are rejected');

    $badAmountRejected = false;
    try {
        $badAmountRequest = [
            'protocolVersion' => '1', 'requestId' => qb_export_uuid(), 'operation' => 'validate',
            'validationOnly' => true, 'idempotencyKey' => null, 'transactionType' => 'invoice',
            'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
            'transaction' => [
                'customer' => ['listId' => 'CUST-LIST-1'], 'txnDate' => '2026-10-23',
                'refNumber' => 'INV-1', 'poNumber' => null, 'memo' => null,
                'lines' => [['item' => ['listId' => 'ITEM-LIST-1'], 'description' => 'Item', 'quantity' => '2', 'rate' => '10.00', 'expectedAmount' => '21.00', 'salesTaxCode' => ['listId' => 'TAX-LIST-1']]],
                'expectedSubtotal' => '21.00', 'expectedTax' => null, 'expectedTotal' => null,
            ],
        ];
        $validationClient->validate($badAmountRequest);
    } catch (InvalidArgumentException) {
        $badAmountRejected = true;
    }
    test_assert($badAmountRejected, 'bridge validation rejects line amount and subtotal reconciliation failures');

    $storedEvidence = [
        'reference_type' => 'customer',
        'local_key' => 'client:2',
        'qb_list_id' => 'CUST-NEW-VERIFY',
        'qb_full_name' => 'Second Customer',
        'active' => 1,
        'company_file_fingerprint' => str_repeat('b', 64),
        'diagnostic_request_id' => qb_export_uuid(),
        'verified_at' => date('Y-m-d H:i:s'),
        'verified_by' => 1,
    ];
    $storedEvidence['evidence_hmac'] = qb_export_verification_signature($storedEvidence, str_repeat('v', 40));
    $pdo->beginTransaction();
    qb_export_store_reference_verification($pdo, $storedEvidence);
    $pdo->commit();
    $savedMapping = $pdo->query("SELECT * FROM qb_export_reference_verifications WHERE local_key='client:2'")->fetch(PDO::FETCH_ASSOC);
    $savedCustomerListId = $pdo->query('SELECT qb_list_id FROM clients WHERE id=2')->fetchColumn();
    test_assert($savedCustomerListId === 'CUST-NEW-VERIFY'
        && $savedMapping['qb_list_id'] === 'CUST-NEW-VERIFY'
        && hash_equals(
            qb_export_verification_signature($savedMapping, str_repeat('v', 40)),
            $savedMapping['evidence_hmac']
        ), 'verified internal ListID and signed company evidence are saved together');
    $collisionEvidence = $storedEvidence;
    $collisionEvidence['local_key'] = 'client:1';
    $collisionEvidence['qb_full_name'] = 'Example Customer';
    $collisionEvidence['evidence_hmac'] = qb_export_verification_signature($collisionEvidence, str_repeat('v', 40));
    $collisionRejected = false;
    $pdo->beginTransaction();
    try {
        qb_export_store_reference_verification($pdo, $collisionEvidence);
    } catch (DomainException) {
        $collisionRejected = true;
    } finally {
        $pdo->rollBack();
    }
    test_assert($collisionRejected
        && $pdo->query('SELECT qb_list_id FROM clients WHERE id=1')->fetchColumn() === 'CUST-LIST-1',
        'a QuickBooks ListID already assigned to another local record is rejected without changing either mapping');

    $pdo->prepare("UPDATE clients SET qb_list_id=NULL WHERE id=1")->execute();
    $pdo->prepare("UPDATE products SET qb_list_id=NULL WHERE id=1")->execute();
    $secret = str_repeat('s', 40);
    putenv('WQ_QB_VERIFICATION_SECRET=' . $secret);
    putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('b', 64));
    $beforeTax = $pdo->query("SELECT qb_list_id FROM qb_export_reference_verifications WHERE reference_type='tax_code' AND local_key='tax:Tax'")->fetchColumn();
    if ($beforeTax === false) {
        $pdo->prepare(
            'INSERT INTO qb_export_reference_verifications (reference_type, local_key, qb_list_id, qb_full_name, verified_at, verified_by, company_file_fingerprint, diagnostic_request_id, evidence_hmac, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            'tax_code',
            'tax:Tax',
            'TAX-LIST-1',
            'Tax',
            '2026-10-23 12:00:00',
            1,
            str_repeat('b', 64),
            qb_export_uuid(),
            qb_export_verification_signature([
                'reference_type' => 'tax_code',
                'local_key' => 'tax:Tax',
                'qb_list_id' => 'TAX-LIST-1',
                'qb_full_name' => 'Tax',
                'active' => 1,
                'company_file_fingerprint' => str_repeat('b', 64),
                'diagnostic_request_id' => qb_export_uuid(),
                'verified_at' => '2026-10-23 12:00:00',
                'verified_by' => 1,
            ], $secret),
        ]);
    }
    $unapprovedCustomer = qb_export_reference($pdo, 'customer', 'client:1', null);
    $unapprovedItem = qb_export_reference($pdo, 'item', 'product:1', null);
    $unapprovedPreview = qb_export_validate($pdo, 'invoice', 1);
    test_assert($unapprovedCustomer === null && $unapprovedItem === null,
        'name candidates without stored Admin-approved signed evidence cannot resolve customer or item references');
    test_assert(!test_has_error($unapprovedPreview, 'CUSTOMER_MAPPING_UNVERIFIED')
        && !test_has_error($unapprovedPreview, 'ITEM_MAPPING_UNVERIFIED')
        && $unapprovedPreview['transaction']['customer_full_name'] === 'Example Customer'
        && $unapprovedPreview['transaction']['lines'][0]['item_full_name'] === 'ITEM-CODE-1',
        'unsigned local name and code values remain explicit FullName candidates, not signed mapping evidence');

    echo "All QuickBooks export foundation checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
