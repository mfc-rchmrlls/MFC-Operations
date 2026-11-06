<?php $page_title = 'QuickBooks Status';

require __DIR__ . '/includes/layout_start.php';
$pdo = db();
$jobs = $pdo->query('SELECT j.*,i.invoice_number,w.withdrawal_no,c.client_name FROM qb_jobs j JOIN invoices i ON i.id=j.invoice_id JOIN withdrawals w ON w.id=i.withdrawal_id JOIN clients c ON c.id=i.customer_id ORDER BY j.id DESC')->fetchAll();
$counts = $pdo->query("SELECT status,COUNT(*) total FROM qb_jobs GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR); ?>
<div class="page-intro">
    <div>
        <h2>QuickBooks Integration Queue</h2>
        <p>This is legacy invoice-job history only. QuickBooks Desktop submission is disabled.</p>
    </div><span class="status-pill status-muted">Not connected</span>
</div>
<div class="notice warning"><strong>Safe retry rule:</strong> uncertain results must be reconciled against QuickBooks before any future retry. This page does not submit invoices; the local bridge and company-file configuration have not been implemented or approved.</div>
<div class="row g-3 mb-4"><?php foreach (['Queued', 'Processing', 'Invoiced', 'Error', 'Uncertain Result'] as $st): ?><div class="col-md">
            <div class="mini-kpi"><small><?= e($st) ?></small><strong><?= number_format((int)($counts[$st] ?? 0)) ?></strong></div>
        </div><?php endforeach; ?></div>
<section class="panel">
    <div class="panel-header">
        <div>
            <h3>Submission History</h3>
            <p><?= count($jobs) ?> stored job(s)</p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Job ID</th>
                    <th>Withdrawal</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>QuickBooks Invoice</th>
                    <th>Created</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody><?php if (!$jobs): ?><tr>
                        <td colspan="7">
                            <div class="empty-state"><strong>No legacy invoice jobs</strong>New typed export previews are tracked separately; neither workflow submits transactions in this build.</div>
                        </td>
                    </tr><?php endif; ?><?php foreach ($jobs as $j): ?><tr>
                        <td><?= e($j['request_key']) ?></td>
                        <td><?= e($j['withdrawal_no']) ?></td>
                        <td><?= e($j['client_name']) ?></td>
                        <td><span class="status-pill <?= e(status_class($j['status'])) ?>"><?= e($j['status']) ?></span></td>
                        <td><?= e($j['qb_invoice_no'] ?? '-') ?></td>
                        <td><?= e($j['created_at']) ?></td>
                        <td><?= e($j['error_message'] ?? '') ?></td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</section><?php require __DIR__ . '/includes/footer.php'; ?>