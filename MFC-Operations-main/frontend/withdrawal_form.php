<?php

$page_title = 'Withdrawal Form';

require __DIR__ . '/includes/layout_start.php';

$pdo = db();
$no = trim($_GET['withdrawal_no'] ?? '');

$record = null;
$items = [];

/*
|--------------------------------------------------------------------------
| Load existing withdrawal
|--------------------------------------------------------------------------
*/

if ($no !== '') {
    $s = $pdo->prepare(
        'SELECT w.*, c.client_name
         FROM withdrawals w
         JOIN clients c ON c.id = w.client_id
         WHERE w.withdrawal_no = ?'
    );

    $s->execute([$no]);
    $record = $s->fetch();

    if (!$record) {
        flash('warning', 'Withdrawal not found. A new form is ready.');

        header(
            'Location: ' . APP_BASE_URL . '/frontend/withdrawal_form.php'
        );

        exit;
    }

    $s = $pdo->prepare(
        'SELECT *
         FROM withdrawal_items
         WHERE withdrawal_id = ?
         ORDER BY line_no'
    );

    $s->execute([$record['id']]);
    $items = $s->fetchAll();
}

/*
|--------------------------------------------------------------------------
| Load active clients and products
|--------------------------------------------------------------------------
*/

$clients = $pdo->query(
    'SELECT id, client_name
     FROM clients
     WHERE active = 1
     ORDER BY client_name'
)->fetchAll();

$products = get_products($pdo);
$productSearchOptions = [];
$clientSearchOptions = [];
$activeProductsById = [];
foreach ($products as $product) {
    $label = withdrawal_product_label(
        (string)$product['product_name'],
        $product['ht_description'] ?? null
    );
    $productSearchOptions[] = [
        'product_id' => (int)$product['id'],
        'display_label' => $label,
        'product_name' => (string)$product['product_name'],
        'ht_code' => (string)($product['ht_code'] ?? ''),
        'ht_description' => (string)($product['ht_description'] ?? ''),
        'kg_per_box' => is_numeric($product['kg_per_box'] ?? null) ? (float)$product['kg_per_box'] : null,
    ];
    $activeProductsById[(string)$product['id']] = $product;
}
foreach ($clients as $client) {
    $clientSearchOptions[(string)$client['client_name']] = (int)$client['id'];
}

/*
|--------------------------------------------------------------------------
| Load product weights
|--------------------------------------------------------------------------
*/

$productWeights = [];

$weightStmt = $pdo->query(
    'SELECT id, kg_per_box
     FROM products
     WHERE active = 1
       AND kg_per_box IS NOT NULL'
);

foreach ($weightStmt->fetchAll(PDO::FETCH_ASSOC) as $weightRow) {
    $productId = (string) $weightRow['id'];
    $kgPerBox = $weightRow['kg_per_box'];

    if (
        $productId !== '' &&
        is_numeric($kgPerBox) &&
        (float) $kgPerBox > 0
    ) {
        $productWeights[$productId] = (float) $kgPerBox;
    }
}


$formatDecimal = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }

    if (!is_numeric($value)) {
        return (string) $value;
    }

    return rtrim(
        rtrim(number_format((float) $value, 3, '.', ''), '0'),
        '.'
    );
};

$nextNo = $record['withdrawal_no'] ?? next_withdrawal_no($pdo);

/*
|--------------------------------------------------------------------------
| Prepare item rows
|
| Show at least 5 rows initially.
| Existing saved items are preserved and displayed.
| Additional rows are created using the Add Line button.
|--------------------------------------------------------------------------
*/

$emptyItem = [
    'product_id' => '',
    'product_name' => '',
    'quantity' => '',
    'remarks' => ''
];

$values = $items;

while (count($values) < 5) {
    $values[] = $emptyItem;
}

$usedItemCount = 0;

foreach ($values as $item) {
    if (trim((string) ($item['product_name'] ?? '')) !== '') {
        $usedItemCount++;
    }
}
?>

