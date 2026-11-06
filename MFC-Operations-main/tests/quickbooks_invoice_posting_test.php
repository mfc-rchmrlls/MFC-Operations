<?php

declare(strict_types=1);
require_once __DIR__ . '/../backend/core/quickbooks_exports.php';
require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';

function posting_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function posting_has_error(array $validation, string $code): bool
{
    foreach ($validation['errors'] ?? [] as $error) {
        if (($error['code'] ?? null) === $code) {
            return true;
        }
    }
    return false;
}

function posting_fixture(bool $addMappings = true, bool $expiration = false, bool $unit = false): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY,client_name TEXT,qb_list_id TEXT,active INTEGER)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY,product_name TEXT,qb_list_id TEXT,qb_code TEXT,item_code TEXT,qb_description TEXT,description TEXT,u_m TEXT,active INTEGER)');
    $pdo->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY,site_name TEXT,active INTEGER)');
    $pdo->exec('CREATE TABLE withdrawals (id INTEGER PRIMARY KEY,withdrawal_no TEXT)');
    $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY,withdrawal_id INTEGER,customer_id INTEGER,invoice_number TEXT,invoice_date TEXT,po_no TEXT,memo TEXT,status TEXT)');
    $pdo->exec('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY,invoice_id INTEGER,withdrawal_item_id INTEGER,source_type TEXT,product_id INTEGER,site_id INTEGER,product_name TEXT,quantity INTEGER,lot_number TEXT,item_code TEXT,description TEXT,u_m TEXT,price_each TEXT,amount TEXT,price_source TEXT,tax_code TEXT,expiration_date TEXT)');
    $pdo->exec('CREATE TABLE qb_jobs (id INTEGER PRIMARY KEY,invoice_id INTEGER)');
    $pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY,role TEXT,active INTEGER)');
    $pdo->exec('CREATE TABLE qb_export_reference_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT,reference_type TEXT,local_key TEXT,qb_list_id TEXT,qb_full_name TEXT,verified_at TEXT,verified_by INTEGER,company_file_fingerprint TEXT,diagnostic_request_id TEXT,evidence_hmac TEXT,active INTEGER,UNIQUE(reference_type,local_key),UNIQUE(reference_type,qb_list_id))');
    $pdo->exec('CREATE TABLE qb_exports (id INTEGER PRIMARY KEY AUTOINCREMENT,export_id TEXT UNIQUE,transaction_type TEXT,source_transaction_id INTEGER,source_revision TEXT,snapshot_json TEXT,status TEXT DEFAULT "pending",attempt_count INTEGER DEFAULT 0,qb_txn_id TEXT,qb_ref_number TEXT,claim_token TEXT,lease_until TEXT,created_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT,completed_at TEXT,UNIQUE(transaction_type,source_transaction_id))');
    $pdo->exec('CREATE TABLE qb_export_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,export_id TEXT,attempt_number INTEGER,request_id TEXT UNIQUE,outcome TEXT,started_at TEXT,completed_at TEXT,diagnostic_code TEXT,diagnostic_message TEXT,UNIQUE(export_id,attempt_number))');
    $pdo->exec('CREATE TABLE qb_export_events (id INTEGER PRIMARY KEY AUTOINCREMENT,export_id TEXT,event_type TEXT,from_status TEXT,to_status TEXT,actor_id INTEGER,request_id TEXT,diagnostic_code TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE activity_logs (employee_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,details TEXT,ip_address TEXT,created_at TEXT)');
    $pdo->exec("INSERT INTO clients VALUES(1,'Mock Customer','CUST-MOCK',1)");
    $pdo->exec("INSERT INTO products VALUES(1,'Mock Product','ITEM-MOCK','CODE-1','LOCAL-CODE','Mock Item','Mock Item','',1)");
    $pdo->exec("INSERT INTO sites VALUES(1,'Main Site',1)");
    $pdo->exec("INSERT INTO withdrawals VALUES(1,'W-MOCK')");
    $pdo->exec("INSERT INTO invoices VALUES(1,1,1,'INV-MOCK','2026-10-23','PO-MOCK',NULL,'Draft')");
    $expirationDate = $expiration ? "'2026-12-31'" : 'NULL';
    $unit = $unit ? 'Each' : '';
    $pdo->exec("INSERT INTO invoice_items VALUES
        (1,1,NULL,'WITHDRAWAL',1,1,'Mock Product',2,'','LOCAL-CODE', 'Mock invoice line','{$unit}','10.00','20.00','mfc_entered','Tax',{$expirationDate}),
        (2,1,NULL,'WITHDRAWAL',1,1,'Mock Product',1,'','LOCAL-CODE', 'Second mock line','{$unit}','5.00','5.00','mfc_entered','Tax',NULL)");
    $pdo->exec("INSERT INTO employees VALUES(1,'Admin',1)");
    if ($addMappings) {
        foreach ([
            ['customer', 'client:1', 'CUST-MOCK', 'Mock Customer'],
            ['item', 'product:1', 'ITEM-MOCK', 'Mock Item'],
            ['tax_code', 'tax:Tax', 'TAX-MOCK', 'Tax'],
        ] as [$type, $key, $listId, $name]) {
            $evidence = [
                'reference_type' => $type,
                'local_key' => $key,
                'qb_list_id' => $listId,
                'qb_full_name' => $name,
                'active' => 1,
                'company_file_fingerprint' => str_repeat('b', 64),
                'diagnostic_request_id' => qb_export_uuid(),
                'verified_at' => '2026-10-23 12:00:00',
                'verified_by' => 1,
            ];
            $evidence['evidence_hmac'] = qb_export_verification_signature($evidence, str_repeat('v', 40));
            $insert = $pdo->prepare(
                'INSERT INTO qb_export_reference_verifications
                 (reference_type,local_key,qb_list_id,qb_full_name,verified_at,verified_by,
                  company_file_fingerprint,diagnostic_request_id,evidence_hmac,active)
                 VALUES(?,?,?,?,?,?,?,?,?,1)'
            );
            $insert->execute([
                $type, $key, $listId, $name, $evidence['verified_at'], 1,
                $evidence['company_file_fingerprint'], $evidence['diagnostic_request_id'], $evidence['evidence_hmac'],
            ]);
        }
    }
    return $pdo;
}

