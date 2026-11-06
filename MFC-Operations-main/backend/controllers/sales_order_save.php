<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/frontend/withdrawals.php');
    exit;
}
verify_csrf();
$pdo = db();
$orderId = filter_var($_POST['sales_order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$withdrawalNo = is_string($_POST['withdrawal_no'] ?? null) ? trim($_POST['withdrawal_no']) : '';
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && $date->format('Y-m-d') === $value && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
};
$toText = static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '';
try {
    if (!$orderId || $withdrawalNo === '') throw new RuntimeException('Sales Order draft was not found.');
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT so.*,w.withdrawal_no FROM sales_orders so JOIN withdrawals w ON w.id=so.withdrawal_id WHERE so.id=? FOR UPDATE');
    $query->execute([$orderId]);
    $order = $query->fetch();
    if (!$order || $order['withdrawal_no'] !== $withdrawalNo) throw new RuntimeException('Sales Order draft was not found.');

    $number = is_string($_POST['so_number'] ?? null) ? trim($_POST['so_number']) : '';
    if ($number === '' || mb_strlen($number) > 100) throw new RuntimeException('S.O. NO. is required and must be 100 characters or fewer.');
    $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $customerCheck = $pdo->prepare('SELECT id,qb_list_id FROM clients WHERE id=? AND active=1');
    $customerCheck->execute([$customerId ?: 0]);
    $customer = $customerCheck->fetch();
    if (!$customer) throw new RuntimeException('Customer Name must be an active customer.');
    $duplicate = $pdo->prepare('SELECT id FROM sales_orders WHERE so_number=? AND id<>?');
    $duplicate->execute([$number, $orderId]);
    if ($duplicate->fetchColumn()) throw new RuntimeException('That S.O. NO. is already used by another Sales Order.');
    $date = $toText($_POST['sales_order_date'] ?? null);
    if (!$validDate($date)) throw new RuntimeException('Sales Order Date must be a valid date.');
    $po = trim(is_string($_POST['po_no'] ?? null) ? $_POST['po_no'] : '');
    $memo = trim(is_string($_POST['memo'] ?? null) ? $_POST['memo'] : '');
    if (mb_strlen($po) > 100 || mb_strlen($memo) > 65535) throw new RuntimeException('PO NO. or Memo exceeds its allowed length.');

    $rawItems = $_POST['items'] ?? null;
    if (!is_array($rawItems)) throw new RuntimeException('Sales Order lines are invalid.');
    $products = $pdo->prepare('SELECT id,product_name,qb_code,item_code,qb_list_id,qb_description,u_m,active FROM products WHERE id=?');
    $sites = $pdo->prepare('SELECT id FROM sites WHERE id=? AND active=1');
    $validItems = [];
    foreach ($rawItems as $index => $item) {
        $line = (int)$index + 1;
        if (!is_array($item)) throw new RuntimeException("Line {$line}: fields are invalid.");
        $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $description = trim(is_string($item['description'] ?? null) ? $item['description'] : '');
        $siteId = filter_var($item['site_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantityRaw = $toText($item['ordered_quantity'] ?? null);
        $rateRaw = $toText($item['rate'] ?? null);
        $empty = !$productId && $description === '' && !$siteId && $quantityRaw === '' && $rateRaw === '';
        foreach (['lot_number', 'u_m', 'class_name', 'line_status', 'fulfilled_quantity', 'expiration_date', 'tax_code'] as $field) $empty = $empty && $toText($item[$field] ?? null) === '';
        if ($empty) continue;
        if (!$productId) throw new RuntimeException("Line {$line}: ITEM is required.");
        if ($description === '') throw new RuntimeException("Line {$line}: DESCRIPTION is required and cannot contain only spaces.");
        if (mb_strlen($description) > 255) throw new RuntimeException("Line {$line}: DESCRIPTION must be 255 characters or fewer.");
        if (!$siteId) throw new RuntimeException("Line {$line}: SITE is required.");
        $sites->execute([$siteId]);
        if (!$sites->fetchColumn()) throw new RuntimeException("Line {$line}: SITE must be an active site.");
        if (!preg_match('/^[1-9]\d{0,8}$/D', $quantityRaw)) throw new RuntimeException("Line {$line}: ORDERED must be a positive whole quantity.");
        $plainRate = preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $rateRaw);
        $groupedRate = preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $rateRaw);
        if (!$plainRate && !$groupedRate) throw new RuntimeException("Line {$line}: RATE must be a non-negative amount with valid comma grouping and up to two decimal places.");
        $rateRaw = str_replace(',', '', $rateRaw);
        if (strlen(explode('.', $rateRaw, 2)[0]) > 10) throw new RuntimeException("Line {$line}: RATE exceeds the supported amount.");
        $products->execute([$productId]);
        $product = $products->fetch();
        if (!$product || (int)$product['active'] !== 1) throw new RuntimeException("Line {$line}: ITEM must be an active product in Product Master.");
        if (!product_codes_match($product['item_code'] ?? null, $product['qb_code'] ?? null)) throw new RuntimeException("Line {$line}: Product Master has conflicting Item Code and QB Code values. Resolve the shared code before saving this Sales Order.");
        $lot = $toText($item['lot_number'] ?? null);
        $unit = $toText($product['u_m'] ?? null);
        $class = $toText($item['class_name'] ?? null);
        $lineStatus = $toText($item['line_status'] ?? null);
        $fulfilledRaw = $toText($item['fulfilled_quantity'] ?? null);
        $expiration = $toText($item['expiration_date'] ?? null);
        $tax = $toText($item['tax_code'] ?? null);
        if (mb_strlen($lot) > 100 || mb_strlen($unit) > 40 || mb_strlen($class) > 100 || mb_strlen($lineStatus) > 40) throw new RuntimeException("Line {$line}: an optional text field exceeds its allowed length.");
        if ($fulfilledRaw !== '' && !preg_match('/^\d{1,9}$/D', $fulfilledRaw)) throw new RuntimeException("Line {$line}: FULFILLED QUANTITY must be a non-negative whole quantity or blank.");
        if ($expiration !== '' && !$validDate($expiration)) throw new RuntimeException("Line {$line}: EXPIRATION DATE must be a valid date or blank.");
        if ($tax !== '' && !in_array($tax, ['Non', 'Tax'], true)) throw new RuntimeException("Line {$line}: TAX must be blank, Non, or Tax.");
        $quantity = (int)$quantityRaw;
        $rate = (float)$rateRaw;
        $amount = round($quantity * $rate, 2, PHP_ROUND_HALF_UP);
        $validItems[] = ['line_no' => count($validItems) + 1, 'product' => $product, 'site_id' => $siteId, 'description' => $description, 'quantity' => $quantity, 'rate' => $rate, 'amount' => $amount, 'lot' => $lot ?: null, 'u_m' => $unit ?: null, 'class' => $class ?: null, 'status' => $lineStatus ?: null, 'fulfilled' => $fulfilledRaw === '' ? null : (int)$fulfilledRaw, 'expiration' => $expiration ?: null, 'tax' => $tax ?: null];
    }
    if (!$validItems) throw new RuntimeException('Add at least one complete Sales Order line.');

    $pdo->prepare('UPDATE sales_orders SET customer_id=?,qb_customer_list_id=?,so_number=?,sales_order_date=?,po_no=?,memo=?,updated_by=? WHERE id=?')->execute([$customerId, $customer['qb_list_id'], $number, $date, $po ?: null, $memo ?: null, $_SESSION['user']['id'], $orderId]);
    $pdo->prepare('DELETE FROM sales_order_items WHERE sales_order_id=?')->execute([$orderId]);
    $insert = $pdo->prepare('INSERT INTO sales_order_items(sales_order_id,line_no,product_id,item_name,item_code,qb_item_list_id,site_id,description,ordered_quantity,lot_number,u_m,rate,class_name,amount,line_status,fulfilled_quantity,expiration_date,tax_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($validItems as $line) {
        $product = $line['product'];
        $insert->execute([$orderId, $line['line_no'], $product['id'], $product['product_name'], $product['qb_code'], $product['qb_list_id'], $line['site_id'], $line['description'], $line['quantity'], $line['lot'], $line['u_m'], $line['rate'], $line['class'], $line['amount'], $line['status'], $line['fulfilled'], $line['expiration'], $line['tax']]);
    }
    log_activity($pdo, 'SAVE_DRAFT', 'sales_order', $orderId, 'Saved local Sales Order draft ' . $number . ' for withdrawal ' . $withdrawalNo . ' with ' . count($validItems) . ' line(s)');
    $pdo->commit();
    flash('success', 'Sales Order draft saved locally. It was not sent to QuickBooks.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $e instanceof PDOException ? 'Unable to save this Sales Order. Check that its number is unique and the Sales Order migration has been applied.' : $e->getMessage();
    if ($orderId && is_array($_POST['items'] ?? null)) {
        $headers = [];
        foreach (['so_number', 'customer_id', 'sales_order_date', 'po_no', 'memo'] as $field) $headers[$field] = is_scalar($_POST[$field] ?? null) ? (string)$_POST[$field] : '';
        $items = [];
        foreach ($_POST['items'] as $item) {
            if (!is_array($item)) continue;
            $row = [];
            foreach (['product_id', 'description', 'site_id', 'ordered_quantity', 'lot_number', 'u_m', 'rate', 'class_name', 'line_status', 'fulfilled_quantity', 'expiration_date', 'tax_code'] as $field) $row[$field] = is_scalar($item[$field] ?? null) ? (string)$item[$field] : '';
            $items[] = $row;
        }
        $_SESSION['_sales_order_form'] = ['id' => $orderId, 'header' => $headers, 'items' => $items];
    }
    flash('danger', $message);
}
header('Location: ' . APP_BASE_URL . '/frontend/sales_order_preparation.php?withdrawal_no=' . rawurlencode($withdrawalNo));
exit;