<div class="page-intro withdrawal-page-intro">
    <div>
        <h2>
            <?= $record ? 'Edit Withdrawal' : 'Create Withdrawal' ?>
        </h2>

        <p>
            Complete the withdrawal details and list the products to be released.
            Invoice-only information is managed separately.
        </p>
    </div>

    <div class="toolbar withdrawal-page-toolbar">
        <a href="withdrawals.php" class="btn btn-light">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Back to register
        </a>

        <?php if ($record): ?>
            <a
                href="withdrawal_print.php?withdrawal_no=<?= e($record['withdrawal_no']) ?>"
                target="_blank"
                rel="noopener"
                class="btn btn-outline-secondary">
                <i class="bi bi-printer" aria-hidden="true"></i>
                Print form
            </a>
            <a href="withdrawal_form.php" class="btn btn-primary">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                New Withdrawal
            </a>
        <?php endif; ?>
    </div>
</div>

<form
    method="post"
    action="<?= e(APP_BASE_URL) ?>/backend/controllers/withdrawal_save.php"
    id="withdrawalForm"
    class="withdrawal-form">

    <input
        type="hidden"
        name="_csrf"
        value="<?= e(csrf_token()) ?>">

    <input
        type="hidden"
        name="withdrawal_id"
        value="<?= e($record['id'] ?? '') ?>">
    <!-- =========================================================
         WITHDRAWAL DETAILS
    ========================================================== -->

    <section class="panel withdrawal-panel">
        <div class="panel-header">
            <div>
                <h3>Withdrawal Details</h3>
                <p>Header information for the original withdrawal record</p>
            </div>

            <span
                class="status-pill <?= e(status_class($record['status'] ?? 'Draft')) ?>">
                <?= e($record['status'] ?? 'New draft') ?>
            </span>
        </div>

        <div class="panel-body">
            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label" for="withdrawalNoDisplay">
                        Withdrawal No.
                    </label>

                    <input
                        id="withdrawalNoDisplay"
                        class="form-control withdrawal-number"
                        name="withdrawal_no"
                        value="<?= e($nextNo) ?>"
                        <?= $record ? 'readonly aria-readonly="true"' : '' ?>
                        required
                        maxlength="40"
                        autocomplete="off">

                    <div class="form-text">
                        <?= $record ? 'Saved withdrawal number' : 'Suggested number; you can change it before saving.' ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="formDate">
                        Form Date <span class="required-mark">*</span>
                    </label>

                    <input
                        id="formDate"
                        class="form-control"
                        type="date"
                        name="form_date"
                        value="<?= e($record['form_date'] ?? date('Y-m-d')) ?>"
                        required>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="clientName">
                        Client Name <span class="required-mark">*</span>
                    </label>
                    <div class="withdrawal-autocomplete" data-autocomplete="client">
                        <input id="clientName" class="form-control" name="client_name" list="clientList" value="<?= e($record['client_name'] ?? '') ?>" placeholder="Search client name" autocomplete="off" required>
                        <input type="hidden" id="clientId" name="client_id" value="<?= e($record['client_id'] ?? '') ?>">
                        <datalist id="clientList">
                            <?php foreach ($clientSearchOptions as $clientName => $clientId): ?><option value="<?= e($clientName) ?>"></option><?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Type a client name and choose a matching active client.</div>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="timeReceived">
                        Time Received
                    </label>

                    <input
                        id="timeReceived"
                        class="form-control"
                        type="time"
                        name="time_received"
                        value="<?= e(
                                    substr(
                                        (string) ($record['time_received'] ?? ''),
                                        0,
                                        5
                                    )
                                ) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="pickUpTime">
                        Pick Up Time
                    </label>

                    <input
                        id="pickUpTime"
                        class="form-control"
                        type="time"
                        name="pick_up_time"
                        value="<?= e(
                                    substr(
                                        (string) ($record['pick_up_time'] ?? ''),
                                        0,
                                        5
                                    )
                                ) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="poNo">
                        PO No.
                    </label>

                    <input
                        id="poNo"
                        class="form-control"
                        name="po_no"
                        value="<?= e($record['po_no'] ?? '') ?>"
                        placeholder="Enter purchase order number"
                        autocomplete="off">
                </div>

            </div>
        </div>
    </section>

    <!-- =========================================================
         WITHDRAWAL ITEMS
    ========================================================== -->

    <section class="panel withdrawal-panel withdrawal-items-panel">

        <div class="panel-header withdrawal-items-header">
            <div>
                <h3>Withdrawal Items</h3>
                <p>
                    Search by product name, HT Code, or withdrawal description, then select a matching product.
                    Blank product rows are ignored.
                </p>
            </div>

            <div class="withdrawal-item-tools">
                <span
                    class="withdrawal-item-counter"
                    id="itemCount"
                    aria-live="polite">
                    <?= $usedItemCount ?> product line(s) entered
                </span>

                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    id="addLine">

                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Add line
                </button>
            </div>
        </div>

        <div class="table-responsive withdrawal-table-wrap">
            <table
                class="table withdrawal-items-table"
                id="itemsTable"
                data-pagination-exempt>

                <thead>
                    <tr>
                        <th scope="col" class="withdrawal-line-column">
                            Line
                        </th>

                        <th scope="col" class="withdrawal-product-column">
                            Product
                        </th>

                        <th scope="col" class="withdrawal-quantity-column">
                            Quantity
                        </th>

                        <th scope="col" class="withdrawal-kg-column">
                            Total KG
                        </th>

                        <th scope="col" class="withdrawal-remarks-column">
                            Remarks
                        </th>

                        <th
                            scope="col"
                            class="withdrawal-remove-column text-center">
                            Remove
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($values as $i => $it): ?>
                        <?php
                        $lineProductId = (string)($it['product_id'] ?? '');
                        $lineProductName = trim((string)($it['product_name'] ?? ''));
                        $lineProductDescription = trim((string)($it['ht_description'] ?? ''));
                        $currentProduct = $activeProductsById[$lineProductId] ?? null;
                        $lineProductLabel = $currentProduct
                            ? withdrawal_product_label((string)$currentProduct['product_name'], $currentProduct['ht_description'] ?? null)
                            : withdrawal_product_label($lineProductName, $lineProductDescription);
                        $lineQuantity = $it['quantity'] ?? '';

                        $lineTotalKg = null;

                        if (
                            $lineProductId !== '' &&
                            $lineQuantity !== '' &&
                            is_numeric($lineQuantity) &&
                            isset($productWeights[$lineProductId])
                        ) {
                            $lineTotalKg =
                                (float) $lineQuantity *
                                $productWeights[$lineProductId];
                        }
                        ?>

                        <tr>
                            <td class="line-no">
                                <?= $i + 1 ?>
                            </td>

                            <td>
                                <div class="withdrawal-product-autocomplete">
                                    <input class="form-control table-input product-name" name="items[<?= $i ?>][product_search]" value="<?= e($lineProductLabel) ?>" placeholder="Search product name, HT code, or description" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="productSuggestions<?= $i ?>" aria-label="Product for line <?= $i + 1 ?>">
                                    <div class="withdrawal-product-suggestions" id="productSuggestions<?= $i ?>" role="listbox" hidden></div>
                                </div>

                                <input
                                    type="hidden"
                                    name="items[<?= $i ?>][product_id]"
                                    class="product-id"
                                    value="<?= e($lineProductId) ?>">
                            </td>

                            <td>
                                <input
                                    class="form-control table-input item-quantity"
                                    name="items[<?= $i ?>][quantity]"
                                    type="number"
                                    min="1"
                                    step="1"
                                    value="<?= e($it['quantity'] ?? '') ?>"
                                    placeholder="0"
                                    inputmode="numeric"
                                    aria-label="Quantity for line <?= $i + 1 ?>">
                            </td>

                            <td>
                                <input
                                    type="text"
                                    class="form-control table-input total-kg"
                                    value="<?= e($formatDecimal($lineTotalKg)) ?>"
                                    placeholder="—"
                                    readonly
                                    aria-label="Total KG for line <?= $i + 1 ?>">
                            </td>

                            <td>
                                <input
                                    class="form-control table-input item-remarks"
                                    name="items[<?= $i ?>][remarks]"
                                    value="<?= e($it['remarks'] ?? '') ?>"
                                    placeholder="Optional remarks"
                                    aria-label="Remarks for line <?= $i + 1 ?>">
                            </td>

                            <td class="text-center">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger remove-line"
                                    aria-label="Remove line <?= $i + 1 ?>"
                                    title="Remove this line">

                                    <i
                                        class="bi bi-trash3"
                                        aria-hidden="true"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="withdrawal-table-footer">
            <span class="muted text-small" style="margin-left:8px;">
                Maximum of 30 item lines per withdrawal.
            </span>

            <span
                class="muted text-small"
                id="rowCount"
                aria-live="polite">
                <?= count($values) ?> rows displayed
            </span>
        </div>

        <div class="panel-body withdrawal-note">
            <label class="form-label" for="internalNote">
                Internal Note <span class="muted">(Optional)</span>
            </label>

            <textarea
                id="internalNote"
                class="form-control"
                rows="2"
                name="internal_note"
                placeholder="Add internal notes about this withdrawal"><?= e(
                                                                            $record['internal_note'] ?? ''
                                                                        ) ?></textarea>
        </div>
    </section>

    <!-- =========================================================
         FORM ACTIONS
    ========================================================== -->

    <div class="page-bottom-actions withdrawal-form-actions">

        <span class="muted text-small withdrawal-save-hint">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Changes are saved only when you click Save Withdrawal.
        </span>

        <div class="right withdrawal-action-buttons">

            <a href="withdrawals.php" class="btn btn-light">
                Cancel
            </a>

            <button
                class="btn btn-primary"
                type="submit"
                id="saveWithdrawal">

                <i class="bi bi-check-lg" aria-hidden="true"></i>
                Save Withdrawal
            </button>

            <?php if ($record): ?>
                <a
                    href="invoice_preparation.php?withdrawal_no=<?= e($nextNo) ?>"
                    class="btn btn-teal">

                    Prepare Invoice

                    <i
                        class="bi bi-arrow-right"
                        aria-hidden="true"></i>
                </a>
            <?php endif; ?>

        </div>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('#withdrawalForm');
        const table = document.querySelector('#itemsTable');
        const tbody = table.querySelector('tbody');
        const addLineButton = document.querySelector('#addLine');
        const itemCount = document.querySelector('#itemCount');
        const rowCount = document.querySelector('#rowCount');

        const MAX_LINES = 30;

        const productWeights = new Map(
            Object.entries(
                <?= json_encode(
                    $productWeights,
                    JSON_HEX_TAG |
                        JSON_HEX_APOS |
                        JSON_HEX_AMP |
                        JSON_HEX_QUOT |
                        JSON_UNESCAPED_UNICODE
                ) ?>
            )
        );
        const productCatalog = <?= json_encode($productSearchOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        const clientCatalog = new Map(Object.entries(<?= json_encode($clientSearchOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>));

        function productLabel(product) {
            return product.display_label;
        }

        function setupProductAutocomplete(root) {
            const input = root.querySelector('.product-name');
            const selectedId = root.closest('td').querySelector('.product-id');
            const list = root.querySelector('.withdrawal-product-suggestions');
            let matches = [];
            let activeIndex = -1;

            function closeSuggestions() {
                list.hidden = true;
                list.replaceChildren();
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                activeIndex = -1;
            }

            function choose(product) {
                input.value = productLabel(product);
                selectedId.value = String(product.product_id);
                closeSuggestions();
                input.dispatchEvent(new Event('selectionchange', {
                    bubbles: true
                }));
            }

            function renderSuggestions() {
                const query = input.value.trim().toLocaleLowerCase();
                selectedId.value = '';
                list.replaceChildren();
                if (!query) {
                    closeSuggestions();
                    return;
                }
                matches = productCatalog.filter(product => [product.product_name, product.ht_code, product.ht_description]
                        .some(value => String(value || '').toLocaleLowerCase().includes(query)))
                    .slice(0, 12);
                matches.forEach(product => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'withdrawal-product-suggestion';
                    option.id = list.id + '-option-' + product.product_id;
                    option.setAttribute('role', 'option');
                    option.setAttribute('aria-selected', 'false');
                    option.textContent = productLabel(product);
                    option.addEventListener('mousedown', event => event.preventDefault());
                    option.addEventListener('click', () => choose(product));
                    list.appendChild(option);
                });
                list.hidden = matches.length === 0;
                input.setAttribute('aria-expanded', matches.length ? 'true' : 'false');
            }

            function setActive(index) {
                const options = [...list.querySelectorAll('[role="option"]')];
                if (!options.length) return;
                activeIndex = (index + options.length) % options.length;
                options.forEach((option, optionIndex) => option.setAttribute('aria-selected', optionIndex === activeIndex ? 'true' : 'false'));
                input.setAttribute('aria-activedescendant', options[activeIndex].id);
                options[activeIndex].scrollIntoView({
                    block: 'nearest'
                });
            }

            input.addEventListener('input', renderSuggestions);
            input.addEventListener('keydown', event => {
                if (event.key === 'ArrowDown' && !list.hidden) {
                    event.preventDefault();
                    setActive(activeIndex + 1);
                } else if (event.key === 'ArrowUp' && !list.hidden) {
                    event.preventDefault();
                    setActive(activeIndex < 0 ? matches.length - 1 : activeIndex - 1);
                } else if (event.key === 'Enter' && !list.hidden && matches.length) {
                    event.preventDefault();
                    choose(matches[activeIndex >= 0 ? activeIndex : 0]);
                } else if (event.key === 'Escape') {
                    closeSuggestions();
                }
            });
            input.addEventListener('blur', () => window.setTimeout(closeSuggestions, 120));
        }

        document.querySelectorAll('.withdrawal-product-autocomplete').forEach(setupProductAutocomplete);

        function syncProductSelection(row) {
            const productInput = row.querySelector('.product-name');
            const productIdInput = row.querySelector('.product-id');
            const selected = productCatalog.find(product => String(product.product_id) === productIdInput.value.trim());
            if (!selected || productLabel(selected) !== productInput.value.trim()) productIdInput.value = '';
        }

        function syncClientSelection() {
            const clientName = document.querySelector('#clientName');
            const clientId = document.querySelector('#clientId');
            clientId.value = clientCatalog.get(clientName.value.trim()) || '';
        }

        const clientNameInput = document.querySelector('#clientName');
        clientNameInput.addEventListener('input', syncClientSelection);
        clientNameInput.addEventListener('change', syncClientSelection);

        function updateTotalKg(row) {
            const quantityInput = row.querySelector('.item-quantity');
            const totalKgInput = row.querySelector('.total-kg');

            const productId = row.querySelector('.product-id').value.trim();
            const quantityText = quantityInput.value.trim();

            if (productId === '' || quantityText === '') {
                totalKgInput.value = '';
                return;
            }

            const quantity = Number(quantityText);
            const kgPerBox = productWeights.get(productId);

            if (
                !Number.isFinite(quantity) ||
                quantity <= 0 ||
                !Number.isFinite(kgPerBox)
            ) {
                totalKgInput.value = '';
                return;
            }

            const totalKg = quantity * kgPerBox;

            // Keep up to 3 decimal places without unnecessary trailing zeros.
            totalKgInput.value = String(Number(totalKg.toFixed(3)));
        }

        function updateAllTotalKg() {
            [...tbody.rows].forEach((row) => {
                updateTotalKg(row);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Count entered products and displayed rows
        |--------------------------------------------------------------------------
        */

        function updateCounters() {
            const rows = [...tbody.rows];

            const filledLines = rows.filter((row) => {
                return row.querySelector('.product-name').value.trim() !== '';
            }).length;

            itemCount.textContent =
                filledLines + (filledLines === 1 ?
                    ' product line entered' :
                    ' product lines entered');

            rowCount.textContent =
                rows.length + (rows.length === 1 ?
                    ' row displayed' :
                    ' rows displayed');

            addLineButton.disabled = rows.length >= MAX_LINES;

            addLineButton.title = rows.length >= MAX_LINES ?
                'The 30-line limit has been reached' :
                'Add another item line';
        }

        /*
        |--------------------------------------------------------------------------
        | Update row numbers and submitted field names
        |--------------------------------------------------------------------------
        */

        function renumberRows() {
            [...tbody.rows].forEach((row, index) => {
                const lineNumber = index + 1;

                row.querySelector('.line-no').textContent = lineNumber;

                row.querySelector('.product-name').setAttribute(
                    'aria-label',
                    'Product for line ' + lineNumber
                );
                const productInput = row.querySelector('.product-name');
                const productSuggestions = row.querySelector('.withdrawal-product-suggestions');
                productSuggestions.id = 'productSuggestions' + index;
                productInput.setAttribute('aria-controls', productSuggestions.id);

                row.querySelector('.item-quantity').setAttribute(
                    'aria-label',
                    'Quantity for line ' + lineNumber
                );

                row.querySelector('.item-remarks').setAttribute(
                    'aria-label',
                    'Remarks for line ' + lineNumber
                );

                row.querySelector('.remove-line').setAttribute(
                    'aria-label',
                    'Remove line ' + lineNumber
                );

                row.querySelectorAll('input').forEach((input) => {
                    input.name = input.name.replace(
                        /items\[\d+\]/,
                        'items[' + index + ']'
                    );
                });
            });

            updateCounters();
        }

        /*
        |--------------------------------------------------------------------------
        | Add a new item line
        |--------------------------------------------------------------------------
        */

        addLineButton.addEventListener('click', () => {
            if (tbody.rows.length >= MAX_LINES) {
                Swal.fire({
                    icon: 'info',
                    title: 'Line limit reached',
                    text: 'A withdrawal can contain up to 30 item lines.'
                });

                return;
            }

            const lastRow = tbody.rows[tbody.rows.length - 1];
            const newRow = lastRow.cloneNode(true);

            newRow.querySelectorAll('input').forEach((input) => {
                input.value = '';
            });
            const suggestions = newRow.querySelector('.withdrawal-product-suggestions');
            suggestions.replaceChildren();
            suggestions.hidden = true;
            const productInput = newRow.querySelector('.product-name');
            productInput.setAttribute('aria-expanded', 'false');
            productInput.removeAttribute('aria-activedescendant');

            tbody.appendChild(newRow);
            setupProductAutocomplete(newRow.querySelector('.withdrawal-product-autocomplete'));

            renumberRows();

            newRow.querySelector('.product-name').focus();
        });

        /*
        |--------------------------------------------------------------------------
        | Remove an item line
        |--------------------------------------------------------------------------
        */

        tbody.addEventListener('click', async (event) => {
            const button = event.target.closest('.remove-line');

            if (!button) {
                return;
            }

            const row = button.closest('tr');

            const hasData = [...row.querySelectorAll('input')].some((input) => {
                return input.value.trim() !== '';
            });

            if (hasData) {
                const result = await Swal.fire({
                    title: 'Remove this item line?',
                    text: 'The line will be removed from this form.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Remove line',
                    cancelButtonText: 'Keep line',
                    confirmButtonColor: '#b42318',
                    reverseButtons: true
                });

                if (!result.isConfirmed) {
                    return;
                }
            }

            if (tbody.rows.length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach((input) => {
                    input.value = '';
                });
            }

            renumberRows();
        });

        /*
        |--------------------------------------------------------------------------
        | Keep visible product choice linked to its catalog record
        |--------------------------------------------------------------------------
        */

        tbody.addEventListener('change', (event) => {
            if (event.target.classList.contains('product-name')) {
                const row = event.target.closest('tr');

                syncProductSelection(row);

                updateTotalKg(row);
                updateCounters();
            }
        });

        tbody.addEventListener('selectionchange', event => {
            if (event.target.classList.contains('product-name')) {
                const row = event.target.closest('tr');
                updateTotalKg(row);
                updateCounters();
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Keep the item counter current while typing
        |--------------------------------------------------------------------------
        */

        tbody.addEventListener('input', (event) => {
            if (
                event.target.classList.contains('product-name') ||
                event.target.classList.contains('item-quantity')
            ) {
                const row = event.target.closest('tr');

                if (event.target.classList.contains('product-name')) {
                    syncProductSelection(row);
                }

                updateTotalKg(row);
                updateAllTotalKg();
                updateCounters();
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Validate incomplete item lines before submitting
        |--------------------------------------------------------------------------
        */

        form.addEventListener('submit', (event) => {
            const rows = [...tbody.rows];

            let invalidRow = null;
            let errorMessage = '';

            for (const row of rows) {
                const productInput = row.querySelector('.product-name');
                const productIdInput = row.querySelector('.product-id');
                const quantityInput = row.querySelector('.item-quantity');

                const productName = productInput.value.trim();
                const productId = productIdInput.value.trim();
                const quantityValue = quantityInput.value.trim();
                const quantity = Number(quantityValue);

                // A quantity without a product is an incomplete line.
                if (productName === '' && quantityValue !== '') {
                    invalidRow = productInput;
                    errorMessage =
                        'Enter or select a product for every line with a quantity.';
                    break;
                }

                // A product must have a valid positive quantity.
                if (productName !== '') {
                    if (productId === '') {
                        invalidRow = productInput;
                        errorMessage = 'Choose a product from the suggestions. Search by product name, HT code, or description.';
                        break;
                    }
                    if (
                        quantityValue === '' ||
                        !Number.isFinite(quantity) ||
                        quantity <= 0
                    ) {
                        invalidRow = quantityInput;
                        errorMessage =
                            'Enter a quantity greater than zero for each product.';
                        break;
                    }
                }
            }

            if (invalidRow) {
                event.preventDefault();

                Swal.fire({
                    icon: 'warning',
                    title: 'Check the withdrawal items',
                    text: errorMessage,
                    confirmButtonText: 'Review item'
                }).then(() => {
                    invalidRow.focus();
                });

                return;
            }

            const clientName = document.querySelector('#clientName');
            const clientId = document.querySelector('#clientId');
            const selectedClientId = clientCatalog.get(clientName.value.trim());
            if (!selectedClientId || String(selectedClientId) !== clientId.value) {
                event.preventDefault();
                clientName.classList.add('is-invalid');
                clientName.setAttribute('aria-invalid', 'true');
                Swal.fire({
                    icon: 'warning',
                    title: 'Choose a client',
                    text: 'Select an active client from the suggestions before saving.'
                }).then(() => clientName.focus());
                return;
            }

            // Prevent submission if the withdrawal contains no products.
            const hasProduct = rows.some((row) => {
                return row.querySelector('.product-id').value.trim() !== '';
            });

            if (!hasProduct) {
                event.preventDefault();

                Swal.fire({
                    icon: 'warning',
                    title: 'No withdrawal items',
                    text: 'Add at least one product before saving.',
                    confirmButtonText: 'Add product'
                }).then(() => {
                    tbody.querySelector('.product-name').focus();
                });
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Initialize counters on page load
        |--------------------------------------------------------------------------
        */

        updateCounters();
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>