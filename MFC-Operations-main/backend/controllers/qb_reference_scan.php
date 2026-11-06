<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/quickbooks_exports.php';
require_once __DIR__ . '/../core/quickbooks_bridge_client.php';
require_login();
require_admin();

$redirect = APP_BASE_URL . '/frontend/quickbooks_exports.php#reference-scan';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}
verify_csrf();

$scan = ['available' => false, 'message' => '', 'categories' => [], 'matches' => []];
$pdo = db();
$locked = false;
try {
    if (($_POST['confirm_company'] ?? null) !== 'Testing QB.qbw') {
        throw new DomainException('Confirm that Testing QB.qbw is currently open before scanning.');
    }
    $lock = $pdo->query("SELECT GET_LOCK('mfc_operations_qb_reference_scan', 0)")->fetchColumn();
    if ((string)$lock !== '1') {
        throw new DomainException('Another QuickBooks reference scan is active. Wait for it to finish before starting another.');
    }
    $locked = true;

    $sites = $pdo->query('SELECT site_name FROM sites WHERE active=1 ORDER BY site_name LIMIT 21')->fetchAll(PDO::FETCH_COLUMN);
    $products = $pdo->query(
        "SELECT id, product_name, qb_description, qb_list_id, u_m
         FROM products WHERE active=1 ORDER BY product_name LIMIT 101"
    )->fetchAll();
    $savedTaxListIds = $pdo->query(
        "SELECT DISTINCT qb_list_id FROM qb_export_reference_verifications
         WHERE reference_type='tax_code' AND active=1 ORDER BY qb_list_id LIMIT 21"
    )->fetchAll(PDO::FETCH_COLUMN);
    $localTaxChoices = $pdo->query(
        'SELECT tax_code FROM invoice_items WHERE tax_code IS NOT NULL AND TRIM(tax_code) <> ""
         GROUP BY tax_code ORDER BY tax_code LIMIT 21'
    )->fetchAll(PDO::FETCH_COLUMN);

    $localCustomers = $pdo->query(
        'SELECT id, client_name, qb_list_id FROM clients WHERE active=1 ORDER BY client_name LIMIT 101'
    )->fetchAll();
    $siteNames = array_values(array_slice(array_unique(array_filter($sites, 'is_string')), 0, 20));
    $itemListIds = [];
    $allItemListIds = [];
    foreach ($products as $product) {
        $id = trim((string)($product['qb_list_id'] ?? ''));
        if ($id !== '' && !in_array($id, $allItemListIds, true)) {
            $allItemListIds[] = $id;
        }
    }
    $itemListIds = array_slice($allItemListIds, 0, 20);
    $taxCodeListIds = array_values(array_slice(array_unique(array_filter($savedTaxListIds, 'is_string')), 0, 20));
    $client = new QuickBooksBridgeClient();
    $result = $client->scanReferences($siteNames, $itemListIds, $taxCodeListIds);
    if (!$result['available']) {
        $scan['message'] = (string)($result['message'] ?? 'The signed QuickBooks reference scan could not be completed.');
        $scan['diagnostic_code'] = $result['diagnostic_code'] ?? 'SCAN_UNAVAILABLE';
        $scan['failure_stage'] = $result['failure_stage'] ?? null;
        $scan['request_id'] = $result['request_id'] ?? null;
    } else {
        $response = $result['response'];
        $categories = [];
        foreach ($response['records'] as $category) {
            $categories[$category['category']] = $category;
        }
        $customerRecords = $categories['customers']['records'] ?? [];
        $itemRecords = $categories['items']['records'] ?? [];
        $customerScanStatus = $categories['customers']['status'] ?? 'failed';
        $itemScanStatus = $categories['items']['status'] ?? 'failed';
        $matches = [];
        foreach (array_slice($localCustomers, 0, 100) as $local) {
            $name = trim((string)$local['client_name']);
            $candidates = array_values(array_filter($customerRecords, static fn(array $record): bool =>
                ($record['name'] ?? null) === $name));
            $mapping = qb_export_reference($pdo, 'customer', 'client:' . (int)$local['id'], (string)$local['qb_list_id']);
            $candidateIds = array_column($candidates, 'listId');
            $status = count($candidates) === 0
                ? 'not_found'
                : (count($candidates) > 1
                    ? 'ambiguous'
                    : (($candidates[0]['active'] ?? null) === false
                        ? 'inactive'
                        : (($candidates[0]['active'] ?? null) === true
                            ? 'exact_candidate_requires_approval'
                            : 'active_status_unknown')));
            if ($mapping && in_array($mapping['qb_list_id'], $candidateIds, true)) {
                $status = count($candidates) > 1
                    ? 'ambiguous_with_verified_mapping'
                    : ($customerScanStatus === 'complete'
                        ? 'verified_mapping_present'
                        : 'verified_mapping_scan_partial');
            } elseif ($mapping) {
                $status = $customerScanStatus === 'complete'
                    ? 'verified_mapping_not_in_scan'
                    : 'verified_mapping_not_found_in_partial_scan';
            } elseif (trim((string)$local['qb_list_id']) !== '') {
                $status = 'existing_list_id_unverified';
            } elseif ($status === 'not_found' && $customerScanStatus !== 'complete') {
                $status = $customerScanStatus === 'failed' ? 'scan_failed' : 'not_found_in_partial_scan';
            } elseif ($status === 'exact_candidate_requires_approval' && $customerScanStatus !== 'complete') {
                $status = 'candidate_not_unique_scan_partial';
            }
            $matches[] = [
                'type' => 'Customer',
                'local_name' => $name,
                'status' => $status,
                'candidates' => $candidates,
            ];
        }
        foreach (array_slice($products, 0, 100) as $local) {
            $names = array_values(array_unique(array_filter([
                trim((string)$local['product_name']),
                trim((string)($local['qb_description'] ?? '')),
            ], static fn(string $name): bool => $name !== '')));
            $candidates = array_values(array_filter($itemRecords, static fn(array $record): bool =>
                in_array($record['name'] ?? null, $names, true)
                    || in_array($record['fullName'] ?? null, $names, true)));
            $mapping = qb_export_reference($pdo, 'item', 'product:' . (int)$local['id'], (string)$local['qb_list_id']);
            $candidateIds = array_column($candidates, 'listId');
            $status = count($candidates) === 0
                ? 'not_found'
                : (count($candidates) > 1
                    ? 'ambiguous'
                    : (($candidates[0]['active'] ?? null) === false
                        ? 'inactive'
                        : (($candidates[0]['active'] ?? null) === true
                            ? 'item_code_source_unverified'
                            : 'active_status_unknown')));
            if ($mapping && in_array($mapping['qb_list_id'], $candidateIds, true)) {
                $status = count($candidates) > 1
                    ? 'ambiguous_with_verified_mapping'
                    : ($itemScanStatus === 'complete'
                        ? 'verified_mapping_present'
                        : 'verified_mapping_scan_partial');
            } elseif ($mapping) {
                $status = $itemScanStatus === 'complete'
                    ? 'verified_mapping_not_in_scan'
                    : 'verified_mapping_not_found_in_partial_scan';
            } elseif (trim((string)$local['qb_list_id']) !== '') {
                $status = 'existing_list_id_unverified';
            } elseif ($status === 'not_found' && $itemScanStatus !== 'complete') {
                $status = $itemScanStatus === 'failed' ? 'scan_failed' : 'not_found_in_partial_scan';
            } elseif ($status === 'item_code_source_unverified' && $itemScanStatus !== 'complete') {
                $status = 'candidate_not_unique_scan_partial';
            }
            $matches[] = [
                'type' => 'Item',
                'local_name' => (string)$local['product_name'],
                'status' => $status,
                'candidates' => $candidates,
            ];
        }
        $siteRecords = $categories['inventory_sites']['records'] ?? [];
        foreach ($siteNames as $siteName) {
            $candidates = array_values(array_filter($siteRecords, static fn(array $record): bool =>
                ($record['fullName'] ?? null) === $siteName));
            $siteScanStatus = $categories['inventory_sites']['status'] ?? 'failed';
            $matches[] = [
                'type' => 'Site',
                'local_name' => $siteName,
                'status' => count($candidates) > 1
                    ? 'ambiguous'
                    : (count($candidates) === 1
                        ? (($candidates[0]['active'] ?? null) === true
                            ? 'exact_active_site_requires_invoice_preflight'
                            : (($candidates[0]['active'] ?? null) === false ? 'inactive' : 'active_status_unknown'))
                        : ($siteScanStatus === 'failed'
                            ? 'scan_failed'
                            : ($siteScanStatus === 'partial' ? 'not_found_in_partial_scan' : 'not_found'))),
                'candidates' => $candidates,
            ];
        }
        $unitRecords = $categories['mapped_item_units']['records'] ?? [];
        foreach (array_slice($products, 0, 100) as $local) {
            $listId = trim((string)($local['qb_list_id'] ?? ''));
            if ($listId === '') {
                $status = 'item_mapping_required';
                $unitRecord = null;
                $mapping = null;
            } elseif (!in_array($listId, $itemListIds, true)) {
                $status = 'not_scanned_limit';
                $unitRecord = null;
                $mapping = null;
            } else {
                $unitRecord = null;
                foreach ($unitRecords as $record) {
                    if (($record['listId'] ?? null) === $listId) {
                        $unitRecord = $record;
                        break;
                    }
                }
                $mapping = qb_export_reference($pdo, 'item', 'product:' . (int)$local['id'], $listId);
                $localUnit = trim((string)($local['u_m'] ?? ''));
                $unitNames = array_merge(
                    array_column(is_array($unitRecord['units'] ?? null) ? $unitRecord['units'] : [], 'name'),
                    array_column(is_array($unitRecord['units'] ?? null) ? $unitRecord['units'] : [], 'abbreviation')
                );
                $unitMatched = $localUnit !== '' && in_array($localUnit, $unitNames, true);
                $status = ($unitRecord['found'] ?? null) !== true
                    ? (($categories['mapped_item_units']['status'] ?? 'failed') === 'failed' ? 'scan_failed' : 'saved_list_id_not_found')
                    : (($unitRecord['active'] ?? null) !== true
                        ? 'inactive_or_unverified_item'
                        : (($unitRecord['unitSetActive'] ?? null) !== true
                            ? 'uom_set_inactive_or_unverified'
                            : ($localUnit === ''
                                ? 'local_uom_not_configured'
                                : ($unitMatched ? 'unit_requires_invoice_preflight' : 'local_uom_not_in_quickbooks_unit_set'))));
                if (!$mapping && $status === 'unit_requires_invoice_preflight') {
                    $status = 'existing_list_id_unverified';
                }
                if (!$mapping && $status === 'local_uom_not_in_quickbooks_unit_set') {
                    $status = 'existing_list_id_unverified_uom_mismatch';
                }
            }
            $matches[] = [
                'type' => 'U/M',
                'local_name' => (string)$local['product_name'] . ' · ' . (string)($local['u_m'] ?? ''),
                'status' => $status,
                'candidates' => $unitRecord ? [$unitRecord] : [],
            ];
        }
        $taxRecords = $categories['saved_tax_codes']['records'] ?? [];
        foreach ($localTaxChoices as $taxCode) {
            $localKey = 'tax:' . (string)$taxCode;
            $mapping = qb_export_reference($pdo, 'tax_code', $localKey, null);
            $candidates = [];
            if ($mapping) {
                $candidates = array_values(array_filter($taxRecords, static fn(array $record): bool =>
                    ($record['listId'] ?? null) === $mapping['qb_list_id']));
            }
            $taxScanStatus = $categories['saved_tax_codes']['status'] ?? 'failed';
            $status = !$mapping
                ? 'tax_mapping_required'
                : (count($candidates) > 1
                    ? 'conflicting_tax_candidates'
                    : (count($candidates) === 1
                        ? (($candidates[0]['found'] ?? null) === true && ($candidates[0]['active'] ?? null) === true
                            ? 'verified_mapping_present'
                            : 'saved_tax_reference_inactive_or_unverified')
                        : ($taxScanStatus === 'failed'
                            ? 'scan_failed'
                            : ($taxScanStatus === 'partial'
                                ? 'verified_mapping_not_found_in_partial_scan'
                                : 'verified_mapping_not_found'))));
            $matches[] = [
                'type' => 'Tax Code',
                'local_name' => (string)$taxCode,
                'status' => $status,
                'candidates' => $candidates,
            ];
        }
        $candidateCounts = [];
        foreach ($matches as $match) {
            foreach ($match['candidates'] as $candidate) {
                $key = $match['type'] . ':' . $candidate['listId'];
                $candidateCounts[$key] = ($candidateCounts[$key] ?? 0) + 1;
            }
        }
        foreach ($matches as &$match) {
            foreach ($match['candidates'] as $candidate) {
                if (($candidateCounts[$match['type'] . ':' . $candidate['listId']] ?? 0) > 1) {
                    $match['status'] = 'conflicting_local_candidates';
                    break;
                }
            }
        }
        unset($match);
        $scan = [
            'available' => true,
            'request_id' => $result['request_id'],
            'company_identity' => $response['companyIdentity'],
            'categories' => array_values($categories),
            'matches' => $matches,
            'local_customer_limit_reached' => count($localCustomers) > 100,
            'local_product_limit_reached' => count($products) > 100,
            'local_site_limit_reached' => count($sites) > 20,
            'saved_item_list_limit_reached' => count($allItemListIds) > 20,
            'saved_tax_code_limit_reached' => count($savedTaxListIds) > 20,
            'local_tax_code_limit_reached' => count($localTaxChoices) > 20,
        ];
    }
} catch (Throwable $error) {
    $scan['message'] = $error instanceof DomainException || $error instanceof InvalidArgumentException
        ? $error->getMessage()
        : 'The QuickBooks reference scan could not be completed. Check the database schema and application log.';
    $scan['diagnostic_code'] = $error instanceof DomainException ? 'SCAN_BLOCKED' : 'SCAN_FAILED';
} finally {
    if ($locked) {
        $pdo->query("SELECT RELEASE_LOCK('mfc_operations_qb_reference_scan')");
    }
}
$_SESSION['_qb_reference_scan'] = $scan;
header('Location: ' . $redirect);
exit;
