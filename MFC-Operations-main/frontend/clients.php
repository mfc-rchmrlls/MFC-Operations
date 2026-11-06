<?php
$page_title = 'Clients';
require_once __DIR__ . '/../backend/config/app.php';
require_login();
$pdo = db();
$isAdmin = (($_SESSION['user']['role'] ?? '') === 'Admin');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        $name = trim((string)($_POST['client_name'] ?? ''));
        $active = ($_POST['active'] ?? '1') === '1' ? 1 : 0;
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!in_array($action, ['save_client', 'update_client'], true) || $name === '' || mb_strlen($name) > 180) throw new RuntimeException('Enter a client name of 180 characters or fewer.');
        if ($action === 'update_client') {
            if (!$id) throw new RuntimeException('Invalid client.');
            $q = $pdo->prepare('UPDATE clients SET client_name=?,active=? WHERE id=?');
            $q->execute([$name, $active, $id]);
            if (!$q->rowCount()) {
                $check = $pdo->prepare('SELECT 1 FROM clients WHERE id=?');
                $check->execute([$id]);
                if (!$check->fetchColumn()) throw new RuntimeException('Client not found.');
            }
            log_activity($pdo, 'UPDATE', 'client', $id, 'Updated client ' . $name);
        } else {
            $pdo->prepare('INSERT INTO clients(client_name,active) VALUES(?,1)')->execute([$name]);
            $id = (int)$pdo->lastInsertId();
            log_activity($pdo, 'CREATE', 'client', $id, 'Created client ' . $name);
        }
        flash('success', 'Changes saved.');
        header('Location: clients.php');
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof PDOException ? 'Could not save the client. Please check the values and try again.' : $e->getMessage();
    }
}
$search = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search) > 180) $search = mb_substr($search, 0, 180);
$pageSize = 10;
$requestedPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$totalClientsQuery = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE client_name LIKE ?');
$totalClientsQuery->execute(['%' . $search . '%']);
$filteredClientCount = (int)$totalClientsQuery->fetchColumn();
$totalPages = max(1, (int)ceil($filteredClientCount / $pageSize));
$page = min($requestedPage, $totalPages);
$offset = ($page - 1) * $pageSize;
$clientQuery = $pdo->prepare('SELECT c.id,c.client_name,c.active,(SELECT COUNT(*) FROM invoices i WHERE i.customer_id=c.id) invoice_count FROM clients c WHERE c.client_name LIKE ? ORDER BY c.client_name,c.id LIMIT ' . $pageSize . ' OFFSET ' . $offset);
$clientQuery->execute(['%' . $search . '%']);
$clients = $clientQuery->fetchAll();
$summary = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(active=1),0) active FROM clients')->fetch();
require __DIR__ . '/includes/layout_start.php';
?>

<div class="page-intro">
    <div>
        <p>Manage clients and review their invoice history.</p>
    </div><?php if ($isAdmin): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#clientModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Client</button><?php endif; ?>
