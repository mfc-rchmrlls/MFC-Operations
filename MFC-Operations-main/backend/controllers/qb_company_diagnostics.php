<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/quickbooks_exports.php';
require_once __DIR__ . '/../core/quickbooks_bridge_client.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php#quickbooks-company-diagnostics');
    exit;
}
verify_csrf();

$redirect = APP_BASE_URL . '/frontend/quickbooks_exports.php#quickbooks-company-diagnostics';
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$client = new QuickBooksBridgeClient();
$result = [
    'label' => 'Company diagnostic',
    'available' => false,
    'diagnostic_code' => 'DIAGNOSTIC_REQUEST_REJECTED',
    'message' => 'The diagnostic request could not be completed.',
    'records' => [],
];

try {
    if ($action === 'connection') {
        $result['label'] = 'Active company and session check';
        $response = $client->diagnoseCompanyConfiguration('company_preferences');
    } elseif ($action === 'preferences') {
        $result['label'] = 'Company preferences';
        $response = $client->diagnoseCompanyConfiguration('company_preferences');
    } elseif ($action === 'custom_fields') {
        $result['label'] = 'Invoice custom-field definitions';
        $response = $client->diagnoseCompanyConfiguration('invoice_custom_fields');
    } elseif ($action === 'site') {
        $siteId = filter_var($_POST['site_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$siteId) {
            throw new InvalidArgumentException('Choose an active local Site.');
        }
        $statement = db()->prepare('SELECT site_name FROM sites WHERE id=? AND active=1');
        $statement->execute([(int)$siteId]);
        $siteName = $statement->fetchColumn();
        if (!is_string($siteName) || trim($siteName) === '') {
            throw new DomainException('The selected local Site is missing or inactive.');
        }
        $result['label'] = 'Exact inventory-site FullName lookup';
        $result['localContext'] = ['siteName' => $siteName];
        $response = $client->lookupInventorySiteFullName($siteName);
    } elseif ($action === 'uom') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$productId) {
            throw new InvalidArgumentException('Choose an active local product.');
        }
        $statement = db()->prepare('SELECT product_name, qb_list_id, u_m FROM products WHERE id=? AND active=1');
        $statement->execute([(int)$productId]);
        $product = $statement->fetch(PDO::FETCH_ASSOC);
        $listId = is_array($product) ? $product['qb_list_id'] : null;
        if (!is_string($listId) || trim($listId) === '') {
            throw new DomainException('The selected product has no stored QuickBooks ListID. Item Code is not used as a substitute.');
        }
        $result['label'] = 'Item U/M and unit-set lookup';
        $result['localContext'] = [
            'productName' => (string)$product['product_name'],
            'localUnitOfMeasure' => $product['u_m'] === null ? null : (string)$product['u_m'],
            'mappingStatus' => 'diagnostic only; not verified',
        ];
        $response = $client->diagnose('item_uom', [$listId]);
    } elseif ($action === 'tax') {
        $preferences = $client->diagnoseCompanyConfiguration('company_preferences');
        if (!$preferences['available']) {
            $response = $preferences;
        } else {
            $statement = db()->query(
                "SELECT qb_list_id FROM qb_export_reference_verifications
                 WHERE reference_type='tax_code' AND active=1
                 ORDER BY id DESC LIMIT 50"
            );
            $listIds = array_values(array_unique(array_filter(
                $statement->fetchAll(PDO::FETCH_COLUMN),
                static fn($value): bool => is_string($value) && trim($value) !== ''
            ), SORT_STRING));
            $taxCodes = $listIds
                ? $client->diagnose('tax_code', $listIds)
                : ['available' => true, 'response' => ['records' => []], 'message' => 'No stored tax-code ListIDs are available.'];
            $defaultTaxItemId = $preferences['response']['records'][0]['defaultTaxItem']['listId'] ?? null;
            $taxItem = is_string($defaultTaxItemId) && trim($defaultTaxItemId) !== ''
                ? $client->diagnose('tax_item', [$defaultTaxItemId])
                : ['available' => true, 'response' => ['records' => []], 'message' => 'No default tax-item ListID is configured.'];
            $checksAvailable = $taxCodes['available'] && $taxItem['available'];
            $response = [
                'available' => $checksAvailable,
                'request_id' => $preferences['request_id'] ?? qb_export_uuid(),
                'diagnostic_code' => $checksAvailable ? null : ($taxCodes['diagnostic_code'] ?? $taxItem['diagnostic_code'] ?? 'TAX_REFERENCE_QUERY_UNAVAILABLE'),
                'message' => 'Company tax preferences, the default tax item, and stored tax-code references are diagnostic only; they do not verify tax mapping or calculation.',
                'response' => [
                    'records' => [
                        [
                            'section' => 'company_preferences',
                            'status' => 'available',
                            'requestId' => $preferences['request_id'] ?? null,
                            'records' => $preferences['response']['records'] ?? [],
                        ],
                        [
                            'section' => 'default_tax_item',
                            'status' => $taxItem['available'] ? 'available' : 'unavailable',
                            'requestId' => $taxItem['request_id'] ?? null,
                            'diagnosticCode' => $taxItem['diagnostic_code'] ?? null,
                            'message' => $taxItem['message'] ?? null,
                            'records' => $taxItem['response']['records'] ?? [],
                        ],
                        [
                            'section' => 'stored_tax_codes',
                            'status' => $taxCodes['available'] ? 'available' : 'unavailable',
                            'requestId' => $taxCodes['request_id'] ?? null,
                            'diagnosticCode' => $taxCodes['diagnostic_code'] ?? null,
                            'message' => $taxCodes['message'] ?? null,
                            'records' => $taxCodes['response']['records'] ?? [],
                        ],
                    ],
                ],
            ];
        }
        $result['label'] = 'Company tax and reference diagnostics';
    } else {
        throw new InvalidArgumentException('Choose a supported read-only diagnostic.');
    }

    $result['available'] = $response['available'] ?? false;
    $result['diagnostic_code'] = $response['diagnostic_code'] ?? null;
    $result['failure_stage'] = $response['failure_stage'] ?? null;
    $result['sdk_error_code'] = $response['sdk_error_code'] ?? null;
    $result['qbxml_status_code'] = $response['qbxml_status_code'] ?? null;
    $result['qbxml_status_severity'] = $response['qbxml_status_severity'] ?? null;
    $result['qbxml_status_message'] = $response['qbxml_status_message'] ?? null;
    $result['query_operation'] = $response['query_operation'] ?? null;
    $result['message'] = $response['message'] ?? (
        $result['available']
        ? 'The signed diagnostic completed. Results are company-bound but do not establish posting eligibility.'
        : 'The signed diagnostic could not be completed.'
    );
    if (is_array($response['response']['records'] ?? null)) {
        $result['records'] = $response['response']['records'];
    }
    if (is_array($response['response']['companyIdentity'] ?? null)) {
        $result['company_identity'] = $response['response']['companyIdentity'];
        $result['company_identity_verified'] = ($response['available'] ?? false) === true;
    }
    if (isset($response['request_id']) && is_string($response['request_id'])) {
        $result['request_id'] = $response['request_id'];
    }
} catch (DomainException | InvalidArgumentException $error) {
    $result['message'] = $error->getMessage();
} catch (PDOException) {
    $result['message'] = 'The required local diagnostic reference data is unavailable.';
}

$result['checked_at'] = date('c');
if (!empty($result['company_identity_verified'])) {
    $_SESSION['_qb_company_last_success'] = [
        'checked_at' => $result['checked_at'],
        'company_identity' => $result['company_identity'],
    ];
}
$_SESSION['_qb_company_diagnostic'] = $result;
header('Location: ' . $redirect);
exit;