function posting_signed_response(int $status, array $response, string $secret, bool $addCompanyIdentity = false): array
{
    if ($addCompanyIdentity && ($response['companyIdentityVerified'] ?? false) === true) {
        $response['companyIdentity'] = [
            'companyName' => 'Mock QuickBooks Company',
            'legalCompanyName' => 'Mock QuickBooks Company LLC',
        ];
    }
    $body = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $requestId = $response['requestId'];
    $timestamp = (string)time();
    return [
        'status' => $status,
        'body' => $body,
        'headers' => [
            'x-mfc-timestamp' => $timestamp,
            'x-mfc-signature' => hash_hmac(
                'sha256',
                $timestamp . "\nresponse\n" . $requestId . "\n" . $status . "\n" . hash('sha256', $body),
                $secret
            ),
        ],
    ];
}

function posting_bridge(string $mode, array &$counters, string $secret, string $fingerprint = ''): QuickBooksBridgeClient
{
    return new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body, array $headers) use ($mode, &$counters, $secret, $fingerprint): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $operation = basename((string)parse_url($url, PHP_URL_PATH));
            if (!in_array($operation, ['health', 'diagnose'], true)) {
                throw new RuntimeException('The read-only test bridge received an unsupported operation.');
            }

            $counters['diagnostics']++;
            $counters['operations'][] = $operation;
            if ($operation === 'health') {
                return posting_signed_response(200, [
                    'requestId' => $request['requestId'],
                    'status' => 'ok',
                    'sdkRegistered' => true,
                    'clrVersion' => '4.0',
                    'dotNetFrameworkRelease' => '533320',
                    'quickBooksProcessRunning' => false,
                    'companySessionChecked' => false,
                    'companySessionReady' => null,
                    'transactionWritesSupported' => false,
                ], $secret);
            }

            $entity = $request['entity'];
            $mockFingerprint = $fingerprint ?: str_repeat('b', 64);
            $records = [];
            if (
                $mode === 'diagnostic-failure'
                && in_array($entity, ['customer', 'item', 'inventory_site'], true)
            ) {
                return posting_signed_response(503, [
                    'requestId' => $request['requestId'],
                    'status' => 'unavailable',
                    'diagnosticCode' => 'COMPANY_SESSION_UNAVAILABLE',
                    'failureStage' => 'begin_session',
                    'sdkErrorCode' => 'SDK_COM_80040418',
                ], $secret);
            }
            if ($entity === 'company_preferences') {
                $records[] = [
                    'customerTaxCodeEnabled' => $mode === 'null-customer-tax-pref'
                        ? null
                        : ($mode === 'false-customer-tax-pref' ? false : true),
                    'vendorTaxCodeEnabled' => null,
                    'amountsIncludeTax' => false,
                    'defaultTaxableCode' => ['listId' => 'TAX-MOCK', 'fullName' => 'Tax'],
                    'defaultNonTaxableCode' => ['listId' => 'TAX-MOCK', 'fullName' => 'Tax'],
                    'defaultTaxItem' => ['listId' => null, 'fullName' => null],
                    'multiLocationAvailable' => $mode === 'unknown-multi-location'
                        ? null
                        : ($mode === 'disabled-multi-location' ? false : true),
                    'multiLocationEnabled' => $mode === 'unknown-multi-location'
                        ? null
                        : ($mode === 'disabled-multi-location' ? false : true),
                    'lotTracking' => null,
                    'expirationDateEnabled' => false,
                    'salesTransactionTrackingEnabled' => null,
                ];
            } elseif (isset($request['searchName'])) {
                if (in_array($mode, ['site-query-failure', 'site-query-bad-signature'], true)
                    && $entity === 'inventory_site') {
                    $failureResponse = posting_signed_response(503, [
                        'requestId' => $request['requestId'],
                        'status' => 'unavailable',
                        'diagnosticCode' => 'REFERENCE_QUERY_FAILED',
                        'failureStage' => 'reference_name_query',
                        'qbxmlStatusCode' => '3140',
                        'qbxmlStatusSeverity' => 'Error',
                        'qbxmlStatusMessage' => 'The requested inventory Site query failed.',
                        'queryOperation' => 'InventorySiteQueryRq',
                    ], $secret);
                    if ($mode === 'site-query-bad-signature') {
                        $failureResponse['headers']['x-mfc-signature'] = str_repeat('0', 64);
                    }
                    return $failureResponse;
                }
                if (
                    ($mode === 'not-found-fullname' && in_array($entity, ['customer', 'item'], true))
                    || ($mode === 'different-item-results'
                        && $entity === 'item'
                        && $request['searchName'] === 'LOCAL-CODE')
                ) {
                    return posting_signed_response(200, [
                        'requestId' => $request['requestId'],
                        'status' => 'ok',
                        'entity' => $entity,
                        'searchName' => $request['searchName'],
                        'records' => [],
                        'companyFingerprint' => $mockFingerprint,
                        'companyIdentityVerified' => true,
                        'companyIdentity' => [
                            'companyName' => 'Mock QuickBooks Company',
                            'legalCompanyName' => 'Mock QuickBooks Company LLC',
                        ],
                        'qbxmlVersion' => '16.0',
                    ], $secret);
                }
                if ($mode === 'site-not-found' && $entity === 'inventory_site') {
                    return posting_signed_response(200, [
                        'requestId' => $request['requestId'],
                        'status' => 'ok',
                        'entity' => $entity,
                        'searchName' => $request['searchName'],
                        'records' => [],
                        'companyFingerprint' => $mockFingerprint,
                        'companyIdentityVerified' => true,
                        'companyIdentity' => [
                            'companyName' => 'Mock QuickBooks Company',
                            'legalCompanyName' => 'Mock QuickBooks Company LLC',
                        ],
                        'qbxmlVersion' => '16.0',
                    ], $secret);
                }
                $records[] = [
                    'listId' => 'SITE-MOCK',
                    'name' => $entity === 'inventory_site' ? 'Main Site' : $request['searchName'],
                    'fullName' => $entity === 'inventory_site' && $mode === 'bad-site'
                        ? 'Other Site'
                        : $request['searchName'],
                    'active' => ($entity === 'inventory_site' && $mode === 'inactive-site')
                        || (in_array($entity, ['customer', 'item'], true) && $mode === 'inactive-fullname')
                        || ($mode === 'different-item-results'
                            && $entity === 'item'
                            && $request['searchName'] === 'SECOND-LOCAL-CODE')
                        ? false
                        : ($mode === 'bad-site'
                            ? true
                            : ($entity === 'inventory_site' && $mode === 'unknown-site-active' ? null : true)),
                    'found' => true,
                ];
                if ($entity === 'customer') {
                    $records[0]['companyName'] = null;
                } elseif ($entity === 'item') {
                    $records[0]['manufacturerPartNumber'] = null;
                    $records[0]['salesDescription'] = null;
                }
                if (
                    $mode === 'ambiguous-fullname'
                    && in_array($entity, ['customer', 'item'], true)
                ) {
                    $duplicate = $records[0];
                    $duplicate['listId'] = 'SITE-MOCK-2';
                    $records[] = $duplicate;
                }
            } else {
                foreach ($request['listIds'] as $listId) {
                    if ($entity === 'item_uom') {
                        $records[] = [
                            'listId' => $listId,
                            'found' => true,
                            'itemName' => 'Mock Item',
                            'active' => true,
                            'unitSetListId' => $mode === 'unit-set' ? 'UNIT-SET' : null,
                            'unitSetName' => $mode === 'unit-set' ? 'Each' : null,
                            'unitSetActive' => $mode === 'unit-set' ? true : null,
                            'unitType' => $mode === 'unit-set' ? 'Count' : null,
                            'units' => $mode === 'unit-set' ? [['name' => 'Each', 'abbreviation' => 'ea']] : [],
                            'salesDefaultUnit' => $mode === 'unit-set' ? 'Each' : null,
                        ];
                        continue;
                    }
                    $active = !($mode === 'bad-reference' && $listId === 'ITEM-MOCK')
                        && !($mode === 'bad-tax' && $entity === 'tax_code');
                    $record = [
                        'listId' => $listId,
                        'name' => 'Mock Reference',
                        'active' => $active,
                        'found' => true,
                    ];
                    if ($entity === 'item') {
                        $record['manufacturerPartNumber'] = null;
                    }
                    $records[] = $record;
                }
            }

            return posting_signed_response(200, [
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => $entity,
                'records' => $records,
                'companyFingerprint' => $mockFingerprint,
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
                ... (isset($request['searchName']) ? ['searchName' => $request['searchName']] : []),
            ], $secret, true);
        }
    );
}

