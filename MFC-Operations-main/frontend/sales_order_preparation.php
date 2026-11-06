<?php

declare(strict_types=1);
$page_title = 'Sales Order Preparation';
require __DIR__ . '/includes/layout_start.php';
$pdo = db();
$withdrawalNo = trim((string)($_GET['withdrawal_no'] ?? ''));
$source = $pdo->prepare('SELECT w.*, c.client_name, c.qb_list_id AS qb_customer_list_id FROM withdrawals w JOIN clients c ON c.id=w.client_id WHERE w.withdrawal_no=? LIMIT 1');
$source->execute([$withdrawalNo]);
$withdrawal = $source->fetch();
if (!$withdrawal) {
    echo '<div class="alert alert-warning">Select a saved withdrawal before preparing a Sales Order.</div><a class="btn btn-primary mb-3" href="withdrawals.php">Choose withdrawal</a>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT id FROM withdrawals WHERE id=? FOR UPDATE');
    $lock->execute([$withdrawal['id']]);
    $find = $pdo->prepare('SELECT * FROM sales_orders WHERE withdrawal_id=? FOR UPDATE');
    $find->execute([$withdrawal['id']]);
    $order = $find->fetch();
    if (!$order) {
        $insert = $pdo->prepare('INSERT INTO sales_orders(withdrawal_id,customer_id,qb_customer_list_id,sales_order_date,po_no,status,created_by,updated_by) VALUES(?,?,?,?,?,"Draft",?,?)');
        $insert->execute([$withdrawal['id'], $withdrawal['client_id'], $withdrawal['qb_customer_list_id'], $withdrawal['form_date'], $withdrawal['po_no'], $_SESSION['user']['id'], $_SESSION['user']['id']]);
        $orderId = (int)$pdo->lastInsertId();
        $copy = $pdo->prepare('SELECT wi.*,p.product_name AS master_name,p.qb_code,p.qb_list_id,p.qb_description,p.ht_description AS master_ht_description,p.u_m,p.default_price FROM withdrawal_items wi LEFT JOIN products p ON p.id=wi.product_id WHERE wi.withdrawal_id=? ORDER BY wi.line_no');
        $copy->execute([$withdrawal['id']]);
        $insertLine = $pdo->prepare('INSERT INTO sales_order_items(sales_order_id,line_no,product_id,item_name,item_code,qb_item_list_id,site_id,description,ordered_quantity,lot_number,u_m,rate,class_name,amount,line_status,fulfilled_quantity,expiration_date,tax_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($copy->fetchAll() as $index => $line) {
            $quantity = (int)$line['quantity'];
            $rate = $line['default_price'] === null ? null : (float)$line['default_price'];
            $description = trim((string)($line['qb_description'] ?? ''));
            if ($description === '') $description = trim((string)($line['ht_description'] ?? $line['master_ht_description'] ?? $line['master_name'] ?? $line['product_name']));
            $itemName = (string)($line['master_name'] ?: $line['product_name']);
            $insertLine->execute([$orderId, $index + 1, $line['product_id'], $itemName, $line['qb_code'], $line['qb_list_id'], null, $description, $quantity, null, $line['u_m'], $rate, null, round($quantity * (float)$rate, 2, PHP_ROUND_HALF_UP), null, null, null, null]);
        }
        $find = $pdo->prepare('SELECT * FROM sales_orders WHERE id=?');
        $find->execute([$orderId]);
        $order = $find->fetch();
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$lineQuery = $pdo->prepare('SELECT * FROM sales_order_items WHERE sales_order_id=? ORDER BY line_no');
$lineQuery->execute([$order['id']]);
$lines = $lineQuery->fetchAll();
$formState = $_SESSION['_sales_order_form'] ?? null;
unset($_SESSION['_sales_order_form']);
if (is_array($formState) && (int)($formState['id'] ?? 0) === (int)$order['id']) {
    foreach (['so_number', 'customer_id', 'sales_order_date', 'po_no', 'memo'] as $field) {
        if (array_key_exists($field, $formState['header'] ?? [])) $order[$field] = $formState['header'][$field];
    }
    $lines = [];
    foreach (($formState['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $read = static fn(string $key): string => is_scalar($item[$key] ?? null) ? (string)$item[$key] : '';
        $rate = $read('rate');
        $quantity = $read('ordered_quantity');
        $lines[] = [
            'product_id' => $read('product_id'),
            'description' => $read('description'),
            'site_id' => $read('site_id'),
            'ordered_quantity' => $quantity,
            'lot_number' => $read('lot_number'),
            'u_m' => $read('u_m'),
            'rate' => $rate,
            'class_name' => $read('class_name'),
            'amount' => round((float)str_replace(',', '', $rate) * (float)$quantity, 2, PHP_ROUND_HALF_UP),
            'line_status' => $read('line_status'),
            'fulfilled_quantity' => $read('fulfilled_quantity'),
            'expiration_date' => $read('expiration_date'),
            'tax_code' => $read('tax_code')
        ];
    }
}
$products = get_products($pdo);
$sites = get_sites($pdo);
$customerQuery = $pdo->prepare('SELECT id,client_name FROM clients WHERE active=1 OR id=? ORDER BY client_name');
$customerQuery->execute([$order['customer_id']]);
$customers = $customerQuery->fetchAll();
$total = array_sum(array_map(static fn(array $line): float => (float)$line['amount'], $lines));
?>
<div class="page-intro">
    <div>
        <h2>Sales Order Preparation</h2>
        <p>Local Sales Order draft for withdrawal <?= e($withdrawalNo) ?>. Existing invoice records remain separate.</p>
    </div>
    <div class="toolbar"><a href="withdrawals.php" class="btn btn-light"><i class="bi bi-arrow-left" aria-hidden="true"></i> Select withdrawal</a></div>
</div>
<!-- <div class="notice warning mb-3"><strong>Local preparation only:</strong> this Sales Order is saved in this application. QuickBooks submission and live customer/item mapping validation are not available. CLASS, STATUS, FULFILLED QUANTITY, and TAX are retained as local preparation values and are not verified QuickBooks mappings.</div> -->
<?php $notice = take_flash();
if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div><?php endif; ?>
<form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/sales_order_save.php" id="salesOrderForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="sales_order_id" value="<?= (int)$order['id'] ?>">
    <input type="hidden" name="withdrawal_no" value="<?= e($withdrawalNo) ?>">
    <section class="panel mb-4">
        <div class="panel-header">
            <div>
                <h3>Sales Order Header</h3>
                <p>Source withdrawal <?= e($withdrawalNo) ?></p>
            </div>
        </div>
        <div class="panel-body row g-3">
            <div class="col-md-4"><label class="form-label" for="soNumber">S.O. NO. <span class="required-mark">*</span></label><input class="form-control" id="soNumber" name="so_number" maxlength="100" value="<?= e($order['so_number'] ?? '') ?>" required></div>
            <div class="col-md-4"><label class="form-label" for="soCustomer">Customer Name <span class="required-mark">*</span></label><select class="form-select" id="soCustomer" name="customer_id" required><?php foreach ($customers as $customer): ?><option value="<?= (int)$customer['id'] ?>" <?= (int)$order['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>><?= e($customer['client_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="soDate">Sales Order Date <span class="required-mark">*</span></label><input class="form-control" id="soDate" type="date" name="sales_order_date" value="<?= e($order['sales_order_date']) ?>" required></div>
            <div class="col-md-6"><label class="form-label" for="soPo">PO NO.</label><input class="form-control" id="soPo" name="po_no" maxlength="100" value="<?= e($order['po_no'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label" for="soMemo">Memo</label><input class="form-control" id="soMemo" name="memo" maxlength="65535" value="<?= e($order['memo'] ?? '') ?>"></div>
        </div>
    </section>
    <section class="panel mb-4">
        <div class="panel-header">
            <div>
                <h3>Sales Order Lines</h3>
                <p>Required: ITEM, DESCRIPTION, SITE, ORDERED, RATE.</p>
            </div><button class="btn btn-outline-primary btn-sm" type="button" id="addSalesOrderLine"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add line</button>
        </div>
        <div class="table-responsive sales-order-table-wrap" tabindex="0" aria-label="Sales Order line items">
            <table class="table sales-order-lines-table" id="salesOrderLines" data-pagination-exempt>
                <colgroup>
                    <col class="so-col-item">
                    <col class="so-col-description">
                    <col class="so-col-site">
                    <col class="so-col-ordered">
                    <col class="so-col-lot">
                    <col class="so-col-um">
                    <col class="so-col-rate">
                    <col class="so-col-class">
                    <col class="so-col-amount">
                    <col class="so-col-status">
                    <col class="so-col-fulfilled">
                    <col class="so-col-expiration">
                    <col class="so-col-tax">
                </colgroup>
                <thead>
                    <tr>
                        <th>ITEM <span class="required-mark">*</span></th>
                        <th>DESCRIPTION <span class="required-mark">*</span></th>
                        <th>SITE <span class="required-mark">*</span></th>
                        <th>ORDERED <span class="required-mark">*</span></th>
                        <th>LOT NUMBER</th>
                        <th>U/M</th>
                        <th>RATE <span class="required-mark">*</span></th>
                        <th>CLASS</th>
                        <th>AMOUNT</th>
                        <th>STATUS</th>
                        <th>FULFILLED QUANTITY</th>
                        <th>EXPIRATION DATE</th>
                        <th>TAX</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$lines): $lines = [['product_id' => '', 'description' => '', 'site_id' => '', 'ordered_quantity' => '1', 'lot_number' => '', 'u_m' => '', 'rate' => null, 'class_name' => '', 'amount' => 0, 'line_status' => '', 'fulfilled_quantity' => '', 'expiration_date' => '', 'tax_code' => '']];
                    endif; ?>
                    <?php foreach ($lines as $index => $line): ?>
                        <tr data-line-row>
                            <td>
                                <div class="sales-order-item-cell"><select class="form-select form-select-sm so-item" name="items[<?= $index ?>][product_id]" aria-label="Line <?= $index + 1 ?> item" required>
                                        <option value="">Select item</option><?php foreach ($products as $product): ?><option value="<?= (int)$product['id'] ?>" data-description="<?= e($product['qb_description'] ?? '') ?>" data-unit="<?= e($product['u_m'] ?? '') ?>" data-rate="<?= e($product['default_price'] ?? '') ?>" <?= (int)$line['product_id'] === (int)$product['id'] ? 'selected' : '' ?>><?= e($product['product_name']) ?></option><?php endforeach; ?>
                                    </select><button type="button" class="btn btn-outline-danger btn-sm remove-sales-order-line" aria-label="Remove line <?= $index + 1 ?>">&times;</button></div>
                            </td>
                            <td><input class="form-control form-control-sm" name="items[<?= $index ?>][description]" maxlength="255" aria-label="Line <?= $index + 1 ?> description" value="<?= e($line['description']) ?>" required></td>
                            <td><select class="form-select form-select-sm" name="items[<?= $index ?>][site_id]" aria-label="Line <?= $index + 1 ?> site" required>
                                    <option value="">Select site</option><?php foreach ($sites as $site): ?><option value="<?= (int)$site['id'] ?>" <?= (int)$line['site_id'] === (int)$site['id'] ? 'selected' : '' ?>><?= e($site['site_name']) ?></option><?php endforeach; ?>
                                </select></td>
                            <td><input class="form-control form-control-sm" type="number" min="1" step="1" name="items[<?= $index ?>][ordered_quantity]" aria-label="Line <?= $index + 1 ?> ordered quantity" value="<?= e($line['ordered_quantity']) ?>" required></td>
                            <td><input class="form-control form-control-sm" name="items[<?= $index ?>][lot_number]" maxlength="100" aria-label="Line <?= $index + 1 ?> lot number" value="<?= e($line['lot_number'] ?? '') ?>"></td>
                            <td><input class="form-control form-control-sm" name="items[<?= $index ?>][u_m]" maxlength="40" aria-label="Line <?= $index + 1 ?> unit of measure" value="<?= e($line['u_m'] ?? '') ?>" readonly></td>
                            <td>
                                <div class="input-group input-group-sm"><span class="input-group-text">&#8369;</span><input class="form-control currency-input so-rate" type="text" inputmode="decimal" name="items[<?= $index ?>][rate]" aria-label="Line <?= $index + 1 ?> rate" value="<?= $line['rate'] === null ? '' : number_format((float)$line['rate'], 2, '.', ',') ?>" required></div>
                            </td>
                            <td><input class="form-control form-control-sm" name="items[<?= $index ?>][class_name]" maxlength="100" aria-label="Line <?= $index + 1 ?> class" value="<?= e($line['class_name'] ?? '') ?>"></td>
                            <td>
                                <div class="input-group input-group-sm"><span class="input-group-text">&#8369;</span><input class="form-control so-amount" value="<?= number_format((float)$line['amount'], 2, '.', ',') ?>" aria-label="Line <?= $index + 1 ?> amount" readonly></div>
                            </td>
                            <td><input class="form-control form-control-sm" name="items[<?= $index ?>][line_status]" maxlength="40" aria-label="Line <?= $index + 1 ?> status" value="<?= e($line['line_status'] ?? '') ?>"></td>
                            <td><input class="form-control form-control-sm" type="number" min="0" step="1" name="items[<?= $index ?>][fulfilled_quantity]" aria-label="Line <?= $index + 1 ?> fulfilled quantity" value="<?= e($line['fulfilled_quantity'] ?? '') ?>"></td>
                            <td><input class="form-control form-control-sm" type="date" name="items[<?= $index ?>][expiration_date]" aria-label="Line <?= $index + 1 ?> expiration date" value="<?= e($line['expiration_date'] ?? '') ?>"></td>
                            <td><select class="form-select form-select-sm" name="items[<?= $index ?>][tax_code]" aria-label="Line <?= $index + 1 ?> tax">
                                    <option value="">—</option><?php foreach (['Non', 'Tax'] as $tax): ?><option value="<?= e($tax) ?>" <?= ($line['tax_code'] ?? '') === $tax ? 'selected' : '' ?>><?= e($tax) ?></option><?php endforeach; ?>
                                </select></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-body d-flex justify-content-end">
            <div class="total-card">
                <div class="total-row"><span>Line Amount Subtotal</span><strong>&#8369; <span id="salesOrderTotal"><?= number_format($total, 2, '.', ',') ?></span></strong></div>
            </div>
        </div>
    </section>
    <div class="d-flex justify-content-end gap-2 mb-4"><button type="submit" class="btn btn-primary">Save Sales Order Draft</button></div>
</form>
<template id="salesOrderLineTemplate">
    <tr data-line-row>
        <td>
            <div class="sales-order-item-cell"><select class="form-select form-select-sm so-item" data-field="product_id" required>
                    <option value="">Select item</option><?php foreach ($products as $product): ?><option value="<?= (int)$product['id'] ?>" data-description="<?= e($product['qb_description'] ?? '') ?>" data-unit="<?= e($product['u_m'] ?? '') ?>" data-rate="<?= e($product['default_price'] ?? '') ?>"><?= e($product['product_name']) ?></option><?php endforeach; ?>
                </select><button type="button" class="btn btn-outline-danger btn-sm remove-sales-order-line" aria-label="Remove line">&times;</button></div>
        </td>
        <td><input class="form-control form-control-sm" data-field="description" maxlength="255" required></td>
        <td><select class="form-select form-select-sm" data-field="site_id" required>
                <option value="">Select site</option><?php foreach ($sites as $site): ?><option value="<?= (int)$site['id'] ?>"><?= e($site['site_name']) ?></option><?php endforeach; ?>
            </select></td>
        <td><input class="form-control form-control-sm" type="number" min="1" step="1" data-field="ordered_quantity" value="1" required></td>
        <td><input class="form-control form-control-sm" data-field="lot_number" maxlength="100"></td>
        <td><input class="form-control form-control-sm" data-field="u_m" maxlength="40" readonly></td>
        <td>
            <div class="input-group input-group-sm"><span class="input-group-text">&#8369;</span><input class="form-control currency-input so-rate" type="text" inputmode="decimal" data-field="rate" required></div>
        </td>
        <td><input class="form-control form-control-sm" data-field="class_name" maxlength="100"></td>
        <td>
            <div class="input-group input-group-sm"><span class="input-group-text">&#8369;</span><input class="form-control so-amount" value="0.00" readonly></div>
        </td>
        <td><input class="form-control form-control-sm" data-field="line_status" maxlength="40"></td>
        <td><input class="form-control form-control-sm" type="number" min="0" step="1" data-field="fulfilled_quantity"></td>
        <td><input class="form-control form-control-sm" type="date" data-field="expiration_date"></td>
        <td><select class="form-select form-select-sm" data-field="tax_code">
                <option value="">—</option><?php foreach (['Non', 'Tax'] as $tax): ?><option value="<?= e($tax) ?>"><?= e($tax) ?></option><?php endforeach; ?>
            </select></td>
    </tr>
</template>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const tbody = document.querySelector('#salesOrderLines tbody');
        const form = document.getElementById('salesOrderForm');
        const total = document.getElementById('salesOrderTotal');

        function numberValue(value) {
            const parsed = Number(String(value ?? '').replace(/,/g, '').trim());
            return Number.isFinite(parsed) ? parsed : 0;
        }

        function money(value) {
            return numberValue(value).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function updateNames() {
            [...tbody.rows].forEach((row, index) => {
                row.querySelectorAll('[name^="items["] , [data-field]').forEach(field => {
                    const fieldName = field.dataset.field || field.name.match(/^items\[\d+\]\[([^\]]+)\]$/)?.[1];
                    if (fieldName) {
                        field.name = `items[${index}][${fieldName}]`;
                        field.setAttribute('aria-label', `Line ${index + 1} ${fieldName.replaceAll('_', ' ')}`);
                    }
                });
                row.querySelector('.remove-sales-order-line')?.setAttribute('aria-label', `Remove line ${index + 1}`);
                row.dataset.previousProduct = row.querySelector('.so-item')?.value || '';
            });
        }

        function recalc(row) {
            const amount = Number(row.querySelector('[name$="[ordered_quantity]"]')?.value || 0) * numberValue(row.querySelector('.so-rate')?.value);
            row.querySelector('.so-amount').value = money(Math.round((amount + Number.EPSILON) * 100) / 100);
            total.textContent = money([...tbody.querySelectorAll('.so-amount')].reduce((sum, input) => sum + numberValue(input.value), 0));
        }
        document.getElementById('addSalesOrderLine')?.addEventListener('click', () => {
            const row = document.getElementById('salesOrderLineTemplate').content.firstElementChild.cloneNode(true);
            updateNames();
            tbody.appendChild(row);
            updateNames();
        });
        tbody.addEventListener('click', event => {
            if (event.target.closest('.remove-sales-order-line')) {
                if (tbody.rows.length <= 1) {
                    alert('A Sales Order must contain at least one line.');
                    return;
                }
                event.target.closest('tr').remove();
                updateNames();
                total.textContent = money([...tbody.querySelectorAll('.so-amount')].reduce((sum, input) => sum + numberValue(input.value), 0));
            }
        });
        tbody.addEventListener('change', event => {
            if (!event.target.matches('.so-item')) return;
            const row = event.target.closest('tr'),
                previous = row.dataset.previousProduct || '',
                option = event.target.selectedOptions[0];
            const hasEdits = ['[name$="[description]"]', '.so-rate'].some(selector => (row.querySelector(selector)?.value || '').trim() !== '');
            if (previous !== event.target.value && hasEdits && !confirm('Changing ITEM will replace DESCRIPTION, U/M, and RATE with the selected product defaults. Continue?')) {
                event.target.value = previous;
                return;
            }
            if (option?.value) {
                row.querySelector('[name$="[description]"]').value = option.dataset.description || '';
                row.querySelector('[name$="[u_m]"]').value = option.dataset.unit || '';
                row.querySelector('.so-rate').value = option.dataset.rate ? money(option.dataset.rate) : '';
                recalc(row);
            }
            row.dataset.previousProduct = event.target.value;
        });
        tbody.addEventListener('input', event => {
            if (event.target.matches('.so-rate,[name$="[ordered_quantity]"]')) recalc(event.target.closest('tr'));
        });
        tbody.querySelectorAll('tr').forEach(recalc);
        updateNames();
        form.addEventListener('submit', event => {
            for (const [index, row] of [...tbody.rows].entries()) {
                for (const field of row.querySelectorAll('[required]')) {
                    if (!String(field.value ?? '').trim()) {
                        event.preventDefault();
                        field.focus();
                        alert(`Line ${index + 1}: ${field.getAttribute('aria-label')} is required.`);
                        return;
                    }
                }
            }
        });
    });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>