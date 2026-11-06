<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/quickbooks_exports.php';
require_once __DIR__ . '/../core/quickbooks_bridge_client.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php');
    exit;
}
verify_csrf();

$type = is_string($_POST['transaction_type'] ?? null) ? $_POST['transaction_type'] : '';
$sourceId = filter_var($_POST['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$revision = is_string($_POST['source_revision'] ?? null) ? $_POST['source_revision'] : '';
$preflightFailed = false;
try {
    if (!$sourceId) {
        throw new InvalidArgumentException('Select a valid source transaction.');
    }
    $pdo = db();
    $preview = qb_export_validate($pdo, $type, $sourceId);
    if (!$preview['valid'] || !hash_equals((string)$preview['source_revision'], $revision)) {
        throw new DomainException('The transaction changed or has blocking validation errors. Refresh and review it again.');
    }
    $transaction = $preview['transaction'];
    $hasFullNameReferences = is_array($transaction)
        && (is_string($transaction['customer_full_name'] ?? null)
            || count(array_filter(
                is_array($transaction['lines'] ?? null) ? $transaction['lines'] : [],
                static fn($line): bool => is_array($line) && is_string($line['item_full_name'] ?? null)
            )) > 0);
    if ($type === 'invoice' && $hasFullNameReferences) {
        $referenceTransaction = qb_export_reference_preflight_transaction($transaction);
        $referencePreflight = (new QuickBooksBridgeClient())->preflightInvoice(
            $referenceTransaction,
            $referenceTransaction['preparationMode']
        );
        if (!($referencePreflight['ready'] ?? false)) {
            $preflightFailed = true;
            $preflightErrors = is_array($referencePreflight['errors'] ?? null)
                ? $referencePreflight['errors']
                : [];
            $_SESSION['_qb_reference_preflight'] = [
                'checked_at' => date(DATE_ATOM),
                'source_type' => $type,
                'source_id' => (int)$sourceId,
                'errors' => $preflightErrors,
            ];
            $messages = qb_export_preflight_summary_messages($preflightErrors);
            throw new DomainException($messages
                ? 'QuickBooks FullName preflight failed: ' . implode(' ', $messages)
                : 'QuickBooks FullName preflight could not be completed.');
        }
    }
    $result = qb_export_create($pdo, $type, $sourceId, $revision, (int)$_SESSION['user']['id']);
    flash('success', $hasFullNameReferences
        ? 'Exact FullName references passed the current read-only preflight. The export was saved as pending; no invoice was sent.'
        : 'Preview passed. An export record was saved as pending; no request was sent to QuickBooks.');
} catch (Throwable $error) {
    $message = $error instanceof PDOException
        ? 'Could not create the export record. Refresh the preview and check whether an export already exists.'
        : $error->getMessage();
    flash('danger', $message);
}

$query = http_build_query(['type' => $type, 'id' => (int)$sourceId]);
header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php?' . $query . ($preflightFailed ? '#advanced-diagnostics' : ''));
exit;
