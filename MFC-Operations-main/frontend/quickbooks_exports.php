<?php

declare(strict_types=1);
$page_title = 'QuickBooks Export Previews';
require_once __DIR__ . '/../backend/config/app.php';
require_once __DIR__ . '/../backend/core/quickbooks_exports.php';
require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';
require_login();
require_admin();

$type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
$sourceId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedExportId = is_string($_GET['export_id'] ?? null) ? $_GET['export_id'] : '';
$preview = null;
$loadError = '';
$bridgeClient = new QuickBooksBridgeClient();
$bridge = $bridgeClient->health();
$bridgeHealth = is_array($bridge['response'] ?? null) ? $bridge['response'] : [];
$bridgeSecret = getenv('WQ_QB_BRIDGE_SECRET');
$expectedFingerprint = strtolower((string)(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: ''));
$verificationSecret = getenv('WQ_QB_VERIFICATION_SECRET');
$bridgeUrl = getenv('WQ_QB_BRIDGE_URL');
$effectiveBridgeUrl = $bridgeUrl === false || $bridgeUrl === ''
    ? 'http://127.0.0.1:8765'
    : $bridgeUrl;
$quickBooksConfiguration = [
    'bridge_url' => $effectiveBridgeUrl === 'http://127.0.0.1:8765'
        ? ($bridgeUrl === false || $bridgeUrl === '' ? 'Default loopback URL' : 'Configured loopback URL')
        : 'Unexpected URL; loopback required',
    'bridge_url_valid' => $effectiveBridgeUrl === 'http://127.0.0.1:8765',
    'bridge_secret' => is_string($bridgeSecret) && strlen($bridgeSecret) >= 32,
    'company_fingerprint' => preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint) === 1,
    'verification_secret' => is_string($verificationSecret) && strlen($verificationSecret) >= 32,
];
$pdo = db();
$diagnosticSites = [];
$diagnosticProducts = [];
try {
    $diagnosticSites = $pdo->query('SELECT id, site_name FROM sites WHERE active=1 ORDER BY site_name LIMIT 100')->fetchAll();
    $diagnosticProducts = $pdo->query(
        "SELECT id, product_name, qb_list_id FROM products
         WHERE active=1 AND qb_list_id IS NOT NULL AND qb_list_id <> ''
         ORDER BY product_name LIMIT 100"
    )->fetchAll();
} catch (PDOException) {
    $loadError = 'Local Site or product diagnostic choices are unavailable; verify the deployed schema before running diagnostics.';
}
$companyDiagnostic = is_array($_SESSION['_qb_company_diagnostic'] ?? null)
    ? $_SESSION['_qb_company_diagnostic']
    : null;
unset($_SESSION['_qb_company_diagnostic']);
$lastCompanyIdentityDiagnostic = is_array($_SESSION['_qb_company_last_success'] ?? null)
    ? $_SESSION['_qb_company_last_success']
    : null;
$referencePreflightDiagnostic = is_array($_SESSION['_qb_reference_preflight'] ?? null)
    ? $_SESSION['_qb_reference_preflight']
    : null;
unset($_SESSION['_qb_reference_preflight']);

$invoices = $pdo->query(
    'SELECT i.id, i.invoice_number AS reference_number, i.invoice_date AS transaction_date,
            (SELECT COALESCE(SUM(ii.amount), 0) FROM invoice_items ii WHERE ii.invoice_id=i.id) AS line_subtotal,
            i.status, w.withdrawal_no, c.client_name
     FROM invoices i JOIN withdrawals w ON w.id=i.withdrawal_id
     JOIN clients c ON c.id=i.customer_id
     ORDER BY i.id DESC LIMIT 100'
)->fetchAll();
$localTaxChoices = $pdo->query(
    'SELECT tax_code, COUNT(*) AS line_count FROM invoice_items
     WHERE tax_code IS NOT NULL AND TRIM(tax_code) <> ""
     GROUP BY tax_code ORDER BY tax_code'
)->fetchAll();
$orders = [];
try {
    $orders = $pdo->query(
        'SELECT so.id, so.so_number AS reference_number, so.sales_order_date AS transaction_date,
                (SELECT COALESCE(SUM(soi.amount), 0) FROM sales_order_items soi WHERE soi.sales_order_id=so.id) AS line_subtotal,
                so.status, w.withdrawal_no, c.client_name
         FROM sales_orders so JOIN withdrawals w ON w.id=so.withdrawal_id
         JOIN clients c ON c.id=so.customer_id
         ORDER BY so.id DESC LIMIT 100'
    )->fetchAll();
} catch (PDOException) {
    $loadError = 'Sales-order tables are unavailable. Apply the sales-order preparation migration before using sales-order previews.';
}
$availableTransactions = [];
foreach ($invoices as $item) {
    $item['transaction_type'] = 'invoice';
    $item['type_label'] = 'Invoice';
    $availableTransactions[] = $item;
}
foreach ($orders as $item) {
    $item['transaction_type'] = 'sales_order';
    $item['type_label'] = 'Sales Order';
    $availableTransactions[] = $item;
}
usort($availableTransactions, static function (array $left, array $right): int {
    $dateOrder = strcmp((string)$right['transaction_date'], (string)$left['transaction_date']);
    return $dateOrder !== 0 ? $dateOrder : ((int)$right['id'] <=> (int)$left['id']);
});

if ($sourceId && in_array($type, qb_export_types(), true)) {
    try {
        $preview = qb_export_validate($pdo, $type, $sourceId);
    } catch (Throwable $error) {
        $loadError = 'Preview unavailable. Apply the typed-export migration and verify the database schema.';
    }
} elseif ($type !== '' || $sourceId) {
    $loadError = 'Choose a supported transaction type and a valid source record.';
}