</div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel p-3 p-md-4">
    <div class="row align-items-end mb-3">
        <div class="col-md-6">
            <form method="get" action="clients.php" class="d-flex gap-2">
                <div class="flex-grow-1"><label for="clientSearch" class="form-label">Search clients</label><input class="form-control" id="clientSearch" name="q" type="search" value="<?= e($search) ?>" placeholder="Search by client name"></div><input type="hidden" name="page" value="1"><button class="btn btn-outline-primary align-self-end" type="submit">Search</button><?php if ($search !== ''): ?><a class="btn btn-light align-self-end" href="clients.php">Clear</a><?php endif; ?>
            </form>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0"><span class="text-secondary"><?= (int)$summary['total'] ?> clients &middot; <?= (int)$summary['active'] ?> active</span></div>
    </div>
    <div class="accordion" id="clientsAccordion">
        <?php foreach ($clients as $client): $cid = (int)$client['id'];
            $collapseId = 'clientHistory' . $cid; ?>
            <div class="accordion-item client-accordion-item" data-client-name="<?= e(mb_strtolower($client['client_name'])) ?>">
                <h3 class="accordion-header" id="clientHeading<?= $cid ?>"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= e($collapseId) ?>" aria-expanded="false" aria-controls="<?= e($collapseId) ?>"><span class="flex-grow-1"><strong><?= e($client['client_name']) ?></strong><span class="badge rounded-pill ms-2 <?= $client['active'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $client['active'] ? 'Active' : 'Inactive' ?></span></span><span class="small text-secondary me-3"><?= (int)$client['invoice_count'] ?> invoices</span></button></h3>
                <div id="<?= e($collapseId) ?>" class="accordion-collapse collapse" aria-labelledby="clientHeading<?= $cid ?>" data-client-id="<?= $cid ?>">
                    <div class="accordion-body"><?php if ($isAdmin): ?><button type="button" class="btn btn-sm btn-outline-primary mb-3" data-bs-toggle="modal" data-bs-target="#editClient<?= $cid ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edit Client</button><?php endif; ?><div class="history-content" aria-live="polite">
                            <div class="text-secondary">Expand to load invoice history.</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div><?php if (!$clients): ?><div class="text-center text-secondary py-4"><?= $search !== '' ? 'No matching clients.' : 'No clients found.' ?></div><?php endif; ?>
    <?php if ($totalPages > 1): ?><nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" aria-label="Client list pages"><span class="small text-secondary">Showing <?= $offset + 1 ?>–<?= min($offset + $pageSize, $filteredClientCount) ?> of <?= $filteredClientCount ?><?= $search !== '' ? ' matching' : '' ?> clients</span>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?q=<?= rawurlencode($search) ?>&amp;page=<?= max(1, $page - 1) ?>" aria-label="Previous page">Previous</a></li><?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?q=<?= rawurlencode($search) ?>&amp;page=<?= $p ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a></li><?php endfor; ?><li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?q=<?= rawurlencode($search) ?>&amp;page=<?= min($totalPages, $page + 1) ?>" aria-label="Next page">Next</a></li>
            </ul>
        </nav><?php elseif ($filteredClientCount > 0): ?><div class="small text-secondary mt-3">Showing 1–<?= $filteredClientCount ?> of <?= $filteredClientCount ?><?= $search !== '' ? ' matching' : '' ?> clients</div><?php endif; ?>
</section>
<div class="modal fade" id="invoiceDetailModal" tabindex="-1" aria-labelledby="invoiceDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="invoiceDetailTitle">Invoice Details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="invoiceDetailBody" aria-live="polite">
                <div class="text-secondary">Select an invoice to view details.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<?php if ($isAdmin): ?><div class="modal fade" id="clientModal" tabindex="-1" aria-labelledby="clientModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_client">
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="clientModalTitle">Add Client</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body"><label class="form-label" for="newClientName">Client Name</label><input class="form-control" id="newClientName" name="client_name" maxlength="180" required></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Client</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php foreach ($clients as $client): ?><div class="modal fade" id="editClient<?= (int)$client['id'] ?>" tabindex="-1" aria-labelledby="editClientTitle<?= (int)$client['id'] ?>">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_client"><input type="hidden" name="id" value="<?= (int)$client['id'] ?>">
                        <div class="modal-header">
                            <h3 class="modal-title fs-5" id="editClientTitle<?= (int)$client['id'] ?>">Edit Client</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body"><label class="form-label" for="editName<?= (int)$client['id'] ?>">Client Name</label><input class="form-control" id="editName<?= (int)$client['id'] ?>" name="client_name" maxlength="180" value="<?= e($client['client_name']) ?>" required><label class="form-label mt-3" for="editActive<?= (int)$client['id'] ?>">Status</label><select class="form-select" id="editActive<?= (int)$client['id'] ?>" name="active">
                                <option value="1" <?= $client['active'] ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= !$client['active'] ? 'selected' : '' ?>>Inactive</option>
                            </select></div>
                        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Client</button></div>
                    </form>
                </div>
            </div>
        </div><?php endforeach; ?><?php endif; ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const detailModal = document.getElementById('invoiceDetailModal');
                const detailTitle = document.getElementById('invoiceDetailTitle');
                const detailBody = document.getElementById('invoiceDetailBody');
                let detailRequest = 0;
                let lastDetailRequest = null;

                const formatPeso = value => '₱' + Number(value).toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                })[char]);

                document.querySelectorAll('.accordion-collapse[data-client-id]').forEach(panel => {
                    panel.addEventListener('show.bs.collapse', async () => {
                        if (panel.dataset.historyLoaded === 'true') return;
                        await loadHistory(panel, 1);
                    });
                });

                async function loadHistory(panel, page) {
                    const box = panel.querySelector('.history-content');
                    const hadHistory = panel.dataset.historyLoaded === 'true';
                    if (!hadHistory) {
                        box.innerHTML = '<div class="text-secondary">Loading invoice history…</div>';
                    }
                    const clientId = Number(panel.dataset.clientId);
                    const url = '../backend/api/client_invoice_history.php?client_id=' + encodeURIComponent(clientId) + '&page=' + encodeURIComponent(page);
                    try {
                        const response = await fetch(url, {
                            headers: {
                                Accept: 'application/json'
                            },
                            credentials: 'same-origin'
                        });
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.error || 'Request failed');
                        if (!data.total) {
                            box.innerHTML = '<p class="mb-0 text-secondary">No invoice history found for this client.</p>';
                            panel.dataset.historyLoaded = 'true';
                            return true;
                        }
                        const historyRows = data.items.map(invoice => `<tr>
            <td><button type="button" class="btn btn-link p-0 text-start" data-bs-toggle="modal" data-bs-target="#invoiceDetailModal" data-invoice-id="${Number(invoice.id)}" data-client-id="${clientId}">${escapeHtml(invoice.invoice_number || '—')}</button></td>
            <td>${escapeHtml(invoice.invoice_date)}</td><td>${escapeHtml(invoice.withdrawal_no)}</td>
            <td>${escapeHtml(invoice.item_count)}</td><td>${escapeHtml(Number(invoice.total_quantity || 0).toLocaleString('en-PH', { maximumFractionDigits: 0 }))}</td>
            <td>${invoice.invoice_amount === null ? '—' : escapeHtml(formatPeso(invoice.invoice_amount))}</td>
            <td><span class="badge ${invoice.status === 'Invoiced' ? 'text-bg-success' : 'text-bg-secondary'}">${escapeHtml(invoice.status)}</span></td>
        </tr>`).join('');
                        const first = (data.page - 1) * 10 + 1;
                        const last = Math.min(data.page * 10, data.total);
                        let pageButtons = '';
                        for (let p = Math.max(1, data.page - 2); p <= Math.min(data.pages, data.page + 2); p++) {
                            pageButtons += `<button type="button" class="btn btn-sm ${p === data.page ? 'btn-primary' : 'btn-outline-secondary'}" data-page="${p}" ${p === data.page ? 'aria-current="page"' : ''}>${p}</button>`;
                        }
                        box.innerHTML = `<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Invoice No.</th><th>Invoice Date</th><th>Withdrawal No.</th><th>Items</th><th>Total Quantity</th><th>Invoice Amount (PHP)</th><th>Status</th></tr></thead><tbody>${historyRows}</tbody></table></div>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2"><span class="small text-secondary">Showing ${first}–${last} of ${data.total} invoices</span>
            <div class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-page="${data.page - 1}" ${data.page <= 1 ? 'disabled' : ''}>Previous</button>${pageButtons}<button type="button" class="btn btn-sm btn-outline-secondary" data-page="${data.page + 1}" ${data.page >= data.pages ? 'disabled' : ''}>Next</button></div></div>`;
                        panel.dataset.historyLoaded = 'true';
                        box.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click', () => loadHistory(panel, Number(button.dataset.page))));
                        return true;
                    } catch (error) {
                        let errorBox = box.querySelector('[data-history-load-error]');
                        if (!errorBox) {
                            errorBox = document.createElement('div');
                            errorBox.dataset.historyLoadError = '';
                            box.prepend(errorBox);
                        }
                        errorBox.innerHTML = '<div class="alert alert-danger mb-3">Unable to load invoice history. <button type="button" class="btn btn-sm btn-outline-danger ms-2" data-history-retry>Retry</button></div>';
                        errorBox.querySelector('[data-history-retry]').addEventListener('click', () => loadHistory(panel, page));
                        return false;
                    }
                }

                detailModal.addEventListener('show.bs.modal', event => {
                    const trigger = event.relatedTarget;
                    lastDetailRequest = {
                        invoiceId: Number(trigger.dataset.invoiceId),
                        clientId: Number(trigger.dataset.clientId)
                    };
                    loadInvoiceDetail(lastDetailRequest);
                });
                detailModal.addEventListener('hide.bs.modal', () => {
                    detailRequest++;
                });

                async function loadInvoiceDetail(params) {
                    const requestId = ++detailRequest;
                    detailTitle.textContent = 'Invoice Details';
                    detailBody.innerHTML = '<div class="text-secondary py-4 text-center"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Loading invoice details…</div>';
                    try {
                        const url = '../backend/api/client_invoice_detail.php?invoice_id=' + encodeURIComponent(params.invoiceId) + '&client_id=' + encodeURIComponent(params.clientId);
                        const response = await fetch(url, {
                            headers: {
                                Accept: 'application/json'
                            },
                            credentials: 'same-origin'
                        });
                        const invoice = await response.json();
                        if (requestId !== detailRequest) return;
                        if (!response.ok) throw new Error(invoice.error || 'Request failed');
                        detailTitle.textContent = 'Invoice ' + (invoice.invoice_number || 'Details');
                        const lines = invoice.lines.map((line, index) => `<tr><td>${index + 1}</td><td>${escapeHtml(line.item_code || '—')}</td><td>${escapeHtml(line.product_name)}</td><td>${escapeHtml(line.description || '—')}</td><td>${escapeHtml(line.site_name || '—')}</td><td class="text-end">${escapeHtml(Number(line.quantity).toLocaleString('en-PH', { maximumFractionDigits: 0 }))}</td><td>${escapeHtml(line.u_m || '—')}</td><td class="text-end">${escapeHtml(formatPeso(line.price_each))}</td><td>${escapeHtml(line.tax_code || '—')}</td><td class="text-end">${escapeHtml(formatPeso(line.amount))}</td></tr>`).join('');
                        const lineTable = lines ? `<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Line</th><th>Product Code</th><th>Product</th><th>Description</th><th>Site</th><th class="text-end">Qty</th><th>Unit</th><th class="text-end">Unit Price (PHP)</th><th>Tax Code</th><th class="text-end">Line Total (PHP)</th></tr></thead><tbody>${lines}</tbody></table></div>` : '<p class="text-secondary mb-0">No invoice product rows were saved for this invoice.</p>';
                        detailBody.innerHTML = `<dl class="row mb-4"><dt class="col-sm-3">Invoice Number</dt><dd class="col-sm-9">${escapeHtml(invoice.invoice_number || '—')}</dd><dt class="col-sm-3">Invoice Date</dt><dd class="col-sm-9">${escapeHtml(invoice.invoice_date)}</dd><dt class="col-sm-3">Client</dt><dd class="col-sm-9">${escapeHtml(invoice.client_name)}</dd><dt class="col-sm-3">Withdrawal</dt><dd class="col-sm-9">${escapeHtml(invoice.withdrawal_no)}</dd><dt class="col-sm-3">Status</dt><dd class="col-sm-9">${escapeHtml(invoice.status)}</dd>${invoice.po_no ? `<dt class="col-sm-3">PO No.</dt><dd class="col-sm-9">${escapeHtml(invoice.po_no)}</dd>` : ''}</dl>${lineTable}<div class="d-flex justify-content-end border-top pt-3 mt-3"><strong>Line Amount Subtotal: ${escapeHtml(formatPeso(invoice.subtotal))}</strong></div>`;
                    } catch (error) {
                        if (requestId !== detailRequest) return;
                        detailBody.innerHTML = '<div class="alert alert-danger mb-0">Unable to load invoice details. <button type="button" class="btn btn-sm btn-outline-danger ms-2" data-detail-retry>Retry</button></div>';
                        detailBody.querySelector('[data-detail-retry]').addEventListener('click', () => {
                            if (lastDetailRequest) loadInvoiceDetail(lastDetailRequest);
                        });
                    }
                }
            });
        </script>
        <?php require __DIR__ . '/includes/footer.php'; ?>