function posting_create_export(PDO $pdo): string
{
    $preview = qb_export_validate($pdo, 'invoice', 1);
    if (!$preview['valid']) {
        throw new RuntimeException('Mock invoice fixture is invalid: ' . json_encode($preview['errors']));
    }
    return qb_export_create($pdo, 'invoice', 1, $preview['source_revision'], 1)['export_id'];
}

putenv('WQ_QB_VERIFICATION_SECRET=' . str_repeat('v', 40));
putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('b', 64));
putenv('WQ_QB_INVOICE_WRITE_ENABLED=true');
$secret = str_repeat('s', 40);

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$historicalRequestId = qb_export_uuid();
$pdo->prepare(
    "INSERT INTO qb_export_attempts
     (export_id,attempt_number,request_id,outcome,started_at,diagnostic_code,diagnostic_message)
     VALUES(?,1,?,'rejected',CURRENT_TIMESTAMP,'HISTORICAL_REJECTION','Retained audit record')"
)->execute([$exportId, $historicalRequestId]);
$pdo->prepare('UPDATE qb_exports SET attempt_count=1 WHERE export_id=?')->execute([$exportId]);
$pdo->prepare(
    'INSERT INTO qb_export_events (export_id,event_type,from_status,to_status,actor_id,request_id,diagnostic_code)
     VALUES(?,?,?,?,?,?,?)'
)->execute([$exportId, 'historical_review', 'pending', 'pending', 1, $historicalRequestId, 'HISTORICAL_REJECTION']);
$snapshotBeforePreflight = (string)$pdo->query(
    'SELECT snapshot_json FROM qb_exports WHERE export_id=' . $pdo->quote($exportId)
)->fetchColumn();
$counters = ['writes' => 0, 'diagnostics' => 0, 'operations' => []];
$bridge = posting_bridge('success', $counters, $secret);
$preflight = qb_export_reference_preflight($pdo, $exportId, $bridge);
posting_assert($preflight['ready'] === true, 'read-only preflight verifies company settings and customer, item, tax, and Site references: ' . json_encode($preflight['errors'] ?? []));
posting_assert($counters['writes'] === 0 && $pdo->query("SELECT status FROM qb_exports WHERE export_id=" . $pdo->quote($exportId))->fetchColumn() === 'pending'
    && (int)$pdo->query("SELECT attempt_count FROM qb_exports WHERE export_id=" . $pdo->quote($exportId))->fetchColumn() === 1
    && (string)$pdo->query('SELECT snapshot_json FROM qb_exports WHERE export_id=' . $pdo->quote($exportId))->fetchColumn() === $snapshotBeforePreflight
    && (string)$pdo->query("SELECT diagnostic_message FROM qb_export_attempts WHERE export_id=" . $pdo->quote($exportId))->fetchColumn() === 'Retained audit record'
    && (int)$pdo->query("SELECT COUNT(*) FROM qb_export_events WHERE export_id=" . $pdo->quote($exportId) . " AND event_type='historical_review'")->fetchColumn() === 1,
    'reference preflight adds no transaction attempt and preserves the pending snapshot and historical audit records');
