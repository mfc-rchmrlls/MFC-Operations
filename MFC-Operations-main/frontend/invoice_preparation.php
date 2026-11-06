<?php

//declare(strict_types=1);
$page_title = 'Invoice Preparation';
require __DIR__ . '/includes/layout_start.php';

$pdo = db();
$no = trim($_GET['withdrawal_no'] ?? '');

$s = $pdo->prepare(
    'SELECT w.*, c.client_name
     FROM withdrawals w
     JOIN clients c ON c.id = w.client_id
     WHERE w.withdrawal_no = ?'
);
$s->execute([$no]);
$w = $s->fetch();

if (!$w) {
    echo '<div class="alert alert-warning">Select a saved withdrawal before preparing an invoice.</div>
      <a class="btn btn-primary mb-3" href="withdrawals.php">Choose withdrawal</a>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$s = $pdo->prepare('SELECT * FROM invoices WHERE withdrawal_id = ?');
$s->execute([$w['id']]);
$inv = $s->fetch();

if (!$inv) {
    $pdo->beginTransaction();

    $s = $pdo->prepare(
        'INSERT INTO invoices
         (withdrawal_id, customer_id, invoice_date, po_no, status, created_by, updated_by)
         VALUES (?, ?, ?, ?, "Draft", ?, ?)'
    );

    $s->execute([
        $w['id'],
        $w['client_id'],
        $w['form_date'],
        $w['po_no'],
        $_SESSION['user']['id'],
        $_SESSION['user']['id']
    ]);

    $iid = (int) $pdo->lastInsertId();

    $s = $pdo->prepare(
        'SELECT wi.*, p.qb_code, p.qb_description, p.u_m
         FROM withdrawal_items wi
         LEFT JOIN products p ON p.id = wi.product_id
         WHERE wi.withdrawal_id = ?
         ORDER BY wi.line_no'
    );

    $s->execute([$w['id']]);
    $copy = $s->fetchAll();

    $ins = $pdo->prepare(
        'INSERT INTO invoice_items
         (invoice_id, withdrawal_item_id, source_type, product_id, site_id,
          product_name, quantity, item_code, description, u_m, price_each,
          amount, price_source, created_by, updated_by)
         VALUES (?, ?, "WITHDRAWAL", ?, ?, ?, ?, ?, ?, ?, NULL, NULL, "quickbooks_pending", ?, ?)'
    );

    foreach ($copy as $it) {
        $ins->execute([
            $iid,
            $it['id'],
            $it['product_id'],
            null,
            $it['product_name'],
            $it['quantity'],
            $it['qb_code'],
            $it['qb_description'],
            $it['u_m'],
            $_SESSION['user']['id'],
            $_SESSION['user']['id']
        ]);
    }

    $pdo->prepare(
        'UPDATE withdrawals SET status = ?, updated_by = ? WHERE id = ?'
    )->execute([
        'Invoice Preparation',
        $_SESSION['user']['id'],
        $w['id']
    ]);

    $pdo->commit();

    $s = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $s->execute([$iid]);
    $inv = $s->fetch();
}

$s = $pdo->prepare(
    'SELECT ii.*, si.site_name
     FROM invoice_items ii
     LEFT JOIN sites si ON si.id = ii.site_id
     WHERE ii.invoice_id = ?
     ORDER BY ii.id'
);
$s->execute([$inv['id']]);
$lines = $s->fetchAll();
$invoiceRevision = invoice_draft_revision($inv, $lines);

$products = get_products($pdo);
$products = array_values(array_filter($products, static fn($p) => trim((string)($p['qb_code'] ?? '')) !== ''));
$customers = $pdo->query('SELECT id,client_name,qb_list_id FROM clients WHERE active=1 ORDER BY client_name')->fetchAll();
$sites = get_sites($pdo);
$locked = in_array($inv['status'], ['Invoiced', 'Queued', 'Processing', 'Uncertain Result'], true);

$sum = 0;
foreach ($lines as $ln) {
    $sum += (float) $ln['amount'];
}
?>

<div class="page-intro">
    <div>
        <h2>Prepare QuickBooks Invoice</h2>
        <p>
            Invoice draft for withdrawal <?= e($no) ?>.
            Invoice edits never overwrite the original withdrawal.
        </p>
    </div>

    <div class="toolbar">
        <a href="withdrawals.php" class="btn btn-light">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Select withdrawal
        </a>
    </div>
</div>

<form
    method="post"
    action="<?= e(APP_BASE_URL) ?>/backend/controllers/invoice_save.php"
    id="invoiceForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
    <input type="hidden" name="withdrawal_no" value="<?= e($no) ?>">
    <input type="hidden" name="invoice_revision" value="<?= e($invoiceRevision) ?>">

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Invoice Header</h3>
                <p>Source withdrawal and invoice details</p>
            </div>

            <span class="status-pill <?= e(status_class($inv['status'])) ?>">
                <?= e($inv['status']) ?>
            </span>
        </div>

        <div class="panel-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Source Withdrawal No.</label>
                    <input class="form-control" value="<?= e($no) ?>" readonly>
                </div>

                <div class="col-md-3">
                    <label class="form-label">CUSTOMER NAME <span class="required-mark">*</span></label>
                    <select class="form-select" name="customer_id" required <?= $locked ? 'disabled' : '' ?>>
                        <option value="">Select customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int)$customer['id'] ?>" <?= (int)$inv['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>><?= e($customer['client_name']) ?><?= !$customer['qb_list_id'] ? ' — QuickBooks mapping required' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$locked): ?><div class="form-text">Defaults to the source withdrawal customer. Changing it affects this invoice only.</div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">INVOICE NUMBER <span class="required-mark">*</span></label>
                    <input class="form-control" name="invoice_number" maxlength="100" value="<?= e($inv['invoice_number'] ?? '') ?>" required <?= $locked ? 'readonly' : '' ?>>
                </div>

                <div class="col-md-3">
                    <label class="form-label">
                        Invoice Date
                    </label>
                    <input
                        class="form-control"
                        type="date"
                        name="invoice_date"
                        value="<?= e($inv['invoice_date']) ?>"
                        required
                        <?= $locked ? 'readonly' : '' ?>>
                </div>

                <div class="col-md-3">
                    <label class="form-label">PO #</label>
                    <input
                        class="form-control"
                        name="po_no"
                        value="<?= e($inv['po_no'] ?? '') ?>"
                        <?= $locked ? 'readonly' : '' ?>>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="invoiceMemo">Memo</label>
                    <textarea
                        class="form-control"
                        id="invoiceMemo"
                        name="memo"
                        rows="1"
                        <?= $locked ? 'readonly' : '' ?>><?= e($inv['memo'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Invoice Lines</h3>
                <p>Site, quantity, optional lot/expiration, item details, price and tax.</p>
            </div>

            <?php if (!$locked): ?>
                <div class="toolbar">
                    <button
                        type="button"
                        class="btn btn-light"
                        id="addInvoiceLine">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        Add invoice-only item
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table invoice-lines-table" id="invoiceLines" data-pagination-exempt>
                <colgroup>
                    <col class="invoice-col-site">
                    <col class="invoice-col-quantity">
                    <col class="invoice-col-lot">
                    <col class="invoice-col-item-code">
                    <col class="invoice-col-description">
                    <col class="invoice-col-unit">
                    <col class="invoice-col-price">
                    <col class="invoice-col-amount">
                    <col class="invoice-col-expiration">
                    <col class="invoice-col-tax">
                </colgroup>
                <thead>
                    <tr>
                        <th>SITE</th>
                        <th>QUANTITY</th>
                        <th>LOT NUMBER</th>
                        <th>ITEM CODE</th>
                        <th>DESCRIPTION</th>
                        <th>U/M</th>
                        <th>PRICE EACH (PHP)</th>
                        <th>AMOUNT (PHP)</th>
                        <th>EXPIRATION DATE</th>
                        <th>TAX</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($lines as $i => $ln): ?>
                        <?php
                        $priceSource = (string)($ln['price_source'] ?? 'unresolved');
                        $hasDisplayedPrice = in_array($priceSource, ['mfc_entered', 'explicit_zero', 'quickbooks_verified'], true)
                            || ($priceSource === 'unresolved' && $ln['price_each'] !== null && (float)$ln['price_each'] === 0.0);
                        $hasDisplayedPrice = $hasDisplayedPrice && $ln['price_each'] !== null;
                        $displayedAmount = $ln['amount'] === null
                            ? ''
                            : number_format((float)$ln['amount'], 2, '.', ',');
                        ?>
                        <tr>
                            <td>
                                <select class="form-select table-input" name="items[<?= $i ?>][site_id]" required <?= $locked ? 'disabled' : '' ?>>
                                    <option value="">Select site</option><?php foreach ($sites as $site): ?><option value="<?= (int)$site['id'] ?>" <?= (string)$ln['site_id'] === (string)$site['id'] ? 'selected' : '' ?>><?= e($site['site_name']) ?></option><?php endforeach; ?>
                                </select>
                                <input
                                    type="hidden"
                                    name="items[<?= $i ?>][id]"
                                    value="<?= (int) $ln['id'] ?>">
                                <input
                                    type="hidden"
                                    name="items[<?= $i ?>][source_type]"
                                    value="<?= e($ln['source_type']) ?>">
                                <input
                                    type="hidden"
                                    name="items[<?= $i ?>][withdrawal_item_id]"
                                    value="<?= e($ln['withdrawal_item_id'] ?? '') ?>">
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-qty"
                                    type="number"
                                    min="1"
                                    step="1"
                                    name="items[<?= $i ?>][quantity]"
                                    value="<?= e($ln['quantity']) ?>"
                                    required
                                    <?= $locked ? 'readonly' : '' ?>>
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-lot"
                                    name="items[<?= $i ?>][lot_number]"
                                    value="<?= e($ln['lot_number'] ?? '') ?>"
                                    <?= $locked ? 'readonly' : '' ?>>
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-item-code"
                                    name="items[<?= $i ?>][item_code]"
                                    value="<?= e($ln['item_code'] ?? '') ?>"
                                    <?= $locked ? 'readonly' : '' ?>>
                            </td>

                            <td>
                                <div class="d-flex gap-1 invoice-description-picker"><select class="form-select table-input product-select" name="items[<?= $i ?>][product_id]" required aria-label="Product description" <?= $locked ? 'disabled' : '' ?>>
                                        <option value="">Select description</option><?php foreach ($products as $pr): ?>                                        <option value="<?= (int)$pr['id'] ?>" data-code="<?= e($pr['qb_code'] ?? '') ?>" data-desc="<?= e($pr['qb_description'] ?? '') ?>" data-name="<?= e($pr['product_name'] ?? '') ?>" data-um="<?= e($pr['u_m'] ?? '') ?>" <?= (int)$ln['product_id'] === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['qb_description'] ?? '') ?></option><?php endforeach; ?>
                                    </select><?php if (!$locked): ?><button type="button" class="btn btn-sm btn-outline-danger remove-invoice-line" title="Remove line" aria-label="Remove invoice line">×</button><?php endif; ?></div><input type="hidden" name="items[<?= $i ?>][description]" value="<?= e($ln['description'] ?? '') ?>"><input type="hidden" name="items[<?= $i ?>][product_name]" value="<?= e($ln['product_name']) ?>">
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-unit"
                                    name="items[<?= $i ?>][u_m]"
                                    value="<?= e($ln['u_m'] ?? '') ?>"
                                    readonly>
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-price currency-input"
                                    type="text"
                                    inputmode="decimal"
                                    name="items[<?= $i ?>][price_each]"
                                    value="<?= $hasDisplayedPrice ? number_format((float)$ln['price_each'], 2, '.', ',') : '' ?>"
                                    placeholder="Enter unit price"
                                    <?= $locked ? 'readonly' : '' ?>>
                                <small class="d-block text-muted invoice-price-status">
                                    <?php if ($priceSource === 'mfc_entered'): ?>MFC-entered
                                    <?php elseif ($priceSource === 'explicit_zero'): ?>Explicit zero — valid
                                    <?php elseif ($priceSource === 'quickbooks_verified'): ?>QuickBooks price — review required
                                    <?php elseif ($priceSource === 'unresolved' && $ln['price_each'] !== null): ?>
                                        Historical price <?= number_format((float)$ln['price_each'], 2, '.', ',') ?> is unresolved; confirm its existing value
                                    <?php else: ?>Enter a Unit Price; blank prices cannot be saved for export<?php endif; ?>
                                </small>
                                <?php if ($priceSource === 'unresolved' && $ln['price_each'] !== null && !$locked): ?>
                                    <?php if (($_SESSION['user']['role'] ?? '') === 'Admin'): ?>
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox" id="confirm-historical-price-<?= (int)$ln['id'] ?>" name="items[<?= $i ?>][confirm_historical_price]" value="yes">
                                            <label class="form-check-label small" for="confirm-historical-price-<?= (int)$ln['id'] ?>"><?= (float)$ln['price_each'] === 0.0 ? 'Admin confirms this stored zero price' : 'Admin confirms re-entered price' ?></label>
                                        </div>
                                    <?php else: ?>
                                        <small class="d-block text-warning">An Admin must confirm this historical price.</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <input
                                    class="form-control table-input amount"
                                    value="<?= $hasDisplayedPrice ? number_format((float)$ln['quantity'] * (float)$ln['price_each'], 2, '.', ',') : e($displayedAmount) ?>"
                                    readonly>
                            </td>

                            <td>
                                <input class="form-control table-input invoice-expiration" type="date" name="items[<?= $i ?>][expiration_date]" value="<?= e($ln['expiration_date'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>>
                            </td>
                            <td>
                                <select class="form-select table-input invoice-tax" name="items[<?= $i ?>][tax_code]" required <?= $locked ? 'disabled' : '' ?>>
                                    <?php foreach (['Non', 'Tax'] as $tax): ?>
                                        <option <?= ($ln['tax_code'] === $tax ? 'selected' : '') ?>>
                                            <?= e($tax) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="panel-body d-flex justify-content-end">
            <div class="total-card">
                <div class="total-row">
                    <span>Invoice total</span>
                    <strong>
                        &#8369;
                        <span data-invoice-total><?= number_format($sum, 2) ?></span>
                    </strong>
                </div>

                <div class="form-text mt-2">
                    Tax treatment is stored as the selected tax code;
                    actual tax calculation follows QuickBooks configuration.
                </div>
            </div>
        </div>
    </section>

    <div class="page-bottom-actions">
        <span class="muted text-small">
            Status: <?= e($inv['status']) ?>.
            No invoice has been sent to QuickBooks.
        </span>

        <div class="right">
            <a
                href="withdrawal_form.php?withdrawal_no=<?= e($no) ?>"
                class="btn btn-light">
                View source withdrawal
            </a>

            <?php if (!$locked): ?>
                <button class="btn btn-primary" type="submit">
                    Save Invoice Draft
                </button>

                <span class="text-muted" role="status">QuickBooks transaction submission is not supported.</span>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const tbody = document.querySelector('#invoiceLines tbody');
        document.querySelector('#addInvoiceLine')?.addEventListener('click', () => {
            const row = tbody.rows[0]?.cloneNode(true);
            if (!row) return;
            const index = tbody.rows.length;
            row.querySelectorAll('[name]').forEach((el) => {
                el.name = el.name.replace(/items\[\d+\]/, 'items[' + index + ']');
                if (el.name.endsWith('[source_type]')) el.value = 'ADDITIONAL';
                else if (el.name.endsWith('[id]') || el.name.endsWith('[withdrawal_item_id]')) el.value = '';
                else if (el.matches('select')) el.selectedIndex = 0;
                else if (el.classList.contains('amount')) el.value = '0.00';
                else if (el.name.endsWith('[tax_code]')) el.value = 'Non';
                else if (el.type === 'hidden' || el.name.endsWith('[lot_number]') || el.name.endsWith('[item_code]') || el.name.endsWith('[description]') || el.name.endsWith('[u_m]') || el.name.endsWith('[expiration_date]')) el.value = '';
                else if (el.name.endsWith('[quantity]')) el.value = '1';
                else if (el.name.endsWith('[price_each]')) el.value = '';
            });
            row.querySelector("input[name$='[source_type]']").value = 'ADDITIONAL';
            tbody.appendChild(row);
        });
        tbody.addEventListener('click', event => {
            const button = event.target.closest('.remove-invoice-line');
            if (button && tbody.rows.length > 1) button.closest('tr').remove();
        });
        tbody.addEventListener('change', (event) => {
            if (!event.target.matches('.product-select')) return;
            const row = event.target.closest('tr');
            const opt = event.target.selectedOptions[0];
            if (!opt?.value) return;
            row.querySelector("input[name$='[item_code]']").value = opt.dataset.code || '';
            row.querySelector("input[name$='[description]']").value = opt.dataset.desc || '';
            row.querySelector("input[name$='[product_name]']").value = opt.dataset.name || '';
            row.querySelector("input[name$='[u_m]']").value = opt.dataset.um || '';
            updateAmount(row);
        });

        function updateAmount(row) {
            const priceInput = row.querySelector('.invoice-price');
            const priceRaw = priceInput.value.trim();
            const status = row.querySelector('.invoice-price-status');
            if (priceRaw === '') {
                row.querySelector('.amount').value = '';
                status.textContent = 'Price required; QuickBooks fallback has not been verified';
            } else {
                const rateCents = parseCents(priceRaw);
                const quantityRaw = row.querySelector('.invoice-qty').value;
                const quantity = /^\d{1,9}$/.test(quantityRaw) ? Number(quantityRaw) : 0;
                const amountCents = rateCents === null || quantity < 1 ? null : rateCents * quantity;
                row.querySelector('.amount').value = Number.isSafeInteger(amountCents)
                    ? formatCents(amountCents)
                    : '';
                status.textContent = rateCents === null
                    ? 'Enter a valid price with up to two decimals'
                    : rateCents === 0 ? 'Explicit zero — approval required' : 'MFC-entered';
            }
            const totalCents = [...tbody.querySelectorAll('.amount')].reduce((sum, input) => {
                const cents = parseCents(input.value);
                return cents === null ? sum : sum + cents;
            }, 0);
            document.querySelector('[data-invoice-total]').textContent = formatCents(totalCents);
        }

        function parseCents(value) {
            const normalized = value.trim().replace(/,/g, '');
            if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(normalized)) return null;
            const [whole, fraction = ''] = normalized.split('.');
            return Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
        }

        function formatCents(cents) {
            if (!Number.isSafeInteger(cents) || cents < 0) return '';
            const whole = String(Math.floor(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return whole + '.' + String(cents % 100).padStart(2, '0');
        }
        tbody.addEventListener('input', event => {
            if (event.target.matches('.invoice-qty,.invoice-price')) updateAmount(event.target.closest('tr'));
        });
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>