<?php $page_title = 'Dashboard';

require __DIR__ . '/includes/layout_start.php';
$pdo = db();
$stats = [];
foreach (['Draft', 'Ready for Invoicing', 'Invoice Preparation', 'Invoiced'] as $st) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM withdrawals WHERE status=?');
    $s->execute([$st]);
    $stats[$st] = (int)$s->fetchColumn();
}
$queue = (int)$pdo->query("SELECT COUNT(*) FROM qb_jobs WHERE status IN ('Queued','Processing')")->fetchColumn();
$recent = $pdo->query('SELECT w.withdrawal_no,w.form_date,w.status,c.client_name,COUNT(wi.id) item_count FROM withdrawals w JOIN clients c ON c.id=w.client_id LEFT JOIN withdrawal_items wi ON wi.withdrawal_id=w.id GROUP BY w.id ORDER BY w.id DESC LIMIT 6')->fetchAll(); ?>
<div class="page-intro">
    <div>
        <h2>Welcome, <?= e($_SESSION['user']['name']) ?></h2>
        <p>Live snapshot of your withdrawal and invoicing workflow.</p>
    </div>
    <div class="toolbar"><a href="withdrawal_form.php" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> New Withdrawal</a><a href="withdrawals.php" class="btn btn-light">Withdrawal Register</a></div>
</div>
<div class="card-grid">
    <div class="stat-card">
        <div class="stat-top"><span>Draft Withdrawals</span><i class="bi bi-file-earmark-text-fill stat-icon" aria-hidden="true"></i></div>
        <div class="stat-value"><?= number_format($stats['Draft']) ?></div>
        <div class="stat-note">Awaiting completion</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span>Ready for Invoicing</span><i class="bi bi-receipt-cutoff stat-icon" aria-hidden="true"></i></div>
        <div class="stat-value"><?= number_format($stats['Ready for Invoicing']) ?></div>
        <div class="stat-note">Available to invoice</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span>Invoices Processing</span><i class="bi bi-arrow-repeat stat-icon" aria-hidden="true"></i></div>
        <div class="stat-value"><?= number_format($queue) ?></div>
        <div class="stat-note">Queue count</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span>Invoiced Withdrawals</span><i class="bi bi-check2-circle stat-icon" aria-hidden="true"></i></div>
        <div class="stat-value"><?= number_format($stats['Invoiced']) ?></div>
        <div class="stat-note">Confirmed status only</div>
    </div>
</div>
<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Recent Withdrawals</h3>
                <p>Latest saved records</p>
            </div><a href="withdrawals.php" class="btn btn-light">View register <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Withdrawal No.</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody><?php if (!$recent): ?><tr>
                            <td colspan="5">No withdrawals saved yet.</td>
                        </tr><?php endif; ?><?php foreach ($recent as $r): ?><tr>
                            <td><a class="record-no" href="withdrawal_form.php?withdrawal_no=<?= e($r['withdrawal_no']) ?>"><?= e($r['withdrawal_no']) ?></a></td>
                            <td><?= e($r['client_name']) ?></td>
                            <td><?= e(date('M d, Y', strtotime($r['form_date']))) ?></td>
                            <td><?= (int)$r['item_count'] ?></td>
                            <td><span class="status-pill <?= e(status_class($r['status'])) ?>"><?= e($r['status']) ?></span></td>
                        </tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </section>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Quick Actions</h3>
                <p>Common tasks</p>
            </div>
        </div>
        <div class="panel-body"><a class="quick-action" href="withdrawal_form.php"><i class="bi bi-plus-square-fill action-icon" aria-hidden="true"></i><span><strong>Create a withdrawal</strong><small>Record products and collection details</small></span><i class="bi bi-chevron-right action-arrow" aria-hidden="true"></i></a><a class="quick-action" href="withdrawals.php"><i class="bi bi-search action-icon" aria-hidden="true"></i><span><strong>Find a withdrawal</strong><small>Search saved records</small></span><i class="bi bi-chevron-right action-arrow" aria-hidden="true"></i></a><a class="quick-action" href="invoice_preparation.php"><i class="bi bi-receipt-cutoff action-icon" aria-hidden="true"></i><span><strong>Prepare an invoice</strong><small>Maintain separate invoice values</small></span><i class="bi bi-chevron-right action-arrow" aria-hidden="true"></i></a><a class="quick-action" href="sales_order_preparation.php"><i class="bi bi-file-earmark-text action-icon" aria-hidden="true"></i><span><strong>Prepare a Sales Order</strong><small>Save a local Sales Order draft</small></span><i class="bi bi-chevron-right action-arrow" aria-hidden="true"></i></a></div>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>