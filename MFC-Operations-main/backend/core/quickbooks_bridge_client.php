<?php

declare(strict_types=1);

require_once __DIR__ . '/quickbooks_exports.php';

final class QuickBooksBridgeClient
{
    private string $baseUrl;
    private ?string $secret;
    private $transport;

    public function __construct(?string $baseUrl = null, ?string $secret = null, ?callable $transport = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (getenv('WQ_QB_BRIDGE_URL') ?: 'http://127.0.0.1:8765'), '/');
        $this->secret = $secret ?? (getenv('WQ_QB_BRIDGE_SECRET') ?: null);
        $this->transport = $transport;
    }

    public function health(): array
    {
        return $this->request('health', ['operation' => 'health']);
    }

    public function diagnose(string $entity, array $listIds): array
    {
        if (
            !in_array($entity, ['customer', 'item', 'tax_code', 'tax_item', 'inventory_site', 'item_uom'], true)
            || count($listIds) < 1
            || count($listIds) > 50
        ) {
            throw new InvalidArgumentException('The diagnostic request has an unsupported entity or page size.');
        }
        foreach ($listIds as $listId) {
            if (
                !is_string($listId)
                || trim($listId) === ''
                || strlen($listId) > 100
                || !preg_match('/^[A-Za-z0-9._:-]+$/D', $listId)
            ) {
                throw new InvalidArgumentException('The diagnostic request contains an invalid QuickBooks ListID.');
            }
        }
        if (count(array_unique($listIds, SORT_STRING)) !== count($listIds)) {
            throw new InvalidArgumentException('The diagnostic request contains duplicate QuickBooks ListIDs.');
        }

        $expectedFingerprint = strtolower(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: '');
        if ($expectedFingerprint === '') {
            return [
                'available' => false,
                'request_id' => qb_export_uuid(),
                'diagnostic_code' => 'COMPANY_FINGERPRINT_NOT_CONFIGURED',
                'message' => 'The expected QuickBooks company fingerprint is not configured.',
            ];
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint)) {
            return [
                'available' => false,
                'request_id' => qb_export_uuid(),
                'diagnostic_code' => 'COMPANY_FINGERPRINT_INVALID',
                'message' => 'The expected QuickBooks company fingerprint is invalid.',
            ];
        }

        return $this->request('diagnose', [
            'operation' => 'diagnose',
            'entity' => $entity,
            'listIds' => array_values($listIds),
        ], 15);
    }

    public function diagnoseCompanyConfiguration(string $entity): array
    {
        if (!in_array($entity, ['company_preferences', 'invoice_custom_fields'], true)) {
            throw new InvalidArgumentException('The company diagnostic does not support this configuration query.');
        }
        $configuration = $this->companyFingerprintConfigurationFailure();
        if ($configuration !== null) {
            return $configuration;
        }
        return $this->request('diagnose', [
            'operation' => 'diagnose',
            'entity' => $entity,
        ], 15);
    }

    public function scanReferences(array $siteNames, array $itemListIds, array $taxCodeListIds): array
    {
        foreach ([
            'site names' => [$siteNames, 255, false],
            'item ListIDs' => [$itemListIds, 100, true],
            'tax-code ListIDs' => [$taxCodeListIds, 100, true],
        ] as $label => [$values, $maximumLength, $listIds]) {
            if (count($values) > 20) {
                throw new InvalidArgumentException('The reference scan contains too many ' . $label . '.');
            }
            $seen = [];
            foreach ($values as $value) {
                if (
                    !is_string($value)
                    || $value === ''
                    || $value !== trim($value)
                    || mb_strlen($value) > $maximumLength
                    || preg_match('/[\x00-\x1F\x7F]/', $value)
                    || ($listIds && !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $value))
                ) {
                    throw new InvalidArgumentException('The reference scan contains an invalid ' . $label . ' value.');
                }
                if (in_array($value, $seen, true)) {
                    throw new InvalidArgumentException('The reference scan contains duplicate ' . $label . '.');
                }
                $seen[] = $value;
            }
        }
        $configuration = $this->companyFingerprintConfigurationFailure();
        if ($configuration !== null) {
            return $configuration;
        }
        return $this->request('diagnose', [
            'operation' => 'diagnose',
            'entity' => 'reference_scan',
            'siteNames' => array_values($siteNames),
            'itemListIds' => array_values($itemListIds),
            'taxCodeListIds' => array_values($taxCodeListIds),
        ], 35);
    }

    public function lookupInventorySiteFullName(string $fullName): array
    {
        if (
            trim($fullName) === ''
            || $fullName !== trim($fullName)
            || mb_strlen($fullName) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $fullName)
        ) {
            throw new InvalidArgumentException('The inventory-site lookup requires an exact FullName of 1 to 255 characters.');
        }
        $configuration = $this->companyFingerprintConfigurationFailure();
        if ($configuration !== null) {
            return $configuration;
        }
        return $this->request('diagnose', [
            'operation' => 'diagnose',
            'entity' => 'inventory_site',
            'searchName' => $fullName,
        ], 15);
    }

    public function lookupName(string $entity, string $name): array
    {
        if (
            !in_array($entity, ['customer', 'item'], true)
            || trim($name) === ''
            || $name !== trim($name)
            || mb_strlen($name) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            throw new InvalidArgumentException('The name lookup requires an exact customer or item name of 1 to 255 characters.');
        }

        $expectedFingerprint = strtolower(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: '');
        if ($expectedFingerprint === '') {
            return [
                'available' => false,
                'request_id' => qb_export_uuid(),
                'diagnostic_code' => 'COMPANY_FINGERPRINT_NOT_CONFIGURED',
                'message' => 'The expected QuickBooks company fingerprint is not configured.',
            ];
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint)) {
            return [
                'available' => false,
                'request_id' => qb_export_uuid(),
                'diagnostic_code' => 'COMPANY_FINGERPRINT_INVALID',
                'message' => 'The expected QuickBooks company fingerprint is invalid.',
            ];
        }

        return $this->request('diagnose', [
            'operation' => 'diagnose',
            'entity' => $entity,
            'searchName' => $name,
        ], 15);
    }

    public function validate(array $request): array
    {
        $type = $request['transactionType'] ?? null;
        if (!in_array($type, ['invoice', 'sales_order'], true)) {
            throw new InvalidArgumentException('Unsupported QuickBooks transaction type.');
        }
        if (($request['operation'] ?? null) !== 'validate' || ($request['validationOnly'] ?? null) !== true) {
            throw new InvalidArgumentException('Only validation-only bridge requests are supported.');
        }
        $allowedKeys = [
            'protocolVersion',
            'requestId',
            'operation',
            'validationOnly',
            'idempotencyKey',
            'transactionType',
            'source',
            'transaction',
        ];
        if (
            array_diff(array_keys($request), $allowedKeys)
            || array_diff(['protocolVersion', 'requestId', 'operation', 'validationOnly', 'idempotencyKey', 'transactionType', 'source', 'transaction'], array_keys($request))
            || ($request['protocolVersion'] ?? null) !== '1'
            || !is_string($request['requestId'] ?? null)
            || !preg_match('/^[a-f0-9-]{36}$/iD', $request['requestId'])
            || ($request['idempotencyKey'] ?? null) !== null
            || !is_array($request['source'] ?? null)
            || !is_array($request['transaction'] ?? null)
        ) {
            throw new InvalidArgumentException('The validation-only request has invalid or unexpected fields.');
        }
        $transactionKeys = [
            'customer',
            'txnDate',
            'refNumber',
            'poNumber',
            'memo',
            'terms',
            'class',
            'billAddress',
            'shipAddress',
            'lines',
            'expectedSubtotal',
            'expectedTax',
            'expectedTotal',
        ];
        $lineKeys = [
            'item',
            'description',
            'quantity',
            'rate',
            'expectedAmount',
            'salesTaxCode',
            'site',
            'lotNumber',
            'expirationDate',
        ];
        $requiredTransactionKeys = [
            'customer',
            'txnDate',
            'refNumber',
            'poNumber',
            'memo',
            'lines',
            'expectedSubtotal',
            'expectedTax',
            'expectedTotal',
        ];
        if (
            array_diff(array_keys($request['transaction']), $transactionKeys)
            || array_diff($requiredTransactionKeys, array_keys($request['transaction']))
            || !is_array($request['transaction']['customer'] ?? null)
            || !is_array($request['transaction']['lines'] ?? null)
            || array_diff(array_keys($request['transaction']['customer']), ['listId'])
        ) {
            throw new InvalidArgumentException('The validation-only transaction has invalid or unexpected fields.');
        }
        if (
            !is_string($request['transaction']['customer']['listId'] ?? null)
            || trim($request['transaction']['customer']['listId']) === ''
        ) {
            throw new InvalidArgumentException('The validation-only customer reference is invalid.');
        }
        if (
            array_diff(array_keys($request['source']), ['id', 'revision'])
            || !isset($request['source']['id'], $request['source']['revision'])
            || !filter_var($request['source']['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            || !is_string($request['source']['revision'])
            || !preg_match('/^[a-f0-9]{64}$/D', $request['source']['revision'])
        ) {
            throw new InvalidArgumentException('The validation-only source reference is invalid.');
        }
        $transaction = $request['transaction'];
        if (
            !is_string($transaction['txnDate'] ?? null)
            || !qb_export_valid_date($transaction['txnDate'])
            || !is_string($transaction['refNumber'] ?? null)
            || trim($transaction['refNumber']) === ''
            || mb_strlen($transaction['refNumber']) > 11
            || !$this->isNullableString($transaction['poNumber'] ?? null, 25)
            || !$this->isNullableString($transaction['memo'] ?? null, 4095)
            || !$this->isMoneyOrNull($transaction['expectedSubtotal'] ?? null, false)
            || !$this->isMoneyOrNull($transaction['expectedTax'] ?? null, true)
            || !$this->isMoneyOrNull($transaction['expectedTotal'] ?? null, true)
            || $transaction['expectedTax'] !== null
            || $transaction['expectedTotal'] !== null
            || count($transaction['lines']) < 1
            || count($transaction['lines']) > 500
        ) {
            throw new InvalidArgumentException('The validation-only transaction values are invalid.');
        }
        foreach (['terms', 'class'] as $optionalString) {
            if (array_key_exists($optionalString, $transaction) && !$this->isNullableString($transaction[$optionalString], 100)) {
                throw new InvalidArgumentException('The validation-only transaction values are invalid.');
            }
        }
        foreach (['billAddress', 'shipAddress'] as $addressField) {
            if (array_key_exists($addressField, $transaction) && !$this->isAddress($transaction[$addressField])) {
                throw new InvalidArgumentException('The validation-only transaction address is invalid.');
            }
        }
        $subtotalCents = 0;
        foreach ($transaction['lines'] as $line) {
            if (
                !is_array($line)
                || array_diff(array_keys($line), $lineKeys)
                || array_diff(['item', 'description', 'quantity', 'rate', 'expectedAmount', 'salesTaxCode'], array_keys($line))
                || !is_array($line['item'] ?? null)
                || array_diff(array_keys($line['item']), ['listId'])
                || !is_string($line['item']['listId'] ?? null)
                || trim($line['item']['listId']) === ''
                || !is_array($line['salesTaxCode'] ?? null)
                || array_diff(array_keys($line['salesTaxCode']), ['listId'])
                || !is_string($line['salesTaxCode']['listId'] ?? null)
                || trim($line['salesTaxCode']['listId']) === ''
                || !is_string($line['description'] ?? null)
                || trim($line['description']) === ''
                || mb_strlen($line['description']) > 255
                || !is_string($line['quantity'] ?? null)
                || !preg_match('/^[1-9]\d{0,8}$/D', $line['quantity'])
                || !$this->isMoneyOrNull($line['rate'] ?? null, false)
                || !$this->isMoneyOrNull($line['expectedAmount'] ?? null, false)
            ) {
                throw new InvalidArgumentException('The validation-only line has invalid or unexpected fields.');
            }
            $quantity = (int)$line['quantity'];
            $rateCents = qb_export_money_cents($line['rate']);
            $amountCents = qb_export_money_cents($line['expectedAmount']);
            $maximumCents = 99999999999999;
            if (
                $rateCents === null
                || $amountCents === null
                || ($rateCents > 0 && $quantity > intdiv($maximumCents, $rateCents))
                || $quantity * $rateCents !== $amountCents
                || $subtotalCents > $maximumCents - $amountCents
            ) {
                throw new InvalidArgumentException('The validation-only line amount does not reconcile to quantity and rate.');
            }
            $subtotalCents += $amountCents;
            foreach (['site', 'lotNumber', 'expirationDate'] as $optionalLineValue) {
                if (
                    array_key_exists($optionalLineValue, $line)
                    && !$this->isNullableString($line[$optionalLineValue], 255)
                ) {
                    throw new InvalidArgumentException('The validation-only line values are invalid.');
                }
            }
            if (
                array_key_exists('expirationDate', $line)
                && $line['expirationDate'] !== null
                && !qb_export_valid_date($line['expirationDate'])
            ) {
                throw new InvalidArgumentException('The validation-only expiration date is invalid.');
            }
        }
        if ($subtotalCents !== qb_export_money_cents($transaction['expectedSubtotal'])) {
            throw new InvalidArgumentException('The validation-only subtotal does not reconcile to its line amounts.');
        }
        return $this->request('validate', $request);
    }



    public function preflightInvoice(array $transaction, string $preparationMode = 'legacy_mapped'): array
    {
        $errors = [];
        if (!in_array($preparationMode, ['quickbooks_managed_defaults', 'legacy_mapped'], true)) {
            return ['ready' => false, 'errors' => [['code' => 'INVALID_PREPARATION_MODE', 'message' => 'The invoice preparation mode is unsupported.']]];
        }
        $customerReference = $transaction['customer'] ?? null;
        $lines = $transaction['lines'] ?? null;
        if (
            !is_array($customerReference)
            || !$this->isValidInvoiceReference($customerReference, 'customer')
            || !is_array($lines)
            || count($lines) < 1
            || count($lines) > 500
        ) {
            return ['ready' => false, 'errors' => [['code' => 'INVALID_SNAPSHOT', 'message' => 'The saved invoice references are incomplete.']]];
        }

        $preferencesResult = $this->diagnoseCompanyConfiguration('company_preferences');
        $preferences = $preferencesResult['response']['records'][0] ?? null;
        $preferencesAvailable = ($preferencesResult['available'] ?? false) === true && is_array($preferences);
        if (!$preferencesAvailable) {
            $errors[] = [
                'code' => 'COMPANY_PREFERENCES_QUERY_FAILED',
                'message' => 'QuickBooks company preferences could not be read; company-level invoice settings remain unverified.',
                'diagnostic_code' => $preferencesResult['diagnostic_code'] ?? null,
                'failure_stage' => $preferencesResult['failure_stage'] ?? null,
                'sdk_error_code' => $preferencesResult['sdk_error_code'] ?? null,
                'request_id' => $preferencesResult['request_id'] ?? null,
                'shared_failure' => true,
            ];
        } else {
            $multiLocationAvailable = $preferences['multiLocationAvailable'] ?? null;
            $multiLocationEnabled = $preferences['multiLocationEnabled'] ?? null;
            if ($multiLocationAvailable === false || $multiLocationEnabled === false) {
                $errors[] = [
                    'code' => 'INVENTORY_SITES_DISABLED',
                    'message' => 'QuickBooks inventory-site tracking is disabled for this company.',
                ];
            } elseif ($multiLocationAvailable !== true || $multiLocationEnabled !== true) {
                $errors[] = [
                    'code' => 'INVENTORY_SITE_PREFERENCE_UNKNOWN',
                    'message' => 'QuickBooks inventory-site availability or enabled status is unknown; verify the company preference before export.',
                ];
            }
        }
        $defaultTaxCodeIds = [];
        if ($preparationMode === 'quickbooks_managed_defaults' && $preferencesAvailable) {
            foreach (['defaultTaxableCode', 'defaultNonTaxableCode'] as $defaultField) {
                $defaultListId = is_array($preferences[$defaultField] ?? null)
                    ? $preferences[$defaultField]['listId'] ?? null
                    : null;
                if (
                    !is_string($defaultListId)
                    || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $defaultListId)
                ) {
                    $defaultTaxCodeIds = [];
                    $errors[] = [
                        'code' => 'TAX_DEFAULTS_UNVERIFIED',
                        'message' => 'QuickBooks company tax defaults are missing or could not be verified; invoice tax behavior remains unverified.',
                    ];
                    break;
                }
                $defaultTaxCodeIds[$defaultListId] = $defaultListId;
            }
            if ($defaultTaxCodeIds && !$this->preflightListReferences(
                'tax_code',
                array_values($defaultTaxCodeIds),
                'TAX_DEFAULTS_UNVERIFIED',
                $errors
            )) {
                $errors[] = [
                    'code' => 'TAX_DEFAULTS_UNVERIFIED',
                    'message' => 'QuickBooks company tax defaults are missing or inactive; invoice tax behavior remains unverified.',
                ];
            }
        }

        if (isset($customerReference['listId'])) {
            $customerRecords = $this->diagnose('customer', [$customerReference['listId']])['response']['records'] ?? null;
            $customerRecord = is_array($customerRecords) ? ($customerRecords[0] ?? null) : null;
            $customerValid = is_array($customerRecord)
                && ($customerRecord['found'] ?? false) === true
                && ($customerRecord['active'] ?? null) === true;
        } else {
            $customerResolution = $this->resolveExactFullName('customer', $customerReference['fullName']);
            $customerValid = $customerResolution['listId'] !== null;
            if (!$customerValid) {
                $errors[] = [
                    'code' => $customerResolution['code'],
                    'message' => $customerResolution['message'],
                    'entity' => 'customer',
                    'reference' => $customerReference['fullName'],
                    'diagnostic_code' => $customerResolution['diagnostic_code'] ?? null,
                    'failure_stage' => $customerResolution['failure_stage'] ?? null,
                    'sdk_error_code' => $customerResolution['sdk_error_code'] ?? null,
                    'request_id' => $customerResolution['request_id'] ?? null,
                    'shared_failure' => $customerResolution['shared_failure'] ?? false,
                ];
            }
        }
        if (!$customerValid && isset($customerReference['listId'])) {
            $errors[] = ['code' => 'CUSTOMER_REFERENCE_INVALID', 'message' => 'The verified QuickBooks customer is missing or inactive.'];
        }

        $itemIds = [];
        $itemFullNames = [];
        $taxIds = [];
        $sites = [];
        $hasLots = false;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                $errors[] = ['code' => 'INVALID_SNAPSHOT', 'message' => 'A saved invoice line is invalid.'];
                continue;
            }
            $itemReference = $line['item'] ?? null;
            $taxId = $line['salesTaxCode']['listId'] ?? null;
            $site = $line['site'] ?? null;
            if (is_array($itemReference) && $this->isValidInvoiceReference($itemReference, 'item')) {
                if (isset($itemReference['listId'])) {
                    $itemIds[$itemReference['listId']] = $itemReference['listId'];
                } else {
                    $itemFullNames[$itemReference['fullName']] = $itemReference['fullName'];
                }
            }
            if ($preparationMode === 'legacy_mapped' && is_string($taxId)) {
                $taxIds[$taxId] = $taxId;
            }
            if (is_string($site) && $site !== '') {
                $sites[$site] = $site;
            } else {
                $errors[] = ['code' => 'SITE_REFERENCE_INVALID', 'message' => 'An exact QuickBooks inventory site is required for every invoice line.'];
            }
            $hasLots = $hasLots || trim((string)($line['lotNumber'] ?? '')) !== '';
            if (trim((string)($line['expirationDate'] ?? '')) !== '') {
                $errors[] = ['code' => 'EXPIRATION_DATE_UNSUPPORTED', 'message' => 'Expiration dates are unsupported by the verified invoice-line mechanism.'];
            }
        }
        if ($itemIds && !$this->preflightListReferences('item', array_values($itemIds), 'ITEM_REFERENCE_INVALID', $errors)) {
            $errors[] = ['code' => 'ITEM_PREFLIGHT_FAILED', 'message' => 'QuickBooks item references could not all be verified.'];
        }
        foreach (array_values($itemFullNames) as $fullName) {
            $resolution = $this->resolveExactFullName('item', $fullName);
            if ($resolution['listId'] === null) {
                $errors[] = [
                    'code' => $resolution['code'],
                    'message' => $resolution['message'],
                    'entity' => 'item',
                    'reference' => $fullName,
                    'diagnostic_code' => $resolution['diagnostic_code'] ?? null,
                    'failure_stage' => $resolution['failure_stage'] ?? null,
                    'sdk_error_code' => $resolution['sdk_error_code'] ?? null,
                    'request_id' => $resolution['request_id'] ?? null,
                    'shared_failure' => $resolution['shared_failure'] ?? false,
                ];
                continue;
            }
        }
        if ($preparationMode === 'legacy_mapped'
            && !$this->preflightListReferences('tax_code', array_values($taxIds), 'TAX_CODE_INVALID', $errors)) {
            $errors[] = ['code' => 'TAX_PREFLIGHT_FAILED', 'message' => 'QuickBooks sales-tax references could not all be verified.'];
        }
        foreach (array_values($sites) as $site) {
            $siteResult = $this->lookupInventorySiteFullName($site);
            $records = $siteResult['response']['records'] ?? null;
            $siteError = null;
            $siteMessage = null;
            if (!($siteResult['available'] ?? false) || !is_array($records)) {
                $siteError = 'SITE_QUERY_FAILED';
                $siteMessage = 'QuickBooks could not complete the read-only inventory-site query; the saved Site has not been verified.';
            } elseif (count($records) === 0) {
                $siteError = 'SITE_NOT_FOUND';
                $siteMessage = 'No QuickBooks inventory Site has the saved FullName.';
            } elseif (count($records) > 1) {
                $siteError = 'SITE_FULLNAME_AMBIGUOUS';
                $siteMessage = 'More than one QuickBooks inventory Site matched the saved FullName.';
            } elseif (($records[0]['fullName'] ?? null) !== $site) {
                $siteError = 'SITE_FULLNAME_MISMATCH';
                $siteMessage = 'The returned QuickBooks inventory Site FullName does not exactly match the saved Site.';
            } elseif (($records[0]['active'] ?? null) === false) {
                $siteError = 'SITE_INACTIVE';
                $siteMessage = 'The exact QuickBooks inventory Site is inactive.';
            } elseif (($records[0]['active'] ?? null) !== true) {
                $siteError = 'SITE_ACTIVE_STATUS_UNKNOWN';
                $siteMessage = 'QuickBooks returned the exact inventory Site but did not verify that it is active.';
            }
            if ($siteError !== null) {
                $errors[] = [
                    'code' => $siteError,
                    'message' => $siteMessage,
                    'entity' => 'inventory_site',
                    'reference' => $site,
                    'diagnostic_code' => $siteResult['diagnostic_code'] ?? null,
                    'failure_stage' => $siteResult['failure_stage'] ?? null,
                    'sdk_error_code' => $siteResult['sdk_error_code'] ?? null,
                    'qbxml_status_code' => $siteResult['qbxml_status_code'] ?? null,
                    'qbxml_status_severity' => $siteResult['qbxml_status_severity'] ?? null,
                    'qbxml_status_message' => $siteResult['qbxml_status_message'] ?? null,
                    'query_operation' => $siteResult['query_operation'] ?? null,
                    'request_id' => $siteResult['request_id'] ?? null,
                    'shared_failure' => $siteError === 'SITE_QUERY_FAILED',
                ];
                break;
            }
        }
        if ($hasLots && (!$preferencesAvailable || ($preferences['lotTracking'] ?? null) !== 'LotNumber')) {
            $errors[] = ['code' => 'LOT_TRACKING_UNAVAILABLE', 'message' => 'QuickBooks lot-number tracking is not enabled or verified.'];
        }
        return ['ready' => $errors === [], 'errors' => $errors];
    }

    private function preflightListReferences(string $entity, array $listIds, string $failureCode, array &$errors): bool
    {
        if (!$listIds || count($listIds) > 500) {
            $errors[] = ['code' => $failureCode, 'message' => 'The saved invoice has no valid QuickBooks references.'];
            return false;
        }
        $verified = true;
        foreach (array_chunk(array_values(array_unique($listIds)), 50) as $batch) {
            $result = $this->diagnose($entity, $batch);
            $records = $result['response']['records'] ?? null;
            if (!($result['available'] ?? false) || !is_array($records)) {
                $verified = false;
                break;
            }
            foreach ($records as $record) {
                if (
                    !is_array($record)
                    || ($record['found'] ?? false) !== true
                    || ($record['active'] ?? null) !== true
                ) {
                    $verified = false;
                    break 2;
                }
            }
        }
        if (!$verified) {
            $errors[] = ['code' => $failureCode, 'message' => 'A saved QuickBooks reference is missing, inactive, or unverifiable.'];
        }
        return $verified;
    }

    private function isNullableString(mixed $value, int $maxLength): bool
    {
        return $value === null || (is_string($value) && mb_strlen($value) <= $maxLength);
    }

    private function isValidInvoiceReference(array $reference, string $entity): bool
    {
        if (count($reference) !== 1) {
            return false;
        }
        if (isset($reference['listId'])) {
            return is_string($reference['listId'])
                && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $reference['listId']) === 1;
        }
        $fullName = $reference['fullName'] ?? null;
        $maximumLength = $entity === 'customer' ? 209 : 159;
        return is_string($fullName)
            && trim($fullName) !== ''
            && $fullName === trim($fullName)
            && mb_strlen($fullName) <= $maximumLength
            && preg_match('/[\x00-\x1F\x7F]/', $fullName) !== 1;
    }

    private function resolveExactFullName(string $entity, string $fullName): array
    {
        $result = $this->lookupName($entity, $fullName);
        $records = $result['response']['records'] ?? null;
        if (!($result['available'] ?? false) || !is_array($records)) {
            return [
                'listId' => null,
                'code' => strtoupper($entity) . '_FULLNAME_LOOKUP_UNAVAILABLE',
                'message' => 'The signed, company-bound QuickBooks FullName query could not be completed; this reference has not been verified.',
                'diagnostic_code' => $result['diagnostic_code'] ?? null,
                'failure_stage' => $result['failure_stage'] ?? null,
                'sdk_error_code' => $result['sdk_error_code'] ?? null,
                'request_id' => $result['request_id'] ?? null,
                'shared_failure' => true,
            ];
        }
        $matches = array_values(array_filter(
            $records,
            static fn($record): bool => is_array($record)
                && ($record['fullName'] ?? null) === $fullName
        ));
        if (count($matches) !== 1) {
            $ambiguous = count($matches) > 1;
            return [
                'listId' => null,
                'code' => strtoupper($entity) . '_FULLNAME_' . ($ambiguous ? 'AMBIGUOUS' : 'NOT_FOUND'),
                'message' => $ambiguous
                    ? 'The exact QuickBooks FullName matches more than one company record.'
                    : 'No QuickBooks record has the exact requested FullName.',
            ];
        }
        if (($matches[0]['active'] ?? null) !== true) {
            $inactive = ($matches[0]['active'] ?? null) === false;
            return [
                'listId' => null,
                'code' => strtoupper($entity) . ($inactive ? '_FULLNAME_INACTIVE' : '_FULLNAME_ACTIVE_STATUS_UNKNOWN'),
                'message' => $inactive
                    ? 'The exact QuickBooks FullName record is inactive.'
                    : 'QuickBooks did not verify the active status of the exact FullName record.',
            ];
        }
        if (
            !is_string($matches[0]['listId'] ?? null)
            || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $matches[0]['listId'])
        ) {
            return [
                'listId' => null,
                'code' => strtoupper($entity) . '_FULLNAME_INVALID',
                'message' => 'The exact QuickBooks FullName record did not include a valid internal ListID.',
            ];
        }
        return ['listId' => $matches[0]['listId'], 'code' => null, 'message' => null];
    }

    private function isValidCompanyIdentity(mixed $identity): bool
    {
        if (
            !is_array($identity)
            || array_is_list($identity)
            || array_diff(array_keys($identity), ['companyName', 'legalCompanyName'])
            || array_diff(['companyName', 'legalCompanyName'], array_keys($identity))
        ) {
            return false;
        }
        return is_string($identity['companyName'])
            && trim($identity['companyName']) !== ''
            && mb_strlen($identity['companyName']) <= 59
            && is_string($identity['legalCompanyName'])
            && trim($identity['legalCompanyName']) !== ''
            && mb_strlen($identity['legalCompanyName']) <= 59;
    }

    private function isMoneyOrNull(mixed $value, bool $nullable): bool
    {
        return ($nullable && $value === null)
            || (is_string($value) && qb_export_money_cents($value) !== null);
    }

    private function companyFingerprintConfigurationFailure(): ?array
    {
        $expectedFingerprint = strtolower(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: '');
        if ($expectedFingerprint === '') {
            return [
                'available' => false,
                'request_id' => qb_export_uuid(),
                'diagnostic_code' => 'COMPANY_FINGERPRINT_NOT_CONFIGURED',
                'message' => 'The expected QuickBooks company fingerprint is not configured.',
            ];
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint)) {
            return [
                'available' => false,
                'request_id' => qb_export_uuid(),
                'diagnostic_code' => 'COMPANY_FINGERPRINT_INVALID',
                'message' => 'The expected QuickBooks company fingerprint is invalid.',
            ];
        }
        return null;
    }

    private function isValidCompanyPreferences(mixed $record): bool
    {
        $fields = [
            'customerTaxCodeEnabled',
            'vendorTaxCodeEnabled',
            'amountsIncludeTax',
            'defaultTaxableCode',
            'defaultNonTaxableCode',
            'defaultTaxItem',
            'multiLocationAvailable',
            'multiLocationEnabled',
            'lotTracking',
            'expirationDateEnabled',
            'salesTransactionTrackingEnabled',
        ];
        if (
            !is_array($record)
            || array_is_list($record)
            || array_diff(array_keys($record), $fields)
            || array_diff($fields, array_keys($record))
        ) {
            return false;
        }
        foreach (
            [
                'customerTaxCodeEnabled',
                'vendorTaxCodeEnabled',
                'amountsIncludeTax',
                'multiLocationAvailable',
                'multiLocationEnabled',
                'expirationDateEnabled',
                'salesTransactionTrackingEnabled',
            ] as $field
        ) {
            if (!is_bool($record[$field]) && $record[$field] !== null) {
                return false;
            }
        }
        if (!in_array($record['lotTracking'], [null, 'None', 'SerialNumber', 'LotNumber'], true)) {
            return false;
        }
        foreach (['defaultTaxableCode' => 3, 'defaultNonTaxableCode' => 3, 'defaultTaxItem' => 31] as $field => $maxNameLength) {
            $reference = $record[$field];
            if (
                !is_array($reference)
                || array_is_list($reference)
                || array_diff(array_keys($reference), ['listId', 'fullName'])
                || array_diff(['listId', 'fullName'], array_keys($reference))
                || !$this->isNullableString($reference['listId'], 100)
                || !$this->isNullableString($reference['fullName'], $maxNameLength)
                || ($reference['listId'] !== null
                    && !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $reference['listId']))
            ) {
                return false;
            }
        }
        return true;
    }

    private function areValidInvoiceCustomFields(array $records): bool
    {
        foreach ($records as $record) {
            $fields = ['ownerId', 'dataExtId', 'name', 'type', 'assignToObject', 'transactionRequired', 'listRequired', 'format'];
            if (
                !is_array($record)
                || array_is_list($record)
                || array_diff(array_keys($record), $fields)
                || array_diff($fields, array_keys($record))
                || !is_string($record['name'])
                || trim($record['name']) === ''
                || mb_strlen($record['name']) > 31
                || !in_array($record['type'], ['AMTTYPE', 'DATETIMETYPE', 'INTTYPE', 'PERCENTTYPE', 'PRICETYPE', 'QUANTYPE', 'STR1024TYPE', 'STR255TYPE'], true)
                || !is_array($record['assignToObject'])
                || !array_is_list($record['assignToObject'])
                || !in_array('Invoice', $record['assignToObject'], true)
                || count($record['assignToObject']) > 40
                || (!is_bool($record['transactionRequired']) && $record['transactionRequired'] !== null)
                || (!is_bool($record['listRequired']) && $record['listRequired'] !== null)
                || !is_string($record['dataExtId'])
                || !preg_match('/^\d{1,10}$/D', $record['dataExtId'])
                || !$this->isNullableString($record['ownerId'], 36)
                || !$this->isNullableString($record['format'], 1024)
            ) {
                return false;
            }
            $allowedObjects = [
                'Account',
                'ARRefundCreditCard',
                'Bill',
                'BillPaymentCheck',
                'BillPaymentCreditCard',
                'BuildAssembly',
                'Charge',
                'Check',
                'Company',
                'CreditCardCharge',
                'CreditCardCredit',
                'CreditMemo',
                'Customer',
                'Deposit',
                'Employee',
                'Estimate',
                'InventoryAdjustment',
                'Invoice',
                'Item',
                'ItemReceipt',
                'JournalEntry',
                'OtherName',
                'PurchaseOrder',
                'ReceivePayment',
                'SalesOrder',
                'SalesReceipt',
                'SalesTaxPaymentCheck',
                'Vendor',
                'VendorCredit',
            ];
            foreach ($record['assignToObject'] as $objectType) {
                if (!is_string($objectType) || !in_array($objectType, $allowedObjects, true)) {
                    return false;
                }
            }
            if (count(array_unique($record['assignToObject'], SORT_STRING)) !== count($record['assignToObject'])) {
                return false;
            }
        }
        return true;
    }

    private function isValidUnitRecord(array $record, string $expectedListId): bool
    {
        if (($record['listId'] ?? null) !== $expectedListId
            || !is_bool($record['found'] ?? null)
            || !array_key_exists('itemName', $record)
            || !array_key_exists('active', $record)
            || !array_key_exists('unitSetListId', $record)
            || !array_key_exists('unitSetName', $record)
            || !array_key_exists('unitSetActive', $record)
            || !array_key_exists('unitType', $record)
            || !array_key_exists('units', $record)
            || !array_key_exists('salesDefaultUnit', $record)
            || !is_array($record['units'])
            || !array_is_list($record['units'])
            || count($record['units']) > 100
            || !$this->isNullableString($record['itemName'], 159)
            || (!$record['found'] && (
                $record['itemName'] !== null
                || $record['active'] !== null
                || $record['unitSetListId'] !== null
                || $record['unitSetName'] !== null
                || $record['unitSetActive'] !== null
                || $record['unitType'] !== null
                || $record['units'] !== []
                || $record['salesDefaultUnit'] !== null
            ))
        ) {
            return false;
        }
        foreach (['active', 'unitSetActive'] as $field) {
            if (!is_bool($record[$field]) && $record[$field] !== null) {
                return false;
            }
        }
        if (
            !$this->isNullableString($record['unitSetListId'], 100)
            || !$this->isNullableString($record['unitSetName'], 31)
            || !$this->isNullableString($record['unitType'], 20)
            || !$this->isNullableString($record['salesDefaultUnit'], 31)
        ) {
            return false;
        }
        if ($record['found'] && (!is_string($record['itemName']) || trim($record['itemName']) === '')) {
            return false;
        }
        if (
            $record['unitSetListId'] !== null
            && !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['unitSetListId'])
        ) {
            return false;
        }
        foreach ($record['units'] as $unit) {
            if (
                !is_array($unit)
                || array_is_list($unit)
                || array_diff(array_keys($unit), ['name', 'abbreviation'])
                || array_diff(['name', 'abbreviation'], array_keys($unit))
                || !$this->isNullableString($unit['name'], 31)
                || !$this->isNullableString($unit['abbreviation'], 31)
                || !is_string($unit['name'])
                || trim($unit['name']) === ''
            ) {
                return false;
            }
        }
        if (
            $record['salesDefaultUnit'] !== null
            && !in_array($record['salesDefaultUnit'], array_column($record['units'], 'name'), true)
        ) {
            return false;
        }
        return true;
    }

    private function isAddress(mixed $address): bool
    {
        if ($address === null) {
            return true;
        }
        if (!is_array($address)) {
            return false;
        }
        $allowed = ['addr1', 'addr2', 'addr3', 'addr4', 'addr5', 'city', 'state', 'postalCode', 'country'];
        if (array_diff(array_keys($address), $allowed)) {
            return false;
        }
        foreach ($address as $value) {
            if (!$this->isNullableString($value, 41)) {
                return false;
            }
        }
        return true;
    }

    private function isValidReferenceScan(array $categories): bool
    {
        $expected = [
            'customers' => ['scope' => 'customer', 'limit' => 100],
            'items' => ['scope' => 'item', 'limit' => 100],
            'inventory_sites' => ['scope' => 'local_exact_names', 'limit' => 20],
            'mapped_item_units' => ['scope' => 'saved_item_list_ids', 'limit' => 20],
            'saved_tax_codes' => ['scope' => 'saved_tax_code_list_ids', 'limit' => 20],
            'company_preferences' => ['scope' => 'company_preferences', 'limit' => 1],
        ];
        if (count($categories) !== count($expected)) {
            return false;
        }
        foreach ($categories as $category) {
            $fields = ['category', 'scope', 'status', 'diagnosticCode', 'recordCount', 'limit', 'records'];
            if (
                !is_array($category)
                || array_is_list($category)
                || array_diff(array_keys($category), $fields)
                || array_diff($fields, array_keys($category))
                || !is_string($category['category'])
                || !isset($expected[$category['category']])
                || $category['scope'] !== $expected[$category['category']]['scope']
                || !in_array($category['status'], ['complete', 'partial', 'failed'], true)
                || (!is_null($category['diagnosticCode'])
                    && (!is_string($category['diagnosticCode'])
                        || !preg_match('/^[A-Z0-9_]{1,80}$/D', $category['diagnosticCode'])))
                || !is_int($category['recordCount'])
                || !is_int($category['limit'])
                || $category['limit'] !== $expected[$category['category']]['limit']
                || !is_array($category['records'])
                || !array_is_list($category['records'])
                || $category['recordCount'] !== count($category['records'])
                || count($category['records']) > $category['limit']
                || ($category['status'] === 'failed'
                    && ($category['recordCount'] !== 0 || $category['diagnosticCode'] === null))
                || ($category['status'] === 'complete' && $category['diagnosticCode'] !== null)
                || ($category['status'] === 'partial'
                    && $category['diagnosticCode'] !== 'SCAN_LIMIT_REACHED')
            ) {
                return false;
            }
            foreach ($category['records'] as $record) {
                if (!$this->isValidReferenceScanRecord($category['category'], $record)) {
                    return false;
                }
            }
        }
        return count(array_unique(array_column($categories, 'category'), SORT_STRING)) === count($expected);
    }

    private function isValidReferenceScanRecord(string $category, mixed $record): bool
    {
        if (!is_array($record) || array_is_list($record)) {
            return false;
        }
        if ($category === 'customers') {
            $fields = ['listId', 'name', 'fullName', 'active', 'companyName', 'customerType'];
            return !array_diff(array_keys($record), $fields)
                && !array_diff($fields, array_keys($record))
                && is_string($record['listId'])
                && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['listId'])
                && is_string($record['name'])
                && trim($record['name']) !== ''
                && mb_strlen($record['name']) <= 41
                && is_string($record['fullName'])
                && trim($record['fullName']) !== ''
                && mb_strlen($record['fullName']) <= 209
                && (is_bool($record['active']) || $record['active'] === null)
                && $this->isNullableString($record['companyName'], 41)
                && $this->isNullableString($record['customerType'], 159);
        }
        if ($category === 'items') {
            $fields = ['listId', 'name', 'fullName', 'active', 'itemType', 'manufacturerPartNumber', 'unitSetListId', 'unitSetName'];
            return !array_diff(array_keys($record), $fields)
                && !array_diff($fields, array_keys($record))
                && is_string($record['listId'])
                && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['listId'])
                && is_string($record['name'])
                && trim($record['name']) !== ''
                && mb_strlen($record['name']) <= 31
                && is_string($record['fullName'])
                && trim($record['fullName']) !== ''
                && mb_strlen($record['fullName']) <= 159
                && (is_bool($record['active']) || $record['active'] === null)
                && is_string($record['itemType'])
                && preg_match('/^Item[A-Za-z]{1,30}$/D', $record['itemType'])
                && $this->isNullableString($record['manufacturerPartNumber'], 31)
                && $this->isNullableString($record['unitSetListId'], 100)
                && ($record['unitSetListId'] === null
                    || preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['unitSetListId']))
                && $this->isNullableString($record['unitSetName'], 159);
        }
        if ($category === 'inventory_sites') {
            $fields = ['listId', 'name', 'fullName', 'found', 'active'];
            return !array_diff(array_keys($record), $fields)
                && !array_diff($fields, array_keys($record))
                && is_string($record['listId'])
                && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['listId'])
                && is_string($record['name'])
                && mb_strlen($record['name']) <= 31
                && is_string($record['fullName'])
                && mb_strlen($record['fullName']) <= 255
                && is_bool($record['found'])
                && $record['found'] === true
                && (is_bool($record['active']) || $record['active'] === null);
        }
        if ($category === 'mapped_item_units') {
            return is_string($record['listId'] ?? null)
                && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['listId'])
                && $this->isValidUnitRecord($record, $record['listId']);
        }
        if ($category === 'saved_tax_codes') {
            if (($record['found'] ?? null) === false) {
                return array_keys($record) === ['listId', 'found']
                    && is_string($record['listId'])
                    && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['listId']);
            }
            $fields = ['listId', 'name', 'active', 'found'];
            return !array_diff(array_keys($record), $fields)
                && !array_diff($fields, array_keys($record))
                && is_string($record['listId'])
                && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['listId'])
                && is_bool($record['found'])
                && (!$record['found']
                    || (is_string($record['name'])
                        && mb_strlen($record['name']) <= 31
                        && (is_bool($record['active']) || $record['active'] === null)));
        }
        return $category === 'company_preferences'
            && $this->isValidCompanyPreferences($record);
    }

    private function request(string $operation, array $payload, int $timeout = 5): array
    {
        if (!in_array($operation, ['health', 'validate', 'diagnose'], true)) {
            throw new InvalidArgumentException('Unsupported bridge operation.');
        }
        if (
            !$this->isLoopbackUrl($this->baseUrl)
            || !is_string($this->secret)
            || strlen($this->secret) < 32
        ) {
            return [
                'available' => false,
                'request_id' => $payload['requestId'] ?? qb_export_uuid(),
                'diagnostic_code' => !$this->isLoopbackUrl($this->baseUrl) ? 'BRIDGE_DESTINATION_REJECTED' : 'BRIDGE_NOT_CONFIGURED',
                'message' => !$this->isLoopbackUrl($this->baseUrl)
                    ? 'The bridge destination must be literal loopback HTTP.'
                    : 'The authenticated loopback bridge or its minimum-length secret is not configured.',
            ];
        }
        if ($this->transport === null && !function_exists('curl_init')) {
            return [
                'available' => false,
                'request_id' => $payload['requestId'] ?? qb_export_uuid(),
                'diagnostic_code' => 'HTTP_CLIENT_UNAVAILABLE',
                'message' => 'The PHP cURL extension is not available.',
            ];
        }

        $requestId = is_string($payload['requestId'] ?? null) ? $payload['requestId'] : qb_export_uuid();
        $payload['requestId'] = $requestId;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $operation . "\n" . $requestId . "\n" . hash('sha256', $body), $this->secret);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-MFC-Request-Id: ' . $requestId,
            'X-MFC-Timestamp: ' . $timestamp,
            'X-MFC-Signature: ' . $signature,
        ];
        if ($this->transport !== null) {
            try {
                $transportResult = ($this->transport)($this->baseUrl . '/' . $operation, $body, $headers);
                if (!is_array($transportResult)) {
                    throw new RuntimeException('Invalid HTTP transport result.');
                }
                $transportHeaders = $this->normalizeHeaders($transportResult['headers'] ?? []);
                return $this->decodeResponse(
                    (int)($transportResult['status'] ?? 0),
                    (string)($transportResult['body'] ?? ''),
                    $requestId,
                    $operation,
                    $transportHeaders,
                    $payload['entity'] ?? null,
                    $payload['listIds'] ?? null,
                    $payload['searchName'] ?? null
                );
            } catch (Throwable) {
                return [
                    'available' => false,
                    'request_id' => $requestId,
                    'diagnostic_code' => 'BRIDGE_UNAVAILABLE',
                    'message' => 'The local bridge did not return a complete response.',
                ];
            }
        }

        $handle = curl_init($this->baseUrl . '/' . $operation);
        $responseHeaders = [];
        $duplicateResponseHeaders = false;
        $responseTooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$duplicateResponseHeaders): int {
                if (preg_match('/^([^:]+):\s*(.*?)\s*$/', $line, $matches)) {
                    $name = strtolower($matches[1]);
                    if (in_array($name, ['x-mfc-timestamp', 'x-mfc-signature'], true)) {
                        if (array_key_exists($name, $responseHeaders)) {
                            $duplicateResponseHeaders = true;
                        }
                        $responseHeaders[$name] = $matches[2];
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, &$responseTooLarge): int {
                $response ??= '';
                if (strlen($response) + strlen($chunk) > 65536) {
                    $responseTooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        $response = '';
        $result = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);

        if ($responseTooLarge) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_TOO_LARGE',
                'message' => 'The local bridge response exceeded the configured limit.',
            ];
        }
        if ($result === false || $curlError !== 0) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_UNAVAILABLE',
                'message' => 'The local bridge did not return a complete response.',
            ];
        }
        if ($duplicateResponseHeaders) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_INVALID_RESPONSE_HEADERS',
                'message' => 'The local bridge returned duplicate authentication headers.',
            ];
        }
        return $this->decodeResponse(
            $status,
            $response,
            $requestId,
            $operation,
            $responseHeaders,
            $payload['entity'] ?? null,
            $payload['listIds'] ?? null,
            $payload['searchName'] ?? null
        );
    }

    private function decodeResponse(
        int $status,
        string $response,
        string $requestId,
        string $operation,
        array $headers,
        ?string $expectedEntity = null,
        ?array $expectedListIds = null,
        ?string $expectedSearchName = null
    ): array {
        if (strlen($response) > 65536) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_TOO_LARGE',
                'message' => 'The local bridge response exceeded the configured limit.',
            ];
        }
        $timestamp = $headers['x-mfc-timestamp'] ?? '';
        $signature = $headers['x-mfc-signature'] ?? '';
        if (
            !is_string($timestamp)
            || !preg_match('/^\d{10}$/D', $timestamp)
            || abs(time() - (int)$timestamp) > 30
            || !is_string($signature)
            || !preg_match('/^[a-f0-9]{64}$/D', $signature)
        ) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_AUTH_FAILED',
                'message' => 'The local bridge response is unsigned or stale.',
            ];
        }
        $expectedSignature = hash_hmac(
            'sha256',
            $timestamp . "\nresponse\n" . $requestId . "\n" . $status . "\n" . hash('sha256', $response),
            (string)$this->secret
        );
        if (!hash_equals($expectedSignature, $signature)) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_AUTH_FAILED',
                'message' => 'The local bridge response signature is invalid.',
            ];
        }

        try {
            $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_INVALID_RESPONSE',
                'message' => 'The local bridge returned invalid JSON.',
            ];
        }
        $expectedCompanyFingerprint = strtolower(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: '');
        if (
            $operation === 'diagnose'
            && is_array($decoded)
            && ($decoded['status'] ?? null) === 'ok'
            && ($decoded['companyIdentityVerified'] ?? null) === false
        ) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'COMPANY_IDENTITY_MISMATCH',
                'message' => 'The bridge could not verify the expected QuickBooks company file.',
            ];
        }
        if (
            $operation === 'diagnose'
            && is_array($decoded)
            && ($decoded['status'] ?? null) === 'ok'
            && is_string($decoded['companyFingerprint'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $decoded['companyFingerprint'])
            && preg_match('/^[a-f0-9]{64}$/D', $expectedCompanyFingerprint)
            && !hash_equals($expectedCompanyFingerprint, $decoded['companyFingerprint'])
        ) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'COMPANY_IDENTITY_MISMATCH',
                'message' => 'The diagnostic response belongs to a different QuickBooks company file.',
            ];
        }
        $allowedResponseKeys = [
            'requestId',
            'status',
            'valid',
            'errors',
            'warnings',
            'diagnosticCode',
            'sdkRegistered',
            'clrVersion',
            'dotNetFrameworkRelease',
            'quickBooksProcessRunning',
            'companySessionChecked',
            'companySessionReady',
            'companyFingerprint',
            'transactionWritesSupported',
            'companyIdentityVerified',
            'companyIdentity',
            'validationOnly',
            'entity',
            'records',
            'qbxmlVersion',
            'searchName',
            'failureStage',
            'sdkErrorCode',
            'qbxmlStatusCode',
            'qbxmlStatusSeverity',
            'qbxmlStatusMessage',
            'queryOperation',
            'message',
        ];
        $invalidHealth = $operation === 'health'
            && ($decoded['status'] ?? null) === 'ok'
            && ((!is_bool($decoded['sdkRegistered'] ?? null))
                || !is_string($decoded['clrVersion'] ?? null)
                || !array_key_exists('dotNetFrameworkRelease', $decoded)
                || (!is_null($decoded['dotNetFrameworkRelease']) && !is_string($decoded['dotNetFrameworkRelease']))
                || (!is_bool($decoded['quickBooksProcessRunning'] ?? null))
                || (!is_bool($decoded['companySessionChecked'] ?? null))
                || ($decoded['transactionWritesSupported'] ?? null) !== false
                || (!is_null($decoded['companySessionReady'] ?? null)
                    && !is_bool($decoded['companySessionReady']))
            );
        $invalidDiagnosis = $operation === 'diagnose';
        if ($operation === 'diagnose' && is_array($decoded) && is_string($decoded['status'] ?? null)) {
            $invalidDiagnosis = false;
            if ($decoded['status'] !== 'ok') {
                $failureStages = [
                    'worker_request_read',
                    'worker_request_parse',
                    'worker_request_validation',
                    'sdk_diagnostic',
                    'worker_response_serialization',
                    'sdk_session_mutex',
                    'request_processor_activation',
                    'open_connection',
                    'begin_session',
                    'get_current_company_file',
                    'qbxml_version_negotiation',
                    'company_identity_query',
                    'company_preferences_query',
                    'invoice_custom_fields_query',
                    'item_uom_query',
                    'reference_scan_query',
                    'reference_query',
                    'reference_name_query',
                    'diagnostic_worker_timeout',
                    'diagnostic_worker_response',
                    'diagnostic_worker_launch',
                ];
                $invalidDiagnosis = (array_key_exists('failureStage', $decoded)
                        && !in_array($decoded['failureStage'], $failureStages, true))
                    || (array_key_exists('sdkErrorCode', $decoded)
                        && (!is_string($decoded['sdkErrorCode'])
                            || !preg_match('/^SDK_COM_[A-F0-9]{8}$/D', $decoded['sdkErrorCode'])))
                    || (array_key_exists('qbxmlStatusCode', $decoded)
                        && (!is_string($decoded['qbxmlStatusCode'])
                            || !preg_match('/^[1-9][0-9]{0,8}$/D', $decoded['qbxmlStatusCode'])))
                    || (array_key_exists('qbxmlStatusSeverity', $decoded)
                        && !in_array($decoded['qbxmlStatusSeverity'], ['Info', 'Warning', 'Error'], true))
                    || (array_key_exists('qbxmlStatusMessage', $decoded)
                        && (!is_string($decoded['qbxmlStatusMessage'])
                            || mb_strlen($decoded['qbxmlStatusMessage']) > 300
                            || preg_match('/[^A-Za-z0-9 .,:;()#_\-\[\]]/', $decoded['qbxmlStatusMessage'])))
                    || (array_key_exists('queryOperation', $decoded)
                        && !in_array($decoded['queryOperation'], ['InventorySiteQueryRq'], true));
            }
            if ($decoded['status'] === 'ok'
                && (array_key_exists('qbxmlStatusCode', $decoded)
                    || array_key_exists('qbxmlStatusSeverity', $decoded)
                    || array_key_exists('qbxmlStatusMessage', $decoded)
                    || array_key_exists('queryOperation', $decoded))) {
                $invalidDiagnosis = true;
            }
            if ($decoded['status'] === 'ok') {
                $records = $decoded['records'] ?? null;
                if ($expectedSearchName !== null) {
                    $expectedFields = $expectedEntity === 'customer'
                        ? ['listId', 'name', 'fullName', 'companyName', 'active', 'found']
                        : ($expectedEntity === 'item'
                            ? ['listId', 'name', 'fullName', 'manufacturerPartNumber', 'salesDescription', 'active', 'found']
                            : ['listId', 'name', 'fullName', 'active', 'found']);
                    $invalidDiagnosis = ($decoded['entity'] ?? null) !== $expectedEntity
                        || ($decoded['searchName'] ?? null) !== $expectedSearchName
                        || !is_array($records)
                        || !array_is_list($records)
                        || count($records) > 50
                        || !is_string($decoded['companyFingerprint'] ?? null)
                        || !preg_match('/^[a-f0-9]{64}$/D', $decoded['companyFingerprint'])
                        || !is_bool($decoded['companyIdentityVerified'] ?? null)
                        || $decoded['companyIdentityVerified'] !== true
                        || !$this->isValidCompanyIdentity($decoded['companyIdentity'] ?? null)
                        || !hash_equals($expectedCompanyFingerprint, $decoded['companyFingerprint'])
                        || !is_string($decoded['qbxmlVersion'] ?? null);
                    if (!$invalidDiagnosis) {
                        foreach ($records as $record) {
                            if (
                                !is_array($record)
                                || array_is_list($record)
                                || array_diff(array_keys($record), $expectedFields)
                                || array_diff($expectedFields, array_keys($record))
                                || !is_string($record['listId'] ?? null)
                                || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $record['listId'])
                                || !is_string($record['name'] ?? null)
                                || trim($record['name']) === ''
                                || mb_strlen($record['name']) > ($expectedEntity === 'customer' ? 41 : 31)
                                || !is_string($record['fullName'] ?? null)
                                || trim($record['fullName']) === ''
                                || mb_strlen($record['fullName']) > ($expectedEntity === 'customer' ? 209 : 159)
                                || ($record['found'] ?? null) !== true
                                || (!is_bool($record['active'] ?? null) && !is_null($record['active']))
                                || ($expectedEntity === 'customer'
                                    && !is_null($record['companyName'])
                                    && (!is_string($record['companyName']) || mb_strlen($record['companyName']) > 41))
                                || ($expectedEntity === 'item'
                                    && ((!is_null($record['manufacturerPartNumber'])
                                            && (!is_string($record['manufacturerPartNumber']) || mb_strlen($record['manufacturerPartNumber']) > 31))
                                        || (!is_null($record['salesDescription'])
                                            && (!is_string($record['salesDescription']) || mb_strlen($record['salesDescription']) > 4095))))
                            ) {
                                $invalidDiagnosis = true;
                                break;
                            }
                        }
                    }
                } elseif ($expectedListIds === null) {
                    $invalidDiagnosis = !in_array($expectedEntity, ['company_preferences', 'invoice_custom_fields', 'reference_scan'], true)
                        || ($decoded['entity'] ?? null) !== $expectedEntity
                        || !is_array($records)
                        || !array_is_list($records)
                        || !is_string($decoded['companyFingerprint'] ?? null)
                        || !preg_match('/^[a-f0-9]{64}$/D', $decoded['companyFingerprint'])
                        || ($decoded['companyIdentityVerified'] ?? null) !== true
                        || !$this->isValidCompanyIdentity($decoded['companyIdentity'] ?? null)
                        || !hash_equals($expectedCompanyFingerprint, $decoded['companyFingerprint'])
                        || !is_string($decoded['qbxmlVersion'] ?? null);
                    if (!$invalidDiagnosis) {
                        $invalidDiagnosis = $expectedEntity === 'company_preferences'
                            ? count($records) !== 1 || !$this->isValidCompanyPreferences($records[0] ?? null)
                            : ($expectedEntity === 'invoice_custom_fields'
                                ? count($records) > 100 || !$this->areValidInvoiceCustomFields($records)
                                : !$this->isValidReferenceScan($records));
                    }
                } else {
                    $invalidDiagnosis = ($decoded['entity'] ?? null) !== $expectedEntity
                        || !is_array($records)
                        || !array_is_list($records)
                        || !is_array($expectedListIds)
                        || count($records) !== count($expectedListIds)
                        || !is_string($decoded['companyFingerprint'] ?? null)
                        || !preg_match('/^[a-f0-9]{64}$/D', $decoded['companyFingerprint'])
                        || !is_bool($decoded['companyIdentityVerified'] ?? null)
                        || $decoded['companyIdentityVerified'] !== true
                        || !$this->isValidCompanyIdentity($decoded['companyIdentity'] ?? null)
                        || !hash_equals(
                            strtolower(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: ''),
                            $decoded['companyFingerprint']
                        )
                        || !is_string($decoded['qbxmlVersion'] ?? null);
                    if (!$invalidDiagnosis) {
                        $expectedRecordFields = $expectedEntity === 'item'
                            ? ['listId', 'name', 'active', 'found', 'manufacturerPartNumber']
                            : ($expectedEntity === 'item_uom'
                                ? ['listId', 'found', 'itemName', 'active', 'unitSetListId', 'unitSetName', 'unitSetActive', 'unitType', 'units', 'salesDefaultUnit']
                                : ['listId', 'name', 'active', 'found']);
                        foreach ($records as $index => $record) {
                            if (
                                !is_array($record)
                                || array_is_list($record)
                                || array_diff(array_keys($record), $expectedRecordFields)
                                || ($record['listId'] ?? null) !== $expectedListIds[$index]
                                || !is_bool($record['found'] ?? null)
                                || ($expectedEntity === 'item_uom'
                                    ? !$this->isValidUnitRecord($record, $expectedListIds[$index])
                                    : ($record['found']
                                        && (!is_string($record['name'] ?? null)
                                            || !array_key_exists('active', $record)
                                            || !is_bool($record['active'])
                                            || ($expectedEntity === 'item'
                                                && (!array_key_exists('manufacturerPartNumber', $record)
                                                    || (!is_null($record['manufacturerPartNumber'])
                                                        && (!is_string($record['manufacturerPartNumber'])
                                                            || mb_strlen($record['manufacturerPartNumber']) > 31)))))))
                            ) {
                                $invalidDiagnosis = true;
                                break;
                            }
                        }
                    }
                }
            }
        }
        if (
            !is_array($decoded)
            || array_is_list($decoded)
            || array_diff(array_keys($decoded), $allowedResponseKeys)
            || ($decoded['requestId'] ?? null) !== $requestId
            || ($operation === 'health' && !is_string($decoded['status'] ?? null))
            || $invalidHealth
            || ($operation === 'validate' && !is_bool($decoded['valid'] ?? null))
            || $invalidDiagnosis
        ) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_MISMATCH',
                'message' => 'The local bridge response did not match the request ID.',
            ];
        }
        if ($status < 200 || $status >= 300) {
            $responseCode = is_string($decoded['diagnosticCode'] ?? null)
                && preg_match('/^[A-Z0-9_]{1,80}$/D', $decoded['diagnosticCode'])
                ? $decoded['diagnosticCode']
                : ($status === 401 || $status === 403 ? 'BRIDGE_AUTH_FAILED' : 'BRIDGE_HTTP_ERROR');
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => $responseCode,
                'message' => is_string($decoded['message'] ?? null)
                    ? $decoded['message']
                    : 'The local bridge returned a signed unavailable or error response.',
                'failure_stage' => is_string($decoded['failureStage'] ?? null)
                    ? $decoded['failureStage']
                    : null,
                'sdk_error_code' => is_string($decoded['sdkErrorCode'] ?? null)
                    ? $decoded['sdkErrorCode']
                    : null,
                'qbxml_status_code' => is_string($decoded['qbxmlStatusCode'] ?? null)
                    ? $decoded['qbxmlStatusCode']
                    : null,
                'qbxml_status_severity' => is_string($decoded['qbxmlStatusSeverity'] ?? null)
                    ? $decoded['qbxmlStatusSeverity']
                    : null,
                'qbxml_status_message' => is_string($decoded['qbxmlStatusMessage'] ?? null)
                    ? $decoded['qbxmlStatusMessage']
                    : null,
                'query_operation' => is_string($decoded['queryOperation'] ?? null)
                    ? $decoded['queryOperation']
                    : null,
                'http_status' => $status,
                'signed_response' => true,
            ];
        }
        return ['available' => true, 'request_id' => $requestId, 'response' => $decoded];
    }

    private function normalizeHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $normalized[strtolower($name)] = $value;
            }
        }
        return $normalized;
    }

    private function isLoopbackUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'http') {
            return false;
        }
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        return $host === '127.0.0.1'
            && (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/')
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }
}