posting_assert(!function_exists('qb_export_post') && !method_exists(QuickBooksBridgeClient::class, 'submitInvoice'),
    'PHP transaction-posting service and bridge client method are structurally absent');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$wrongCompanyBridge = posting_bridge('success', $counters, $secret, str_repeat('c', 64));
$preflight = qb_export_reference_preflight($pdo, $exportId, $wrongCompanyBridge);
posting_assert($preflight['ready'] === false && $counters['writes'] === 0, 'company-fingerprint mismatch prevents a write');

$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
posting_assert($validation['valid']
    && $validation['transaction']['customer_full_name'] === 'Mock Customer'
    && $validation['transaction']['lines'][0]['item_full_name'] === 'LOCAL-CODE'
    && $validation['transaction']['customer_list_id'] === null
    && $validation['transaction']['lines'][0]['item_list_id'] === null,
    'unmapped invoice customer and item use separate exact FullName candidates without treating Item Code as a ListID');

$pdo = posting_fixture();
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$fullnameBridge = posting_bridge('success', $counters, $secret);
$preflight = qb_export_reference_preflight($pdo, $exportId, $fullnameBridge);
posting_assert($preflight['ready'] === true,
    'company-bound read-only preflight accepts unique active exact FullName references');
posting_assert($pdo->query("SELECT status FROM qb_exports WHERE export_id=" . $pdo->quote($exportId))->fetchColumn() === 'pending'
    && $counters['writes'] === 0,
    'exact FullName preflight does not approve or post the pending export');

