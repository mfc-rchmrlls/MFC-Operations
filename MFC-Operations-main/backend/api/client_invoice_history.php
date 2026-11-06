<?php
//declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$clientId = $clientId ?: 0;
$page = $page ?: 1;
$perPage = 10;
if (!$clientId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid client.']);
    exit;
}

try {
    $pdo = db();
    $exists = $pdo->prepare('SELECT 1 FROM clients WHERE id=?');
    $exists->execute([$clientId]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Client not found.']);
        exit;
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE customer_id=?');
    $count->execute([$clientId]);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $query = $pdo->prepare("SELECT i.id, i.invoice_number, i.invoice_date, i.status,
            w.withdrawal_no, COALESCE(invoice_totals.item_count,0) AS item_count,
            COALESCE(invoice_totals.total_quantity,0) AS total_quantity, invoice_totals.invoice_amount
        FROM invoices i
        INNER JOIN withdrawals w ON w.id=i.withdrawal_id
        LEFT JOIN (
            SELECT invoice_id, COUNT(*) AS item_count, SUM(quantity) AS total_quantity, SUM(amount) AS invoice_amount
            FROM invoice_items GROUP BY invoice_id
        ) AS invoice_totals ON invoice_totals.invoice_id=i.id
        WHERE i.customer_id=?
        ORDER BY i.invoice_date DESC, i.id DESC LIMIT {$perPage} OFFSET {$offset}");
    $query->execute([$clientId]);
    echo json_encode(['items' => $query->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Client invoice history request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load invoice history. Please try again.']);
}
