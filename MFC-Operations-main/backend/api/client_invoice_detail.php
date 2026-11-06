<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$invoiceId = filter_input(INPUT_GET, 'invoice_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$invoiceId || !$clientId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid invoice request.']);
    exit;
}

try {
    $pdo = db();
    $header = $pdo->prepare('SELECT i.id,i.invoice_number,i.invoice_date,i.po_no,i.status,c.client_name,w.withdrawal_no FROM invoices i JOIN clients c ON c.id=i.customer_id JOIN withdrawals w ON w.id=i.withdrawal_id WHERE i.id=? AND i.customer_id=? LIMIT 1');
    $header->execute([$invoiceId, $clientId]);
    $invoice = $header->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['error' => 'Invoice not found for this client.']);
        exit;
    }

    $linesQuery = $pdo->prepare('SELECT ii.id,ii.item_code,ii.product_name,ii.description,si.site_name,ii.quantity,ii.u_m,ii.price_each,ii.amount,ii.tax_code FROM invoice_items ii LEFT JOIN sites si ON si.id=ii.site_id WHERE ii.invoice_id=? ORDER BY ii.id');
    $linesQuery->execute([$invoiceId]);
    $lines = $linesQuery->fetchAll(PDO::FETCH_ASSOC);
    $invoice['lines'] = $lines;
    // Invoice Preparation calculates its displayed total as the sum of saved line amounts.
    $invoice['subtotal'] = array_reduce($lines, static fn(float $sum, array $line): float => $sum + (float)$line['amount'], 0.0);
    echo json_encode($invoice, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Client invoice detail request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load invoice details. Please try again.']);
}