foreach (['null-customer-tax-pref', 'false-customer-tax-pref'] as $taxPreferenceMode) {
    $pdo = posting_fixture(true);
    $pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
    $pdo->exec('UPDATE clients SET qb_list_id=NULL');
    $pdo->exec('UPDATE products SET qb_list_id=NULL');
    $validation = qb_export_validate($pdo, 'invoice', 1);
    $exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
    $counters = ['writes' => 0, 'diagnostics' => 0];
    $preflight = qb_export_reference_preflight($pdo, $exportId, posting_bridge($taxPreferenceMode, $counters, $secret));
    posting_assert($preflight['ready'] === true && $counters['writes'] === 0,
        'unknown or disabled customer-tax-code preference does not block independently verified invoice references');
}

$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$unknownSitePreference = qb_export_reference_preflight($pdo, $exportId, posting_bridge('unknown-multi-location', $counters, $secret));
posting_assert($unknownSitePreference['ready'] === false
    && in_array('INVENTORY_SITE_PREFERENCE_UNKNOWN', array_column($unknownSitePreference['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'unknown multiple-location preference remains a specific invoice-preflight blocker');
$disabledSitePreference = qb_export_reference_preflight($pdo, $exportId, posting_bridge('disabled-multi-location', $counters, $secret));
posting_assert($disabledSitePreference['ready'] === false
    && in_array('INVENTORY_SITES_DISABLED', array_column($disabledSitePreference['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'verified disabled multiple-location preference blocks invoice preflight');

$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$ambiguousPreflight = qb_export_reference_preflight($pdo, $exportId, posting_bridge('ambiguous-fullname', $counters, $secret));
posting_assert($ambiguousPreflight['ready'] === false
    && in_array('CUSTOMER_FULLNAME_AMBIGUOUS', array_column($ambiguousPreflight['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'duplicate exact FullName records block posting before any submit request');

$pdo = posting_fixture(true, true);
posting_assert(posting_has_error(qb_export_validate($pdo, 'invoice', 1), 'EXPIRATION_DESTINATION_UNVERIFIED'),
    'unsupported expiration date mapping blocks posting before submission');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$badMappings = posting_bridge('bad-site', $counters, $secret);
$preflight = qb_export_reference_preflight($pdo, $exportId, $badMappings);
posting_assert($preflight['ready'] === false
    && in_array('SITE_FULLNAME_MISMATCH', array_column($preflight['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'a returned but mismatched inventory-site FullName is distinguished from a failed lookup');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$siteNotFound = qb_export_reference_preflight($pdo, $exportId, posting_bridge('site-not-found', $counters, $secret));
posting_assert($siteNotFound['ready'] === false
    && in_array('SITE_NOT_FOUND', array_column($siteNotFound['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'an empty successful inventory-site query is reported as not found');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$inactiveSite = qb_export_reference_preflight($pdo, $exportId, posting_bridge('inactive-site', $counters, $secret));
posting_assert($inactiveSite['ready'] === false
    && in_array('SITE_INACTIVE', array_column($inactiveSite['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'an exact inactive inventory Site is distinguished from a failed lookup');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$unknownSiteActive = qb_export_reference_preflight($pdo, $exportId, posting_bridge('unknown-site-active', $counters, $secret));
posting_assert($unknownSiteActive['ready'] === false
    && in_array('SITE_ACTIVE_STATUS_UNKNOWN', array_column($unknownSiteActive['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'an unknown inventory Site active status remains a specific blocker');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$siteQueryFailed = qb_export_reference_preflight($pdo, $exportId, posting_bridge('site-query-failure', $counters, $secret));
$siteQueryFailureErrors = array_values(array_filter(
    $siteQueryFailed['errors'],
    static fn(array $error): bool => ($error['code'] ?? null) === 'SITE_QUERY_FAILED'
));
posting_assert($siteQueryFailed['ready'] === false
    && in_array('SITE_QUERY_FAILED', array_column($siteQueryFailed['errors'], 'code'), true)
    && ($siteQueryFailureErrors[0]['diagnostic_code'] ?? null) === 'REFERENCE_QUERY_FAILED'
    && ($siteQueryFailureErrors[0]['failure_stage'] ?? null) === 'reference_name_query'
    && ($siteQueryFailureErrors[0]['qbxml_status_code'] ?? null) === '3140'
    && ($siteQueryFailureErrors[0]['qbxml_status_severity'] ?? null) === 'Error'
    && ($siteQueryFailureErrors[0]['qbxml_status_message'] ?? null) === 'The requested inventory Site query failed.'
    && ($siteQueryFailureErrors[0]['query_operation'] ?? null) === 'InventorySiteQueryRq'
    && !in_array('SITE_NOT_FOUND', array_column($siteQueryFailed['errors'], 'code'), true)
    && !in_array('SITE_INACTIVE', array_column($siteQueryFailed['errors'], 'code'), true)
    && (int)$pdo->query("SELECT COUNT(*) FROM qb_export_reference_verifications WHERE reference_type='inventory_site'")->fetchColumn() === 0
    && $counters['writes'] === 0,
    'a correctly signed reference_name_query failure preserves its diagnostic, blocks the Site, and creates no verification or QuickBooks write');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$siteQueryBadSignature = qb_export_reference_preflight(
    $pdo,
    $exportId,
    posting_bridge('site-query-bad-signature', $counters, $secret)
);
$siteQueryBadSignatureErrors = array_values(array_filter(
    $siteQueryBadSignature['errors'],
    static fn(array $error): bool => ($error['code'] ?? null) === 'SITE_QUERY_FAILED'
));
posting_assert($siteQueryBadSignature['ready'] === false
    && ($siteQueryBadSignatureErrors[0]['diagnostic_code'] ?? null) === 'BRIDGE_RESPONSE_AUTH_FAILED'
    && !isset($siteQueryBadSignatureErrors[0]['failure_stage'])
    && (int)$pdo->query("SELECT COUNT(*) FROM qb_export_reference_verifications WHERE reference_type='inventory_site'")->fetchColumn() === 0
    && $counters['writes'] === 0,
    'an invalidly signed Site failure response is rejected and cannot verify the Site or allow posting');
$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$inactiveFullName = qb_export_reference_preflight($pdo, $exportId, posting_bridge('inactive-fullname', $counters, $secret));
posting_assert($inactiveFullName['ready'] === false
    && in_array('CUSTOMER_FULLNAME_INACTIVE', array_column($inactiveFullName['errors'], 'code'), true)
    && in_array('ITEM_FULLNAME_INACTIVE', array_column($inactiveFullName['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'exact inactive customer and item FullNames are distinguished from failed queries');
$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$notFoundFullName = qb_export_reference_preflight($pdo, $exportId, posting_bridge('not-found-fullname', $counters, $secret));
posting_assert($notFoundFullName['ready'] === false
    && in_array('CUSTOMER_FULLNAME_NOT_FOUND', array_column($notFoundFullName['errors'], 'code'), true)
    && in_array('ITEM_FULLNAME_NOT_FOUND', array_column($notFoundFullName['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'empty successful customer and item FullName queries are reported as not found');
$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$pdo->exec("INSERT INTO products VALUES(2,'Second Mock Product',NULL,'SECOND-CODE','SECOND-CODE','Second Mock Item','Second Mock Item','',1)");
$pdo->exec("INSERT INTO invoice_items VALUES
    (3,1,NULL,'WITHDRAWAL',2,1,'Second Mock Product',1,'','SECOND-LOCAL-CODE','Second mock line','','3.00','3.00','mfc_entered','Tax',NULL)");
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$differentItemResults = qb_export_reference_preflight(
    $pdo,
    $exportId,
    posting_bridge('different-item-results', $counters, $secret)
);
$differentItemErrors = array_values(array_filter(
    $differentItemResults['errors'],
    static fn(array $error): bool => ($error['entity'] ?? null) === 'item'
));
$differentItemMessages = qb_export_preflight_summary_messages($differentItemErrors);
posting_assert($differentItemResults['ready'] === false
    && count($differentItemErrors) === 2
    && array_column($differentItemErrors, 'code') === ['ITEM_FULLNAME_NOT_FOUND', 'ITEM_FULLNAME_INACTIVE']
    && count($differentItemMessages) === 2
    && str_contains(implode(' ', $differentItemMessages), 'LOCAL-CODE')
    && str_contains(implode(' ', $differentItemMessages), 'SECOND-LOCAL-CODE')
    && $counters['writes'] === 0,
    'different per-item FullName failures remain separately actionable without a QuickBooks write');
$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$sharedQueryFailure = qb_export_reference_preflight($pdo, $exportId, posting_bridge('diagnostic-failure', $counters, $secret));
$sharedErrors = array_values(array_filter($sharedQueryFailure['errors'], static fn(array $error): bool => !empty($error['shared_failure'])));
$summaryMessages = qb_export_preflight_summary_messages($sharedErrors);
posting_assert($sharedQueryFailure['ready'] === false
    && count($sharedErrors) >= 3
    && count($summaryMessages) === 1
    && str_contains($summaryMessages[0], 'SDK_COM_80040418')
    && $counters['writes'] === 0,
    'shared signed SDK failures retain per-reference details while the summary reports one root cause');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$badTax = posting_bridge('bad-tax', $counters, $secret);
$preflight = qb_export_reference_preflight($pdo, $exportId, $badTax);
posting_assert($preflight['ready'] === false
    && in_array('TAX_DEFAULTS_UNVERIFIED', array_column($preflight['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'inactive company-level tax defaults block managed-defaults posting without per-line tax mappings');

$pdo = posting_fixture(true, false, true);
$exportId = posting_create_export($pdo);
$storedSnapshot = json_decode(
    (string)$pdo->query('SELECT snapshot_json FROM qb_exports WHERE export_id=' . $pdo->quote($exportId))->fetchColumn(),
    true,
    16,
    JSON_THROW_ON_ERROR
);
$preflightTransaction = qb_export_reference_preflight_transaction($storedSnapshot);
posting_assert(($storedSnapshot['lines'][0]['unit_of_measure'] ?? null) === 'Each'
    && !array_key_exists('unitOfMeasure', $preflightTransaction['lines'][0])
    && ($preflightTransaction['lines'][0]['item']['listId'] ?? null) === 'ITEM-MOCK'
    && ($storedSnapshot['lines'][0]['quantity'] ?? null) === '2'
    && !array_key_exists('quantity', $preflightTransaction['lines'][0]),
    'immutable preview retains U/M, item identity, and requested quantity while reference input omits transaction values');

posting_assert(!function_exists('qb_export_post') && !method_exists(QuickBooksBridgeClient::class, 'submitInvoice'),
    'no PHP posting path can construct or send an invoice request');

echo "All read-only invoice reference preflight tests passed.\n";