$exports = [];
try {
    $exports = $pdo->query(
        'SELECT export_id, transaction_type, source_transaction_id, source_revision, status,
                qb_txn_id, qb_ref_number, created_at, updated_at
         FROM qb_exports ORDER BY id DESC LIMIT 100'
    )->fetchAll();
} catch (Throwable) {
    $loadError = $loadError ?: 'The typed-export migration has not been applied.';
}
$selectedExport = null;
$referencePreflight = null;
if ($selectedExportId !== '') {
    try {
        $selectedQuery = $pdo->prepare(
            'SELECT export_id,transaction_type,source_transaction_id,source_revision,snapshot_json,status,
                    qb_txn_id,qb_ref_number,attempt_count
             FROM qb_exports WHERE export_id=?'
        );
        $selectedQuery->execute([$selectedExportId]);
        $selectedExport = $selectedQuery->fetch() ?: null;
        if ($selectedExport && $selectedExport['transaction_type'] === 'invoice' && $selectedExport['status'] === 'pending') {
            $referencePreflight = qb_export_reference_preflight($pdo, $selectedExportId, $bridgeClient);
        }
    } catch (Throwable) {
        $loadError = 'The selected export could not be safely checked. No QuickBooks transaction was created.';
    }
}
$selectedSnapshot = null;
if (is_array($selectedExport)) {
    try {
        $selectedSnapshot = json_decode((string)$selectedExport['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $selectedSnapshot = null;
    }
}
$selectedAttempts = [];
if (is_array($selectedExport)) {
    try {
        $attemptQuery = $pdo->prepare(
            'SELECT outcome,diagnostic_code,diagnostic_message,started_at
             FROM qb_export_attempts WHERE export_id=? ORDER BY attempt_number DESC LIMIT 10'
        );
        $attemptQuery->execute([$selectedExportId]);
        $selectedAttempts = $attemptQuery->fetchAll();
    } catch (Throwable) {
        $selectedAttempts = [];
    }
}
$referenceLookup = is_array($_SESSION['_qb_reference_lookup'] ?? null)
    ? $_SESSION['_qb_reference_lookup']
    : null;
$referenceScan = is_array($_SESSION['_qb_reference_scan'] ?? null)
    ? $_SESSION['_qb_reference_scan']
    : null;
unset($_SESSION['_qb_reference_scan']);
$referenceCandidateSummary = null;
$referenceMatchingListIds = [];
if (is_array($referenceLookup)) {
    $referenceCandidateSummary = qb_export_name_candidate_summary(
        (string)$referenceLookup['type'],
        is_array($referenceLookup['search_names'] ?? null) ? $referenceLookup['search_names'] : [],
        is_array($referenceLookup['records'] ?? null) ? $referenceLookup['records'] : []
    );
    $referenceMatchingListIds = array_column($referenceCandidateSummary['matches'], 'listId');
}
$referenceCustomers = [];
$referenceProducts = [];
$referenceLoadError = '';
try {
    $referenceCustomers = $pdo->query(
        'SELECT id, client_name FROM clients WHERE active=1 ORDER BY client_name'
    )->fetchAll();
    $referenceProducts = $pdo->query(
        'SELECT id, product_name, qb_description FROM products WHERE active=1 ORDER BY product_name'
    )->fetchAll();
} catch (Throwable) {
    $referenceLoadError = 'Local reference lists are unavailable; no QuickBooks lookup can be started.';
}
require __DIR__ . '/includes/layout_start.php';
?>
<div class="qb-export-page">
<?php
$verifiedCompanyIdentity = $companyDiagnostic['company_identity']
    ?? $lastCompanyIdentityDiagnostic['company_identity']
    ?? null;
$companyVerificationStatus = !empty($companyDiagnostic['company_identity_verified'])
    ? 'Verified in latest check'
    : (!empty($lastCompanyIdentityDiagnostic['checked_at']) ? 'Previously verified' : 'Not verified');
$invoiceSendingStatus = 'Not supported (preview only)';
?>
<div class="page-intro qb-export-intro">
    <div>
        <h2>QuickBooks Export Previews</h2>
        <p>Review and save immutable, pending previews. This application does not create QuickBooks transactions.</p>
    </div>
</div>
<section class="panel qb-connection-summary mb-4" aria-labelledby="qb-connection-heading">
    <div class="panel-header">
        <h3 id="qb-connection-heading">QuickBooks connection</h3>
        <a class="btn btn-sm btn-outline-secondary" href="#advanced-diagnostics">Advanced Diagnostics</a>
    </div>
    <div class="panel-body qb-connection-grid">
        <div>
            <span class="qb-summary-label">Bridge</span>
            <span class="status-pill <?= $bridge['available'] ? 'status-success' : 'status-danger' ?>">
                <?= $bridge['available'] ? 'Connected' : 'Unavailable' ?>
            </span>
        </div>
        <div>
            <span class="qb-summary-label">Company (last verified)</span>
            <strong><?= e(is_array($verifiedCompanyIdentity) ? (string)($verifiedCompanyIdentity['companyName'] ?? 'Unknown') : 'Not verified') ?></strong>
        </div>
        <div>
            <span class="qb-summary-label">Company verification</span>
            <strong><?= e($companyVerificationStatus) ?></strong>
        </div>
        <div>
            <span class="qb-summary-label">Invoice sending</span>
            <strong><?= e($invoiceSendingStatus) ?></strong>
        </div>
    </div>
</section>
<details class="qb-advanced-diagnostics mb-4" id="advanced-diagnostics">
    <summary>Advanced Diagnostics</summary>
<?php if ($referencePreflightDiagnostic !== null): ?>
    <section class="panel mb-4" aria-labelledby="reference-preflight-diagnostic-heading">
        <div class="panel-header">
            <div>
                <h3 id="reference-preflight-diagnostic-heading">Latest invoice reference preflight</h3>
                <p>Read-only company-bound results. A failed query is not evidence that a customer, item, or Site is missing.</p>
            </div>
        </div>
        <div class="panel-body">
            <p class="small text-muted">Checked <?= e((string)($referencePreflightDiagnostic['checked_at'] ?? '')) ?>. No invoice was queued or sent by this failed preflight.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Reference</th><th>Result</th><th>Diagnostic</th></tr></thead>
                    <tbody>
                    <?php foreach (($referencePreflightDiagnostic['errors'] ?? []) as $error): ?>
                        <?php if (!is_array($error)) { continue; } ?>
                        <tr>
                            <td><?= e(trim((string)($error['entity'] ?? '') . ' ' . (string)($error['reference'] ?? ''))) ?: 'Company settings' ?></td>
                            <td><?= e((string)($error['message'] ?? 'Preflight requirement was not satisfied.')) ?></td>
                            <td>
                                <?php if (!empty($error['diagnostic_code'])): ?><code><?= e((string)$error['diagnostic_code']) ?></code><?php endif; ?>
                                <?php if (!empty($error['failure_stage'])): ?> <span>Stage:</span> <code><?= e((string)$error['failure_stage']) ?></code><?php endif; ?>
                                <?php if (!empty($error['sdk_error_code'])): ?> <span>SDK:</span> <code><?= e((string)$error['sdk_error_code']) ?></code><?php endif; ?>
                                <?php if (!empty($error['query_operation'])): ?><small class="d-block">Query: <code><?= e((string)$error['query_operation']) ?></code></small><?php endif; ?>
                                <?php if (!empty($error['qbxml_status_code'])): ?>
                                    <small class="d-block">QBXML:
                                        <code><?= e((string)$error['qbxml_status_code']) ?></code>
                                        <?php if (!empty($error['qbxml_status_severity'])): ?> <code><?= e((string)$error['qbxml_status_severity']) ?></code><?php endif; ?>
                                    </small>
                                <?php endif; ?>
                                <?php if (!empty($error['qbxml_status_message'])): ?><small class="d-block">QBXML message: <?= e((string)$error['qbxml_status_message']) ?></small><?php endif; ?>
                                <?php if (!empty($error['request_id'])): ?><small class="d-block">Request: <?= e((string)$error['request_id']) ?></small><?php endif; ?>
                                <span class="text-muted"><?= e((string)($error['code'] ?? '')) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>
<section class="panel mb-4" id="reference-verification">
    <div class="panel-header">
        <div>
            <h3>Customer and product reference verification</h3>
            <p>Only an explicit Admin request starts a company-bound exact-name lookup or saves a verified mapping. No QuickBooks records are created or changed.</p>
        </div>
    </div>
    <div class="panel-body">
        <section class="border rounded p-3 mb-4" id="reference-scan" aria-labelledby="reference-scan-heading">
            <h4 id="reference-scan-heading">QuickBooks reference discovery</h4>
            <p>Run a signed, read-only scan of the verified company. Customer and item lists use bounded pagination (up to 100 records each); sites, mapped item units, and saved tax-code ListIDs are checked only against local known references. Broad item results omit SalesDesc and pricing; use the existing exact item lookup for that detail. Results are advisory and never approve mappings.</p>
            <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_scan.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <label class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="confirm_company" value="Testing QB.qbw" required>
                    <span class="form-check-label">I confirm that <strong>Testing QB.qbw</strong> is currently open in QuickBooks.</span>
                </label>
                <button class="btn btn-outline-primary" type="submit" <?= $referenceLoadError !== '' ? 'disabled' : '' ?>>Scan QuickBooks References</button>
            </form>
            <?php if ($referenceScan): ?>
                <div class="mt-3" role="status" aria-live="polite">
                    <?php if (empty($referenceScan['available'])): ?>
                        <div class="alert alert-warning mb-2">
                            <?= e((string)($referenceScan['message'] ?? 'The scan was unavailable.')) ?>
                            <?php if (!empty($referenceScan['diagnostic_code'])): ?><code><?= e((string)$referenceScan['diagnostic_code']) ?></code><?php endif; ?>
                            <?php if (!empty($referenceScan['failure_stage'])): ?>Stage: <code><?= e((string)$referenceScan['failure_stage']) ?></code><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p><strong>Company:</strong> <?= e((string)($referenceScan['company_identity']['companyName'] ?? 'Unknown')) ?>
                            <span class="text-muted">(identity checked against configured fingerprint)</span>
                        </p>
                        <p class="small text-muted">Request ID: <?= e((string)($referenceScan['request_id'] ?? '')) ?>. No QuickBooks records or local mappings were changed.</p>
                        <?php foreach ([
                            'local_customer_limit_reached' => 'Local customer results are limited to 100.',
                            'local_product_limit_reached' => 'Local product results are limited to 100.',
                            'local_site_limit_reached' => 'Only the first 20 local sites were checked.',
                            'saved_item_list_limit_reached' => 'Only the first 20 saved item ListIDs were checked for units of measure.',
                            'saved_tax_code_limit_reached' => 'Only the first 20 saved tax-code ListIDs were checked.',
                            'local_tax_code_limit_reached' => 'Only the first 20 local tax codes were reviewed.',
                        ] as $limitFlag => $limitMessage): ?>
                            <?php if (!empty($referenceScan[$limitFlag])): ?><p class="alert alert-warning py-2"><?= e($limitMessage) ?></p><?php endif; ?>
                        <?php endforeach; ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Reference group</th><th>Scope</th><th>Status</th><th>Results</th></tr></thead>
                                <tbody>
                                <?php foreach ($referenceScan['categories'] as $category): ?>
                                    <tr>
                                        <td><?= e(str_replace('_', ' ', (string)$category['category'])) ?></td>
                                        <td><?= e(str_replace('_', ' ', (string)$category['scope'])) ?></td>
                                        <td><span class="status-pill <?= $category['status'] === 'complete' ? 'status-success' : ($category['status'] === 'partial' ? 'status-warning' : 'status-danger') ?>"><?= e((string)$category['status']) ?></span>
                                            <?php if (!empty($category['diagnosticCode'])): ?><code><?= e((string)$category['diagnosticCode']) ?></code><?php endif; ?>
                                        </td>
                                        <td><?= (int)$category['recordCount'] ?> / <?= (int)$category['limit'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <details class="mt-3">
                            <summary>Local reference mapping review</summary>
                            <div class="table-responsive mt-2">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Type</th><th>Local reference</th><th>Result</th><th>QuickBooks candidates</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($referenceScan['matches'] as $match): ?>
                                        <tr>
                                            <td><?= e((string)$match['type']) ?></td>
                                            <td><?= e((string)$match['local_name']) ?></td>
                                            <td><?= e(str_replace('_', ' ', (string)$match['status'])) ?></td>
                                            <td>
                                                <?php if (!$match['candidates']): ?>None found in scanned results
                                                <?php else: foreach ($match['candidates'] as $candidate): ?>
                                                    <div><strong><?= e((string)($candidate['fullName'] ?? $candidate['itemName'] ?? $candidate['name'] ?? $candidate['unitSetName'] ?? '')) ?></strong>
                                                        <code><?= e((string)$candidate['listId']) ?></code>
                                                        <?php if (isset($candidate['active'])): ?><?= $candidate['active'] ? 'Active' : 'Inactive' ?><?php endif; ?>
                                                        <?php if (!empty($candidate['companyName'])): ?> · <?= e((string)$candidate['companyName']) ?><?php endif; ?>
                                                        <?php if (!empty($candidate['manufacturerPartNumber'])): ?> · MPN <?= e((string)$candidate['manufacturerPartNumber']) ?><?php endif; ?>
                                                        <?php if (!empty($candidate['unitSetName'])): ?> · U/M set <?= e((string)$candidate['unitSetName']) ?><?php endif; ?>
                                                        <?php if (!empty($candidate['units'])): ?> · Units <?= e(implode(', ', array_column($candidate['units'], 'name'))) ?><?php endif; ?>
                                                    </div>
                                                <?php endforeach; endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="small text-muted">Exact names and active status are discovery evidence only. Resolve each candidate through the existing Admin lookup and signed verification workflow before invoice preflight.</p>
                        </details>
                        <details class="mt-3">
                            <summary>Read-only reference details</summary>
                            <?php foreach ($referenceScan['categories'] as $category): ?>
                                <h5 class="mt-3"><?= e(str_replace('_', ' ', (string)$category['category'])) ?></h5>
                                <?php if ($category['records']): ?>
                                    <ul class="small mb-2">
                                    <?php foreach ($category['records'] as $record): ?>
                                        <li>
                                            <?php if ($category['category'] === 'company_preferences'): ?>
                                                <?php foreach ($record as $preference => $value): ?>
                                                    <span><strong><?= e(str_replace('_', ' ', (string)$preference)) ?>:</strong>
                                                        <?= e(is_array($value)
                                                            ? trim((string)($value['fullName'] ?? '') . (!empty($value['listId']) ? ' [' . $value['listId'] . ']' : ''))
                                                            : (is_bool($value) ? ($value ? 'Yes' : 'No') : (string)($value ?? 'Unknown'))) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <?php if (isset($record['listId'])): ?><code><?= e((string)$record['listId']) ?></code><?php endif; ?>
                                                <?= e((string)($record['fullName'] ?? $record['name'] ?? $record['itemName'] ?? $record['unitSetName'] ?? '')) ?>
                                                <?php if (isset($record['active'])): ?><?= $record['active'] ? ' — Active' : ' — Inactive' ?><?php endif; ?>
                                                <?php if (!empty($record['companyName'])): ?> — Company: <?= e((string)$record['companyName']) ?><?php endif; ?>
                                                <?php if (!empty($record['customerType'])): ?> — Type: <?= e((string)$record['customerType']) ?><?php endif; ?>
                                                <?php if (!empty($record['itemType'])): ?> — Type: <?= e((string)$record['itemType']) ?><?php endif; ?>
                                                <?php if (!empty($record['manufacturerPartNumber'])): ?> — MPN: <?= e((string)$record['manufacturerPartNumber']) ?><?php endif; ?>
                                                <?php if (!empty($record['unitSetName'])): ?> — U/M set: <?= e((string)$record['unitSetName']) ?><?php endif; ?>
                                                <?php if (!empty($record['units'])): ?> — U/M: <?= e(implode(', ', array_column($record['units'], 'name'))) ?><?php endif; ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>
                                <?php else: ?><p class="small text-muted">No records returned for this scope.</p><?php endif; ?>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php if ($referenceLoadError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($referenceLoadError) ?></div><?php endif; ?>
        <div class="alert alert-warning" role="status">
            Customer lookup runs separate bounded exact queries for QuickBooks <code>Name</code> and <code>FullName</code>; returned <code>CompanyName</code> is shown separately for review, but QuickBooks provides no CompanyName query filter.
            Product lookup runs bounded exact <code>Name</code>/<code>FullName</code> searches using the local product name and configured invoice description. An Admin may explicitly select a candidate after reviewing its QuickBooks identity and confirming that it is the intended product. This approval creates a direct local-product-to-QuickBooks-ListID mapping; it does not claim that the local Item Code matches any QuickBooks field. QBXML 16.0 does not expose a generic SKU field or SKU filter; <code>ManufacturerPartNumber</code> is not assumed to be the Item Code. <code>SalesDesc</code> is shown separately and is not treated as an invoice-line <code>Desc</code>.
            The reference scan can enumerate customer and item lists through capped read-only iterators; it reports capped results as partial. The existing mapping workflow still rechecks the exact active ListID and company identity before saving signed approval evidence.
        </div>
        <div class="alert alert-info" role="note">
            Business confirmation and technical verification are separate. Customer matches must be unique and active. For products, an Admin must manually select and confirm the exact intended QuickBooks item; duplicate candidates are not auto-selected. Item Code remains local-only; this application does not send transaction lines.
        </div>
        <div class="row g-3">
            <div class="col-lg-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_verification.php" class="border rounded p-3">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="lookup">
                    <input type="hidden" name="reference_type" value="customer">
                    <label class="form-label" for="qb-customer-local">Local customer</label>
                    <select class="form-select mb-3" id="qb-customer-local" name="local_id" required>
                        <option value="">Select a customer</option>
                        <?php foreach ($referenceCustomers as $customer): ?>
                            <option value="<?= (int)$customer['id'] ?>"><?= e($customer['client_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit" <?= $referenceLoadError !== '' ? 'disabled' : '' ?>>Look up QuickBooks customer by name</button>
                </form>
            </div>
            <div class="col-lg-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_verification.php" class="border rounded p-3">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="lookup">
                    <input type="hidden" name="reference_type" value="item">
                    <label class="form-label" for="qb-product-local">Local product</label>
                    <select class="form-select mb-3" id="qb-product-local" name="local_id" required>
                        <option value="">Select a product</option>
                        <?php foreach ($referenceProducts as $product): ?>
                            <option value="<?= (int)$product['id'] ?>"><?= e($product['product_name']) ?> — QB description: <?= e($product['qb_description'] ?? '(not configured)') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit" <?= $referenceLoadError !== '' ? 'disabled' : '' ?>>Look up QuickBooks item candidates</button>
                </form>
            </div>
        </div>
        <?php if ($referenceLookup): ?>
            <div class="mt-4" role="status">
                <h4>Lookup result: <?= e(str_replace('_', ' ', (string)$referenceLookup['status'])) ?></h4>
                <p>
                    <?= e(ucfirst((string)$referenceLookup['type'])) ?> lookup for
                    <strong><?= e((string)$referenceLookup['search_name']) ?></strong>.
                    <?php if (!empty($referenceLookup['diagnostic_code'])): ?>
                        <?= e((string)$referenceLookup['message']) ?> <code><?= e((string)$referenceLookup['diagnostic_code']) ?></code>
                    <?php elseif (in_array(($referenceLookup['status'] ?? ''), ['not_found', 'not_found_by_name'], true)): ?>
                        No exact candidates were returned.
                    <?php elseif (($referenceLookup['status'] ?? '') === 'ambiguous'): ?>
                        <?= count($referenceMatchingListIds) ?> active records match the local business fields. Verification is blocked until the duplicate QuickBooks records are resolved.
                    <?php elseif (($referenceLookup['type'] ?? '') === 'item'): ?>
                        Select only the item you have independently confirmed represents this local product. The selected active ListID and company are rechecked before saving; the local Item Code is not used as a matching field.
                    <?php endif; ?>
                </p>
                <?php foreach (($referenceLookup['records'] ?? []) as $candidate): ?>
                    <?php $candidateStatus = qb_export_name_candidate_status(
                        (string)$referenceLookup['type'],
                        is_array($referenceLookup['search_names'] ?? null) ? $referenceLookup['search_names'] : [],
                        $candidate
                    ); ?>
                    <div class="border rounded p-3 mb-2">
                        <dl class="row mb-2">
                            <dt class="col-sm-3">QuickBooks Name</dt>
                            <dd class="col-sm-9"><?= e($candidate['name']) ?></dd>
                            <dt class="col-sm-3">FullName</dt>
                            <dd class="col-sm-9"><?= e($candidate['fullName']) ?></dd>
                            <?php if ($referenceLookup['type'] === 'customer'): ?>
                                <dt class="col-sm-3">CompanyName</dt>
                                <dd class="col-sm-9"><?= e($candidate['companyName'] ?? '(not provided)') ?></dd>
                            <?php else: ?>
                                <dt class="col-sm-3">Local Item Code (source unverified)</dt>
                                <dd class="col-sm-9"><?= e($referenceLookup['local_item_code'] ?? '') ?></dd>
                                <dt class="col-sm-3">Manufacturer Part Number (not treated as Item Code)</dt>
                                <dd class="col-sm-9"><?= e($candidate['manufacturerPartNumber'] ?? '(not provided)') ?></dd>
                                <dt class="col-sm-3">SalesDesc</dt>
                                <dd class="col-sm-9"><?= e($candidate['salesDescription'] ?? '(not provided)') ?></dd>
                            <?php endif; ?>
                            <dt class="col-sm-3">Active status</dt>
                            <dd class="col-sm-9"><?= is_bool($candidate['active'] ?? null) ? ($candidate['active'] ? 'Active' : 'Inactive') : 'Unknown' ?></dd>
                            <dt class="col-sm-3">Match status</dt>
                            <dd class="col-sm-9"><?= e(str_replace('_', ' ', $candidateStatus)) ?></dd>
                        </dl>
                        <?php if (
                            $referenceLookup['type'] === 'customer'
                            && $candidateStatus === 'verified_candidate'
                            && count($referenceMatchingListIds) === 1
                            && $referenceMatchingListIds[0] === $candidate['listId']
                        ): ?>
                            <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_verification.php">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="reference_type" value="<?= e($referenceLookup['type']) ?>">
                                <input type="hidden" name="local_id" value="<?= (int)$referenceLookup['local_id'] ?>">
                                <input type="hidden" name="candidate_list_id" value="<?= e($candidate['listId']) ?>">
                                <button class="btn btn-sm btn-primary" type="submit">Verify this unique active match</button>
                                <span class="form-text ms-2">Rechecks the QuickBooks reference and signed business-field evidence. No QuickBooks data is changed.</span>
                            </form>
                        <?php elseif (
                            $referenceLookup['type'] === 'item'
                            && $candidateStatus === 'item_code_source_unverified'
                        ): ?>
                            <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_verification.php" class="border-top pt-3">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="reference_type" value="item">
                                <input type="hidden" name="local_id" value="<?= (int)$referenceLookup['local_id'] ?>">
                                <input type="hidden" name="candidate_list_id" value="<?= e($candidate['listId']) ?>">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="confirm-qb-item-<?= e($candidate['listId']) ?>" name="confirm_item_mapping" value="yes" required>
                                    <label class="form-check-label" for="confirm-qb-item-<?= e($candidate['listId']) ?>">
                                        I independently confirmed that this exact QuickBooks item represents the selected local product. This does not map or equate the local Item Code.
                                    </label>
                                </div>
                                <button class="btn btn-sm btn-primary" type="submit">Save Admin-selected item mapping</button>
                                <span class="form-text ms-2">Rechecks this active ListID and company before saving signed evidence. No QuickBooks data is changed.</span>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<section class="panel mb-4" id="quickbooks-diagnostic-readiness">
    <div class="panel-header">
        <div>
            <h3>Read-only mapping readiness</h3>
            <p>Diagnostics and reference checks are read-only. Neither invoice nor sales-order transactions can be submitted from this application.</p>
        </div>
    </div>
    <div class="panel-body">
        <div class="alert alert-warning" role="status">
            Reference verification is determined per pending preview by current signed read-only checks. Bridge health does not open a QuickBooks company session, and no name match or SDK capability is treated as company-specific verification.
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr><th scope="col">Area</th><th scope="col">Status</th><th scope="col">Evidence / next action</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">Company identity</th>
                        <td>
                            <?php if (!empty($companyDiagnostic['company_identity_verified'])): ?>
                                Verified for request at <?= e((string)$companyDiagnostic['checked_at']) ?>
                            <?php elseif (!empty($lastCompanyIdentityDiagnostic['checked_at'])): ?>
                                Last verified at <?= e((string)$lastCompanyIdentityDiagnostic['checked_at']) ?>; not a current-session guarantee
                            <?php else: ?>
                                Unverified — run the authenticated check below
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $actualCompanyIdentity = $companyDiagnostic['company_identity']
                                ?? $lastCompanyIdentityDiagnostic['company_identity']
                                ?? null;
                            ?>
                            <?php if (is_array($actualCompanyIdentity)): ?>
                                Active company reported by QuickBooks: <?= e((string)($actualCompanyIdentity['companyName'] ?? '')) ?>
                                (legal name: <?= e((string)($actualCompanyIdentity['legalCompanyName'] ?? '')) ?>).
                            <?php endif; ?>
                            The signed fingerprint binds the configured file path to QuickBooks CompanyName and LegalCompanyName. An administrator must still compare these names with the intended accounting company; the fingerprint does not prove that replacement contents at the same path are correct.
                            <?php if (!getenv('WQ_QB_COMPANY_FINGERPRINT')): ?>
                                Expected company fingerprint is not configured.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Customer</th>
                        <td>Admin verification required per local customer</td>
                        <td>Unique active exact-name matches may be approved above. The selected invoice preflight checks current signed evidence against the expected company identity.</td>
                    </tr>
                    <tr>
                        <th scope="row">Item / SKU</th>
                        <td>Explicit Admin selection required per product</td>
                        <td>Review bounded candidates and confirm the exact item. Local Item Code is not equated with a QuickBooks field; transaction lines are not sent.</td>
                    </tr>
                    <tr>
                        <th scope="row">Site</th>
                        <td>Blocked — no mapping is stored</td>
                        <td>QBXML 16.0 documents line-level <code>InventorySiteRef</code>. An Admin can request an exact <code>FullName</code> diagnostic below; even a match is not saved or treated as a verified mapping.</td>
                    </tr>
                    <tr>
                        <th scope="row">U/M</th>
                        <td>Blocked — local U/M compatibility unverified</td>
                        <td>QBXML 16.0 documents line-level <code>UnitOfMeasure</code>. A read-only query can show the stored QuickBooks item's unit set and sales default, but does not prove that the local U/M maps to it.</td>
                    </tr>
                    <tr>
                        <th scope="row">Lot Number</th>
                        <td>Blocked — company support unverified</td>
                        <td>QBXML 16.0 documents line-level <code>LotNumber</code> (maximum 40 characters). Local storage allows up to 100 characters; values must not be truncated. Company preference diagnostics can report lot tracking, but item-level behavior remains unverified.</td>
                    </tr>
                    <tr>
                        <th scope="row">Expiration Date</th>
                        <td>Blocked</td>
                        <td>No native InvoiceLine expiration field is documented. Invoice custom-field definitions and the company expiration preference can be queried, but no transaction-writing mechanism is available.</td>
                    </tr>
                    <tr>
                        <th scope="row">Tax</th>
                        <td>Blocked — mapping and calculation unverified</td>
                        <td>
                            Local invoice choices:
                            <?php if ($localTaxChoices): ?>
                                <?= implode(', ', array_map(
                                    static fn(array $choice): string => e((string)$choice['tax_code']) . ' (' . (int)$choice['line_count'] . ' lines)',
                                    $localTaxChoices
                                )) ?>.
                            <?php else: ?>
                                none currently stored.
                            <?php endif; ?>
                            The local <code>Tax</code>/<code>Non</code> labels are not evidence of QuickBooks tax compatibility. Read-only company tax preferences and any stored tax-code ListIDs are queryable below; exact code mapping, tax applicability, calculation, and rounding require verification against the intended company and accounting confirmation.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Invoice reference number</th>
                        <td>Blocked — reference limit mismatch</td>
                        <td>The installed QBXML 16.0 schema documents an 11-character QuickBooks Desktop <code>RefNumber</code> limit. Preview validation blocks longer source values; they are not rewritten or truncated.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
<section class="panel mb-4" id="quickbooks-company-diagnostics">
    <div class="panel-header">
        <div>
            <h3>Authenticated read-only company diagnostics</h3>
            <p>These explicit Admin POST actions open a company-bound SDK session. They never alter QuickBooks records or mark a mapping verified.</p>
        </div>
    </div>
    <div class="panel-body">
        <div class="alert alert-secondary" role="status">
            Requests require the bridge secret and expected company fingerprint to be configured independently for PHP and the bridge. Site uses exact QuickBooks <code>FullName</code>; item U/M uses only a stored ListID, never a local SKU.
        </div>
        <h4>Connection setup status</h4>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead><tr><th scope="col">Requirement</th><th scope="col">PHP runtime status</th><th scope="col">Next step</th></tr></thead>
                <tbody>
                    <tr>
                        <th scope="row">PHP bridge URL</th>
                        <td><?= e($quickBooksConfiguration['bridge_url']) ?></td>
                        <td><?= $quickBooksConfiguration['bridge_url_valid'] ? 'The PHP runtime targets the required IPv4 loopback endpoint.' : 'Correct the protected local configuration and restart Apache through start-apache-with-qb-config.ps1.' ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Shared bridge authentication</th>
                        <td><?= $quickBooksConfiguration['bridge_secret'] ? 'Configured (minimum length met)' : 'Missing or too short' ?></td>
                        <td><?= !empty($bridge['available']) ? 'Signed bridge health succeeded from this PHP runtime; secret values are never displayed.' : 'The PHP and bridge processes must load the same protected secret. Secret values are never displayed here.' ?></td>
                    </tr>
                    <tr>
                        <th scope="row">PHP verification secret</th>
                        <td><?= $quickBooksConfiguration['verification_secret'] ? 'Configured (minimum length met)' : 'Missing or too short' ?></td>
                        <td>Used by PHP to sign human-approved mapping evidence; it is separate from the bridge authentication secret.</td>
                    </tr>
                    <tr>
                        <th scope="row">Expected company fingerprint</th>
                        <td><?= $quickBooksConfiguration['company_fingerprint'] ? 'Configured (format valid)' : 'Missing or invalid' ?></td>
                        <td>Set only after visually confirming Testing QB.qbw and reviewing the read-only SDK identity. PHP and bridge independently enforce it.</td>
                    </tr>
                    <tr>
                        <th scope="row">QuickBooks Desktop process</th>
                        <td>
                            <?php if (array_key_exists('quickBooksProcessRunning', $bridgeHealth)): ?>
                                <?= $bridgeHealth['quickBooksProcessRunning'] ? 'Running' : 'Not running' ?>
                            <?php else: ?>
                                Unknown until authenticated bridge health succeeds
                            <?php endif; ?>
                        </td>
                        <td>A running process does not confirm the open company or SDK authorization.</td>
                    </tr>
                    <tr>
                        <th scope="row">SDK authorization / company identity</th>
                        <td><?= !empty($companyDiagnostic['company_identity_verified']) ? 'Read-only SDK identity query succeeded' : 'Not verified; run the explicit company check' ?></td>
                        <td><?php if (!empty($companyDiagnostic['diagnostic_code'])): ?>Last check: <code><?= e((string)$companyDiagnostic['diagnostic_code']) ?></code>.<?php endif; ?> If the SDK reports <code>SDK_COM_80040418</code>, the built-in QuickBooks Admin must authorize first-time access in QuickBooks; do not bypass it.</td>
                    </tr>
                    <tr>
                        <th scope="row">Company fingerprint match</th>
                        <td><?= !empty($companyDiagnostic['company_identity_verified']) ? 'Matched for the explicit signed diagnostic' : 'Not verified in this request' ?></td>
                        <td>A successful signed identity diagnostic is checked against the PHP-configured expected fingerprint. Re-run it for the currently open company.</td>
                    </tr>
                    <tr>
                        <th scope="row">QuickBooks transaction submission</th>
                        <td>Not supported</td>
                        <td>The bridge exposes only health, validation, and diagnostic operations. No InvoiceAdd or SalesOrderAdd operation is available.</td>
                    </tr>
                    <tr>
                        <th scope="row">Authenticated bridge health</th>
                        <td>
                            <?php if (!empty($bridge['available'])): ?>
                                Responding; signed authentication succeeded
                            <?php else: ?>
                                Not verified<?= !empty($bridge['diagnostic_code']) ? ' (' . e((string)$bridge['diagnostic_code']) . ')' : '' ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            Health does not open a QuickBooks company session. The bridge reports transaction writes as unsupported.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Export record behavior</th>
                        <td>Pending preview only</td>
                        <td>Saving a preview does not queue or post a QuickBooks transaction, and no transaction-writing endpoint is available.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="connection">
                    <button class="btn btn-primary" type="submit">Check active QuickBooks company and session</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="preferences">
                    <button class="btn btn-outline-primary" type="submit">Read company tax, inventory, and site preferences</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="custom_fields">
                    <button class="btn btn-outline-primary" type="submit">Read invoice custom-field definitions</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php" class="d-flex gap-2">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="site">
                    <label class="visually-hidden" for="qb-diagnostic-site">Local Site</label>
                    <select class="form-select" name="site_id" id="qb-diagnostic-site" required>
                        <option value="">Choose local Site</option>
                        <?php foreach ($diagnosticSites as $site): ?>
                            <option value="<?= (int)$site['id'] ?>"><?= e((string)$site['site_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit">Look up exact Site FullName</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php" class="d-flex gap-2">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="uom">
                    <label class="visually-hidden" for="qb-diagnostic-product">Product</label>
                    <select class="form-select" name="product_id" id="qb-diagnostic-product" required>
                        <option value="">Choose product with stored ListID</option>
                        <?php foreach ($diagnosticProducts as $product): ?>
                            <option value="<?= (int)$product['id'] ?>"><?= e((string)$product['product_name']) ?> (ListID on file)</option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit">Read item U/M set</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="tax">
                    <button class="btn btn-outline-primary" type="submit">Read tax preferences, default tax item, and stored tax codes</button>
                    <small class="form-text">Queries only ListIDs already present in the local tax-code verification table.</small>
                </form>
            </div>
        </div>
        <?php if ($companyDiagnostic !== null): ?>
            <div class="mt-4 alert <?= !empty($companyDiagnostic['available']) ? 'alert-info' : 'alert-warning' ?>" role="status">
                <strong><?= e((string)($companyDiagnostic['label'] ?? 'Company diagnostic')) ?>:</strong>
                <?= e((string)($companyDiagnostic['message'] ?? 'No diagnostic result is available.')) ?>
                <?php if (!empty($companyDiagnostic['diagnostic_code'])): ?>
                    <code><?= e((string)$companyDiagnostic['diagnostic_code']) ?></code>
                <?php endif; ?>
                <?php if (!empty($companyDiagnostic['failure_stage'])): ?>
                    <small class="d-block">Failure stage: <code><?= e((string)$companyDiagnostic['failure_stage']) ?></code></small>
                <?php endif; ?>
                <?php if (!empty($companyDiagnostic['sdk_error_code'])): ?>
                    <small class="d-block">Sanitized SDK code: <code><?= e((string)$companyDiagnostic['sdk_error_code']) ?></code></small>
                <?php endif; ?>
                <?php if (!empty($companyDiagnostic['request_id'])): ?>
                    <small class="d-block">Request ID: <code><?= e((string)$companyDiagnostic['request_id']) ?></code></small>
                <?php endif; ?>
                <?php if (!empty($companyDiagnostic['checked_at'])): ?>
                    <small class="d-block">Checked at: <time datetime="<?= e((string)$companyDiagnostic['checked_at']) ?>"><?= e((string)$companyDiagnostic['checked_at']) ?></time></small>
                <?php endif; ?>
            </div>
            <?php if (!empty($companyDiagnostic['available']) || !empty($companyDiagnostic['records'])): ?>
                <?php if (is_array($companyDiagnostic['localContext'] ?? null)): ?>
                    <p class="small text-muted">Local values for manual review only:</p>
                    <pre class="small border rounded p-3 bg-light"><?= e(json_encode($companyDiagnostic['localContext'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre>
                <?php endif; ?>
                <?php if (!empty($companyDiagnostic['records'])): ?>
                    <pre class="small border rounded p-3 bg-light mb-0"><?= e(json_encode($companyDiagnostic['records'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre>
                <?php else: ?>
                    <p class="small text-muted mb-0">No matching company records or definitions were returned. This remains diagnostic evidence only.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
    <div class="small text-muted px-3 pb-3">
        QuickBooks integration is structurally read-only: it supports health, validation, and diagnostics only. Saved export records remain pending previews; this screen cannot submit transactions.
    </div>
</details>
<?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($loadError) ?></div><?php endif; ?>
<section class="panel mb-4" id="export-transactions">
    <div class="panel-header">
        <div>
            <h3>Export Transactions</h3>
            <p><?= count($availableTransactions) ?> available source transaction(s); saved records are previews only</p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th scope="col">Reference Number</th>
                    <th scope="col">Transaction Type</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Date</th>
                    <th scope="col">Line Subtotal</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$availableTransactions): ?>
                    <tr><td colspan="7" class="text-muted">No prepared transactions are available.</td></tr>
                <?php endif; ?>
                <?php foreach ($availableTransactions as $item): ?>
                    <tr>
                        <td><?= e($item['reference_number'] ?: '(unnumbered)') ?></td>
                        <td><?= e($item['type_label']) ?></td>
                        <td><?= e($item['client_name']) ?></td>
                        <td><?= e($item['transaction_date']) ?></td>
                        <td><?= e((string)$item['line_subtotal']) ?></td>
                        <td><?= e($item['status']) ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="?type=<?= e($item['transaction_type']) ?>&amp;id=<?= (int)$item['id'] ?>#transaction-preview">Review</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php if ($preview): $transaction = $preview['transaction']; ?>
    <section class="panel mb-4" id="transaction-preview">
        <div class="panel-header">
            <div>
                <h3>Transaction Preview</h3>
                <p><?= $type === 'invoice' ? 'Invoice' : 'Sales Order' ?> <?= e($transaction['reference_number'] ?: '(unnumbered)') ?></p>
            </div>
            <span class="status-pill <?= $preview['valid'] ? 'status-success' : 'status-danger' ?>">
                <?= $preview['valid'] ? 'Validation passed' : count($preview['errors']) . ' issue(s) to resolve' ?>
            </span>
        </div>
        <div class="panel-body">
            <?php if ($transaction): ?>
                <?php if (($transaction['preparation_mode'] ?? null) === 'quickbooks_managed_defaults'): ?>
                    <div class="alert alert-info py-2" role="status">
                        QuickBooks-managed defaults mode: tax references are omitted, but live tax behavior is unverified.
                        Unit defaults are used only when the item has no unit set; configured unit sets require an exact sales-unit match.
                        Prices shown are local invoice values; zero prices are blocked until explicitly approved.
                        Inventory Site must resolve to the exact active QuickBooks FullName.
                    </div>
                <?php endif; ?>
                <dl class="row qb-transaction-meta">
                    <dt class="col-sm-3 col-lg-2">Customer</dt>
                    <dd class="col-sm-9 col-lg-4">
                        <?= e($transaction['customer']) ?>
                        <?php if (!empty($transaction['customer_full_name'])): ?>
                            <span class="text-muted">— QuickBooks FullName candidate: <?= e($transaction['customer_full_name']) ?></span>
                        <?php endif; ?>
                        <span class="text-muted">(<?= e($transaction['customer_mapping_status']) ?>)</span>
                    </dd>
                    <dt class="col-sm-3 col-lg-2"><?= $type === 'invoice' ? 'Invoice number' : 'Order number' ?></dt>
                    <dd class="col-sm-9 col-lg-4"><?= e($transaction['reference_number'] ?: '—') ?></dd>
                    <dt class="col-sm-3 col-lg-2">Date</dt>
                    <dd class="col-sm-9 col-lg-4"><?= e($transaction['transaction_date']) ?></dd>
                    <dt class="col-sm-3 col-lg-2">P.O. number</dt>
                    <dd class="col-sm-9 col-lg-4"><?= e($transaction['po_number'] ?? '—') ?></dd>
                    <?php if (trim((string)($transaction['memo'] ?? '')) !== ''): ?>
                        <dt class="col-sm-3 col-lg-2">Memo</dt>
                        <dd class="col-sm-9 col-lg-10"><?= e($transaction['memo']) ?></dd>
                    <?php endif; ?>
                </dl>
                <div class="table-responsive">
                    <table class="table table-sm qb-preview-lines">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Description</th>
                                <th>Item Code<?= $type === 'invoice' ? ' (local only)' : '' ?></th>
                                <th>Quantity</th>
                                <th>U/M</th>
                                <th>Effective Unit Price</th>
                                <th>Line Amount</th>
                                <th>Site</th>
                                <th>Tax</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($transaction['lines'] as $line): ?><tr>
                                    <td>
                                        <?= e($line['product_name']) ?>
                                        <small class="d-block text-muted"><?= e($line['item_mapping_status']) ?></small>
                                        <?php if (!empty($line['item_full_name'])): ?>
                                            <small class="d-block text-muted">QuickBooks FullName candidate: <?= e($line['item_full_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($line['description']) ?></td>
                                    <td><?= e($line['item_code'] ?? '—') ?></td>
                                    <td><?= e($line['quantity']) ?></td>
                                    <td>
                                        <?= e($line['unit_of_measure'] ?? '—') ?>
                                        <small class="d-block text-muted"><?= e($line['unit_mapping_status']) ?></small>
                                    </td>
                                    <td>
                                        <?= e(trim((string)$line['rate']) !== '' ? $line['rate'] : '—') ?>
                                        <small class="d-block text-muted"><?= e($line['pricing_status']) ?></small>
                                    </td>
                                    <td><?= e($line['expected_amount'] ?? '—') ?></td>
                                    <td>
                                        <?= e($line['site'] ?? '—') ?>
                                        <small class="d-block text-muted"><?= e($line['site_mapping_status']) ?></small>
                                        <?php if (!empty($line['lot_number']) || !empty($line['expiration_date'])): ?>
                                            <small class="d-block text-muted">
                                                Lot: <?= e($line['lot_number'] ?? '—') ?> · Expiry: <?= e($line['expiration_date'] ?? '—') ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (($transaction['preparation_mode'] ?? null) === 'quickbooks_managed_defaults'): ?>
                                            <?= e($line['tax_mapping_status']) ?>
                                        <?php else: ?>
                                            <?= e($line['tax_code'] ?? '(missing)') ?> — <?= e($line['tax_mapping_status']) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr><?php endforeach; ?></tbody>
                    </table>
                </div>
                <div class="qb-preview-total">
                    <span>Line subtotal</span>
                    <strong><?= e($transaction['expected_subtotal']) ?></strong>
                </div>
                <p class="form-text mb-0">Tax / total: <?= e($transaction['tax_total_status']) ?><?= $type === 'sales_order' ? '; total unresolved' : '' ?></p>
            <?php endif; ?>
            <h4 class="h6 mt-4">Validation and Actions</h4>
            <?php if ($preview['errors']): ?>
                <ul class="mb-0">
                    <?php foreach ($preview['errors'] as $error): ?><li><code><?= e($error['path']) ?></code> — <?= e($error['message']) ?> <small>(<?= e($error['code']) ?>)</small></li><?php endforeach; ?>
                </ul><?php else: ?>
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_export_queue.php" class="mt-3">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="transaction_type" value="<?= e($type) ?>">
                    <input type="hidden" name="source_id" value="<?= (int)$sourceId ?>">
                    <input type="hidden" name="source_revision" value="<?= e($preview['source_revision']) ?>">
                    <button class="btn btn-outline-primary" type="submit">Save pending preview record</button>
                    <span class="form-text ms-2">Saves a pending snapshot only; FullName candidates are checked against the current company before saving.</span>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php if (is_array($selectedExport)): ?>
    <section class="panel mb-4" id="selected-export">
        <div class="panel-header">
            <div>
                <h3>Saved export review</h3>
                <p>Export <?= e($selectedExport['export_id']) ?> — <?= e($selectedExport['transaction_type']) ?> — <?= e($selectedExport['status']) ?></p>
            </div>
            <?php if ($selectedExport['status'] === 'succeeded'): ?>
                <span class="status-pill status-success">QuickBooks confirmed TxnID <?= e($selectedExport['qb_txn_id'] ?? 'missing') ?></span>
            <?php elseif ($selectedExport['status'] === 'unknown' || $selectedExport['status'] === 'processing'): ?>
                <span class="status-pill status-danger">Reconciliation required — do not retry</span>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <?php if (is_array($selectedSnapshot) && ($selectedSnapshot['transaction_type'] ?? null) === 'invoice'): ?>
                <dl class="row">
                    <dt class="col-sm-3">Invoice reference</dt>
                    <dd class="col-sm-9"><?= e($selectedSnapshot['reference_number'] ?? '') ?></dd>
                    <dt class="col-sm-3">Customer</dt>
                    <dd class="col-sm-9">
                        <?= e($selectedSnapshot['customer'] ?? '') ?>
                        (<?= e($selectedSnapshot['customer_full_name'] ?? $selectedSnapshot['customer_list_id'] ?? '') ?>)
                    </dd>
                    <dt class="col-sm-3">Date / subtotal</dt>
                    <dd class="col-sm-9"><?= e($selectedSnapshot['transaction_date'] ?? '') ?> / <?= e($selectedSnapshot['expected_subtotal'] ?? '') ?></dd>
                    <dt class="col-sm-3">Tax treatment</dt>
                    <dd class="col-sm-9"><?= e($selectedSnapshot['tax_total_status'] ?? 'QuickBooks tax calculation is subject to live verification.') ?></dd>
                </dl>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Line</th><th>QuickBooks item</th><th>Description</th><th>Qty</th><th>Effective rate</th><th>Price source</th><th>Amount</th><th>Tax code</th><th>Site / lot / U/M</th></tr></thead>
                        <tbody>
                        <?php foreach (($selectedSnapshot['lines'] ?? []) as $line): ?>
                            <tr>
                                <td><?= (int)($line['line'] ?? 0) ?></td>
                                <td><?= e($line['item_full_name'] ?? $line['item_list_id'] ?? '') ?></td>
                                <td><?= e($line['description'] ?? '') ?></td>
                                <td><?= e($line['quantity'] ?? '') ?></td>
                                <td><?= e($line['rate'] ?? '—') ?></td>
                                <td><?= e($line['pricing_status'] ?? 'Price source unresolved') ?></td>
                                <td><?= e($line['expected_amount'] ?? '') ?></td>
                                <td><?= e($line['tax_list_id'] ?? '') ?></td>
                                <td><?= e($line['site'] ?? '') ?> / <?= e($line['lot_number'] ?? '—') ?> / <?= e($line['unit_of_measure'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-danger" role="alert">The persisted invoice snapshot is invalid or unavailable. The preview cannot be verified.</div>
            <?php endif; ?>

            <?php if ($selectedExport['status'] === 'pending' && $selectedExport['transaction_type'] === 'invoice'): ?>
                <h4 class="h6 mt-4">Read-only QuickBooks reference checks</h4>
                <?php if (!empty($referencePreflight['errors'])): ?>
                    <ul><?php foreach ($referencePreflight['errors'] as $error): ?>
                        <li><code><?= e($error['code'] ?? 'PREFLIGHT') ?></code> — <?= e($error['message'] ?? 'QuickBooks reference check is incomplete.') ?></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>
                <?php if ($referencePreflight['ready'] ?? false): ?>
                    <div class="alert alert-success" role="status">The saved references passed the current read-only QuickBooks checks. The export remains pending; no transaction was created.</div>
                <?php else: ?>
                    <div class="alert alert-info" role="status">Reference verification is incomplete or unavailable. The saved export remains a pending preview; a failed check does not prove a reference is missing.</div>
                <?php endif; ?>
                <div class="alert alert-secondary" role="status">Transaction submission is not supported. The immutable snapshot and pending status are retained; this page has no send or approval action.</div>
            <?php elseif (in_array($selectedExport['status'], ['unknown', 'processing'], true)): ?>
                <div class="alert alert-warning" role="alert">This is a historical transaction status from before transaction submission was retired. Existing status and audit data are retained and are not changed by this read-only application.</div>
            <?php endif; ?>
            <?php if ($selectedAttempts): ?>
                <h4 class="mt-3">Historical attempt records</h4>
                <p class="small text-muted">Existing records are retained for audit; this application cannot create new transaction attempts.</p>
                <ul><?php foreach ($selectedAttempts as $attempt): ?>
                    <li><?= e($attempt['started_at']) ?> — <?= e($attempt['outcome']) ?><?= $attempt['diagnostic_code'] ? ' (' . e($attempt['diagnostic_code']) . ')' : '' ?><?= $attempt['diagnostic_message'] ? ': ' . e($attempt['diagnostic_message']) : '' ?></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<details class="qb-export-history mb-4">
    <summary>Recent export records (<?= count($exports) ?>)</summary>
<section class="panel mb-0">
    <div class="panel-header">
        <div>
            <h3>Typed export records</h3>
            <p><?= count($exports) ?> recent record(s). Unknown outcomes are never retried automatically.</p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Export ID</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>QB TxnID</th>
                    <th>QB RefNumber</th>
                    <th>Created</th>
                    <th>Review</th>
                </tr>
            </thead>
            <tbody><?php if (!$exports): ?><tr>
                        <td colspan="8">No typed export records.</td>
                    </tr><?php endif; ?>
                <?php foreach ($exports as $export): ?><tr>
                        <td><?= e($export['export_id']) ?></td>
                        <td><?= e($export['transaction_type']) ?></td>
                        <td><?= (int)$export['source_transaction_id'] ?></td>
                        <td><?= e($export['status']) ?></td>
                        <td><?= e($export['qb_txn_id'] ?? '—') ?></td>
                        <td><?= e($export['qb_ref_number'] ?? '—') ?></td>
                        <td><?= e($export['created_at']) ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="?export_id=<?= rawurlencode($export['export_id']) ?>#selected-export">Review</a></td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</section>
    </details>
</div>
<script>
    (() => {
        const diagnostics = document.querySelector('.qb-advanced-diagnostics');
        if (!diagnostics) return;
        if (['#advanced-diagnostics', '#reference-verification', '#quickbooks-company-diagnostics'].includes(window.location.hash)) {
            diagnostics.open = true;
        }
        document.querySelectorAll('a[href="#advanced-diagnostics"]').forEach((link) => {
            link.addEventListener('click', () => {
                diagnostics.open = true;
            });
        });
    })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>