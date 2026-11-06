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
$invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$no = is_string($_POST['withdrawal_no'] ?? null) ? trim($_POST['withdrawal_no']) : '';
$validDate = static function (string $value): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $d !== false && $d->format('Y-m-d') === $value && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
};
try {
    if (!$invoiceId || $no === '') throw new RuntimeException('Invoice draft was not found.');
    $pdo->beginTransaction();
    $s = $pdo->prepare('SELECT i.*,w.withdrawal_no FROM invoices i JOIN withdrawals w ON w.id=i.withdrawal_id WHERE i.id=? FOR UPDATE');
    $s->execute([$invoiceId]);
    $inv = $s->fetch();
    if (!$inv || $inv['withdrawal_no'] !== $no) throw new RuntimeException('Invoice draft was not found.');
    if (in_array($inv['status'], ['Invoiced', 'Queued', 'Processing', 'Uncertain Result'], true)) throw new RuntimeException('This invoice is locked because it was submitted or may have been submitted to QuickBooks.');
    $invoiceNumber = is_string($_POST['invoice_number'] ?? null) ? trim($_POST['invoice_number']) : '';
    if ($invoiceNumber === '' || mb_strlen($invoiceNumber) > 100) throw new RuntimeException('Invoice Number is required and must be 100 characters or fewer.');
    $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $cs = $pdo->prepare('SELECT id FROM clients WHERE id=? AND active=1');
    $cs->execute([$customerId ?: 0]);
    if (!$cs->fetchColumn()) throw new RuntimeException('Select an active customer for this invoice.');
    $dupe = $pdo->prepare('SELECT id FROM invoices WHERE invoice_number=? AND id<>?');
    $dupe->execute([$invoiceNumber, $invoiceId]);
    if ($dupe->fetchColumn()) throw new RuntimeException('That invoice number is already used by another invoice.');
    $date = trim((string)($_POST['invoice_date'] ?? ''));
    if (!$validDate($date)) throw new RuntimeException('Enter a valid invoice date.');
    $po = trim(is_string($_POST['po_no'] ?? null) ? $_POST['po_no'] : '');
    if (mb_strlen($po) > 100) throw new RuntimeException('PO # must be 100 characters or fewer.');
    $memo = $inv['memo'] ?? null;
    if (array_key_exists('memo', $_POST)) {
        if (!is_string($_POST['memo'])) throw new RuntimeException('Memo must be text.');
        $memo = trim($_POST['memo']);
        if (strlen($memo) > 65535) throw new RuntimeException('Memo is too long.');
        if ($memo === '') $memo = null;
    }
    $raw = $_POST['items'] ?? [];
    if (!is_array($raw)) throw new RuntimeException('Invoice lines are invalid.');
    $products = $pdo->prepare('SELECT id,product_name,qb_code,qb_description,item_code,description,u_m,qb_list_id FROM products WHERE id=? AND active=1');
    $codeMatches = $pdo->prepare('SELECT id FROM products WHERE LOWER(TRIM(qb_code))=LOWER(TRIM(?))');
    $sites = $pdo->prepare('SELECT id FROM sites WHERE id=? AND active=1');
    $withdrawalLine = $pdo->prepare('SELECT product_id FROM withdrawal_items WHERE id=? AND withdrawal_id=?');
    $existingLinesQuery = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id');
    $existingLinesQuery->execute([$invoiceId]);
    $existingLineRows = $existingLinesQuery->fetchAll();
    $existingLines = [];
    foreach ($existingLineRows as $existingLine) {
        $existingLines[(int)$existingLine['id']] = $existingLine;
    }
    $currentInvoiceRevision = invoice_draft_revision($inv, $existingLineRows);
    $valid = [];
    foreach ($raw as $it) {
        if (!is_array($it)) throw new RuntimeException('Invoice lines are invalid.');
        $productId = filter_var($it['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qtyRaw = trim((string)($it['quantity'] ?? ''));
        $priceRaw = trim((string)($it['price_each'] ?? ''));
        if (!$productId && $qtyRaw === '' && $priceRaw === '') continue;
        if (!$productId) throw new RuntimeException('Select a valid product in DESCRIPTION for every invoice line.');
        if (!preg_match('/^\d{1,9}$/D', $qtyRaw) || (float)$qtyRaw <= 0) throw new RuntimeException('Quantity must be a positive whole number.');
        $priceCents = null;
        if ($priceRaw !== '') {
            $plainPrice = preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $priceRaw);
            $groupedPrice = preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $priceRaw);
            if (!$plainPrice && !$groupedPrice) throw new RuntimeException('PRICE EACH must be a non-negative amount with valid comma grouping and up to two decimal places, or be left blank.');
            $priceRaw = str_replace(',', '', $priceRaw);
            [$whole, $fraction] = array_pad(explode('.', $priceRaw, 2), 2, '');
            if (strlen($whole) > 10) throw new RuntimeException('PRICE EACH exceeds the supported amount.');
            $priceCents = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
        }
        $products->execute([$productId]);
        $product = $products->fetch();
        if (!$product) throw new RuntimeException('A selected product is inactive or invalid.');
        if (!product_codes_match($product['item_code'] ?? null, $product['qb_code'] ?? null)) {
            throw new RuntimeException('This product has conflicting Item Code and QB Code values in Product Master. Resolve the shared code before saving the invoice.');
        }
        $submittedCode = trim((string)($it['item_code'] ?? ''));
        if ($submittedCode === '' || strcasecmp($submittedCode, trim((string)$product['qb_code'])) !== 0) throw new RuntimeException('The selected Item Code / QB Code does not match this product. Select the intended invoice item again.');
        $codeMatches->execute([$submittedCode]);
        $mappedIds = $codeMatches->fetchAll(PDO::FETCH_COLUMN);
        if (count($mappedIds) !== 1 || (int)$mappedIds[0] !== (int)$product['id']) throw new RuntimeException('This QB Code is missing or ambiguous in Product Master. Correct the mapping before saving.');
        $siteId = filter_var($it['site_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sites->execute([$siteId ?: 0]);
        if (!$sites->fetchColumn()) throw new RuntimeException('Select an active SITE for every line.');
        $tax = (string)($it['tax_code'] ?? '');
        if (!in_array($tax, ['Non', 'Tax'], true)) throw new RuntimeException('Select a valid TAX value.');
        $expiry = trim((string)($it['expiration_date'] ?? ''));
        if ($expiry !== '' && !$validDate($expiry)) throw new RuntimeException('Enter a valid EXPIRATION DATE or leave it blank.');
        $qty = (int)$qtyRaw;
        $existingLineId = filter_var($it['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $existingLine = $existingLineId ? ($existingLines[$existingLineId] ?? null) : null;
        if ($existingLineId && !$existingLine) throw new RuntimeException('An invoice line does not belong to this draft.');
        $historicalUnresolved = $existingLine
            && (int)$existingLine['product_id'] === (int)$product['id']
            && ($existingLine['price_source'] ?? 'unresolved') === 'unresolved';
        if ($historicalUnresolved && $priceCents !== null
            && (($_SESSION['user']['role'] ?? '') !== 'Admin'
                || ($it['confirm_historical_price'] ?? null) !== 'yes'
                || !is_string($_POST['invoice_revision'] ?? null)
                || !hash_equals($currentInvoiceRevision, $_POST['invoice_revision']))) {
            throw new RuntimeException('Refresh the invoice and have an Admin confirm the historical price for this invoice revision.');
        }
        if ($historicalUnresolved && $priceCents === null) {
            $priceSource = 'unresolved';
            $price = $existingLine['price_each'];
            $amount = $existingLine['amount'];
        } elseif ($priceCents === null) {
            $priceSource = 'quickbooks_pending';
            $price = null;
            $amount = null;
        } else {
            $priceSource = $priceCents === 0 ? 'explicit_zero' : 'mfc_entered';
            $maxAmountCents = 99999999999999;
            if ($priceCents > 0 && $qty > intdiv($maxAmountCents, $priceCents)) {
                throw new RuntimeException('The calculated line amount exceeds the supported accounting precision.');
            }
            $amountCents = $qty * $priceCents;
            $price = intdiv($priceCents, 100) . '.' . str_pad((string)($priceCents % 100), 2, '0', STR_PAD_LEFT);
            $amount = intdiv($amountCents, 100) . '.' . str_pad((string)($amountCents % 100), 2, '0', STR_PAD_LEFT);
        }
        $source = ($it['source_type'] ?? '') === 'ADDITIONAL' ? 'ADDITIONAL' : 'WITHDRAWAL';
        $wi = filter_var($it['withdrawal_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($source === 'WITHDRAWAL' && $wi) {
            $withdrawalLine->execute([$wi, $inv['withdrawal_id']]);
            $sourceProductId = $withdrawalLine->fetchColumn();
            if ($sourceProductId === false || ($sourceProductId !== null && (int)$sourceProductId !== (int)$product['id'])) throw new RuntimeException('A source invoice line no longer matches this withdrawal product.');
        }
        if (mb_strlen($it['lot_number'] ?? '') > 100 || mb_strlen($it['item_code'] ?? '') > 100 || mb_strlen($it['description'] ?? '') > 255) throw new RuntimeException('A line value exceeds its allowed length.');
        $valid[] = ['product' => $product, 'site' => $siteId, 'qty' => $qty, 'price' => $price, 'amount' => $amount, 'price_source' => $priceSource, 'tax' => $tax, 'expiry' => $expiry ?: null, 'lot' => trim((string)($it['lot_number'] ?? '')), 'code' => trim((string)($it['item_code'] ?? '')), 'desc' => trim((string)($it['description'] ?? '')), 'source' => $source, 'wi' => $source === 'WITHDRAWAL' && $wi ? $wi : null];
    }
    if (!$valid) throw new RuntimeException('Add at least one valid invoice line.');
    $pdo->prepare('UPDATE invoices SET customer_id=?,invoice_number=?,invoice_date=?,po_no=?,memo=?,status="Draft",updated_by=? WHERE id=?')->execute([$customerId, $invoiceNumber, $date, $po ?: null, $memo, $_SESSION['user']['id'], $invoiceId]);
    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invoiceId]);
    $ins = $pdo->prepare('INSERT INTO invoice_items(invoice_id,withdrawal_item_id,source_type,product_id,site_id,product_name,quantity,lot_number,item_code,description,u_m,price_each,amount,price_source,tax_code,expiration_date,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($valid as $line) {
        $p = $line['product'];
        $ins->execute([$invoiceId, $line['wi'], $line['source'], $p['id'], $line['site'], $p['product_name'], $line['qty'], $line['lot'] ?: null, $p['qb_code'], $p['qb_description'], $p['u_m'], $line['price'], $line['amount'], $line['price_source'], $line['tax'], $line['expiry'], $_SESSION['user']['id'], $_SESSION['user']['id']]);
    }
    log_activity($pdo, 'SAVE_DRAFT', 'invoice', $invoiceId, 'Saved invoice draft for ' . $no . ' with ' . count($valid) . ' line(s)');
    $pdo->commit();
    flash('success', 'Invoice draft saved. The source withdrawal was not changed.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $e instanceof PDOException ? 'Unable to save invoice draft. Check the invoice number and database connection.' : $e->getMessage();
    flash('danger', $message);
}
header('Location: ' . APP_BASE_URL . '/frontend/invoice_preparation.php?withdrawal_no=' . rawurlencode($no));
exit;