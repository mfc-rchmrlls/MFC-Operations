<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/quickbooks_exports.php';
require_once __DIR__ . '/../core/quickbooks_bridge_client.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php#reference-verification');
    exit;
}
verify_csrf();

$redirect = APP_BASE_URL . '/frontend/quickbooks_exports.php#reference-verification';
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$type = is_string($_POST['reference_type'] ?? null) ? $_POST['reference_type'] : '';
$localId = filter_var($_POST['local_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pdo = db();

try {
    if (
        !in_array($action, ['lookup', 'verify'], true)
        || !in_array($type, ['customer', 'item'], true)
        || !$localId
    ) {
        throw new InvalidArgumentException('Choose a valid local customer or product.');
    }

    $local = qb_reference_local_record($pdo, $type, (int)$localId);
    $searchNames = qb_reference_search_names($type, $local);
    if (!$searchNames) {
        throw new DomainException($type === 'customer'
            ? 'The local customer name is empty.'
            : 'The local product has no name or invoice Description for a bounded diagnostic.');
    }

    $client = new QuickBooksBridgeClient();
    $lookup = qb_reference_lookup_names($client, $type, $searchNames);
    if (!$lookup['available']) {
        $_SESSION['_qb_reference_lookup'] = [
            'type' => $type,
            'local_id' => (int)$localId,
            'search_names' => $searchNames,
            'search_name' => implode(' / ', $searchNames),
            'local_item_code' => $type === 'item' ? (string)($local['qb_code'] ?? '') : null,
            'status' => 'diagnostic_failed',
            'message' => $lookup['message'] ?? 'The name lookup could not be completed.',
            'diagnostic_code' => $lookup['diagnostic_code'] ?? 'LOOKUP_UNAVAILABLE',
            'records' => [],
        ];
        header('Location: ' . $redirect);
        exit;
    }
    $records = $lookup['response']['records'];
    $candidateSummary = qb_export_name_candidate_summary($type, $searchNames, $records);

    if ($action === 'lookup') {
        $_SESSION['_qb_reference_lookup'] = [
            'type' => $type,
            'local_id' => (int)$localId,
            'search_names' => $searchNames,
            'search_name' => implode(' / ', $searchNames),
            'local_item_code' => $type === 'item' ? (string)($local['qb_code'] ?? '') : null,
            'status' => $candidateSummary['status'],
            'message' => '',
            'diagnostic_code' => null,
            'request_id' => $lookup['request_id'],
            'records' => $records,
        ];
        header('Location: ' . $redirect);
        exit;
    }

    $selectedListId = is_string($_POST['candidate_list_id'] ?? null) ? $_POST['candidate_list_id'] : '';
    if ($type === 'item') {
        if (($_POST['confirm_item_mapping'] ?? null) !== 'yes') {
            throw new DomainException('Explicit Admin confirmation is required to map this local product to the selected QuickBooks item.');
        }
        $candidates = array_values(array_filter(
            $records,
            static fn($record): bool => is_array($record)
                && ($record['listId'] ?? null) === $selectedListId
                && qb_export_name_candidate_status('item', $searchNames, $record) === 'item_code_source_unverified'
        ));
        if (count($candidates) !== 1) {
            throw new DomainException('Select one active QuickBooks item from the bounded lookup results. No Item Code or SKU equivalence is inferred.');
        }
        $candidate = $candidates[0];
    } else {
        if ($candidateSummary['status'] !== 'candidate_found') {
            throw new DomainException($candidateSummary['status'] === 'ambiguous'
                ? 'This lookup is ambiguous: more than one active QuickBooks customer matches the local name. No record was verified.'
                : 'No active QuickBooks customer matches the local customer name.');
        }
        $candidate = $candidateSummary['candidate'];
        if (
            !is_array($candidate)
            || ($candidate['listId'] ?? null) !== $selectedListId
            || qb_export_name_candidate_status($type, $searchNames, $candidate) !== 'verified_candidate'
        ) {
            throw new DomainException('The selected record is missing, ambiguous, inactive, or does not match the local business fields.');
        }
    }

    $diagnostic = $client->diagnose($type, [$selectedListId]);
    if (!$diagnostic['available']) {
        throw new DomainException('The exact QuickBooks record could not be reverified: '
            . ($diagnostic['diagnostic_code'] ?? 'DIAGNOSTIC_UNAVAILABLE'));
    }
    $verifiedRecord = $diagnostic['response']['records'][0] ?? null;
    if (
        !is_array($verifiedRecord)
        || ($verifiedRecord['found'] ?? null) !== true
        || ($verifiedRecord['active'] ?? null) !== true
        || ($verifiedRecord['listId'] ?? null) !== $selectedListId
        || ($verifiedRecord['name'] ?? null) !== $candidate['fullName']
        || ($type === 'item'
            && ($verifiedRecord['manufacturerPartNumber'] ?? null) !== ($candidate['manufacturerPartNumber'] ?? null))
        || qb_export_name_candidate_status($type, $searchNames, $candidate)
            !== ($type === 'item' ? 'item_code_source_unverified' : 'verified_candidate')
    ) {
        throw new DomainException('The exact ListID query did not confirm the same active QuickBooks record.');
    }

    $companyFingerprint = $diagnostic['response']['companyFingerprint'];
    $verificationSecret = getenv('WQ_QB_VERIFICATION_SECRET') ?: '';
    if (
        !preg_match('/^[a-f0-9]{64}$/D', $companyFingerprint)
        || strlen($verificationSecret) < 32
    ) {
        throw new DomainException('Company identity or verification-signing configuration is unavailable.');
    }

    $localKey = ($type === 'customer' ? 'client:' : 'product:') . (int)$localId;
    $verifiedAt = date('Y-m-d H:i:s');
    $verification = [
        'reference_type' => $type,
        'local_key' => $localKey,
        'qb_list_id' => $selectedListId,
        'qb_full_name' => $candidate['fullName'],
        'active' => 1,
        'company_file_fingerprint' => $companyFingerprint,
        'diagnostic_request_id' => $diagnostic['request_id'],
        'verified_at' => $verifiedAt,
        'verified_by' => (int)$_SESSION['user']['id'],
    ];
    $verification['evidence_hmac'] = qb_export_verification_signature($verification, $verificationSecret);

    $pdo->beginTransaction();
    $adminCheck = $pdo->prepare('SELECT id FROM employees WHERE id=? AND role="Admin" AND active=1 FOR UPDATE');
    $adminCheck->execute([(int)$_SESSION['user']['id']]);
    if (!$adminCheck->fetchColumn()) {
        throw new DomainException('An active administrator is required to save QuickBooks verification evidence.');
    }
    $lockedLocal = qb_reference_local_record($pdo, $type, (int)$localId, true);
    $currentSearchNames = qb_reference_search_names($type, $lockedLocal);
    if ($searchNames !== $currentSearchNames) {
        throw new DomainException('The local record name or invoice description changed during verification. Run the lookup again.');
    }
    qb_export_store_reference_verification($pdo, $verification);
    $auditDetails = $type === 'item'
        ? 'Admin explicitly selected an active QuickBooks item from bounded name candidates after reviewing the product mapping; local Item Code was not treated as a QuickBooks field.'
        : 'Admin verified an exact active customer name against the configured company.';
    log_activity($pdo, 'QuickBooks reference verified', $type, (int)$localId, $auditDetails);
    $pdo->commit();
    unset($_SESSION['_qb_reference_lookup']);
    flash('success', $type === 'item'
        ? 'The Admin-selected active QuickBooks item mapping was rechecked and saved for this company. Item Code was not mapped or sent, and no QuickBooks data was changed.'
        : 'The selected active QuickBooks reference was verified and saved. No QuickBooks data was changed.');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $error instanceof DomainException || $error instanceof InvalidArgumentException
        ? $error->getMessage()
        : 'Reference verification could not be saved. Check the database and retry the read-only lookup.');
}

header('Location: ' . $redirect);
exit;

function qb_reference_local_record(PDO $pdo, string $type, int $id, bool $forUpdate = false): array
{
    $lock = $forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    if ($type === 'customer') {
        $query = $pdo->prepare('SELECT id, client_name, qb_list_id, active FROM clients WHERE id=?' . $lock);
    } elseif ($type === 'item') {
        $query = $pdo->prepare('SELECT id, product_name, qb_description, qb_code, qb_list_id, active FROM products WHERE id=?' . $lock);
    } else {
        throw new InvalidArgumentException('Unsupported reference type.');
    }
    $query->execute([$id]);
    $record = $query->fetch();
    if (!$record || (int)$record['active'] !== 1) {
        throw new DomainException('Choose an active local record.');
    }
    return $record;
}

function qb_reference_search_names(string $type, array $local): array
{
    $values = $type === 'customer'
        ? [$local['client_name'] ?? null]
        : [$local['product_name'] ?? null, $local['qb_description'] ?? null];
    $names = [];
    foreach ($values as $value) {
        if (is_string($value)) {
            $name = trim($value);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
    }
    return $names;
}

function qb_reference_lookup_names(QuickBooksBridgeClient $client, string $type, array $searchNames): array
{
    $records = [];
    $companyFingerprint = null;
    $lastRequestId = qb_export_uuid();
    $lastResponse = null;
    foreach ($searchNames as $searchName) {
        $lookup = $client->lookupName($type, $searchName);
        if (!$lookup['available']) {
            return $lookup;
        }
        $response = $lookup['response'];
        if (
            $companyFingerprint !== null
            && !hash_equals($companyFingerprint, $response['companyFingerprint'])
        ) {
            return [
                'available' => false,
                'request_id' => $lookup['request_id'],
                'diagnostic_code' => 'COMPANY_IDENTITY_MISMATCH',
                'message' => 'The name searches did not use the same QuickBooks company file.',
            ];
        }
        $companyFingerprint = $response['companyFingerprint'];
        $lastRequestId = $lookup['request_id'];
        $lastResponse = $response;
        foreach ($response['records'] as $record) {
            $listId = $record['listId'];
            if (isset($records[$listId]) && $records[$listId] !== $record) {
                return [
                    'available' => false,
                    'request_id' => $lookup['request_id'],
                    'diagnostic_code' => 'CONFLICTING_LOOKUP_RECORD',
                    'message' => 'QuickBooks returned conflicting data for the same item/customer reference.',
                ];
            }
            $records[$listId] = $record;
            if (count($records) > 50) {
                return [
                    'available' => false,
                    'request_id' => $lookup['request_id'],
                    'diagnostic_code' => 'TOO_MANY_CANDIDATES',
                    'message' => 'The bounded name lookup returned too many candidates to verify safely.',
                ];
            }
        }
    }
    if (!is_array($lastResponse)) {
        return [
            'available' => false,
            'request_id' => $lastRequestId,
            'diagnostic_code' => 'LOOKUP_NAME_MISSING',
            'message' => 'No local search name was available for the read-only lookup.',
        ];
    }
    $lastResponse['records'] = array_values($records);
    return ['available' => true, 'request_id' => $lastRequestId, 'response' => $lastResponse];
}
