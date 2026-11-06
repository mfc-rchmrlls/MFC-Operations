<aside class="sidebar" id="appSidebar">
    <a class="brand" href="dashboard.php"><span class="brand-mark">MFC</span><span class="brand-copy"><strong>Withdrawal</strong><small>&amp; Invoicing</small></span></a>
    <div class="nav-label">WORKSPACE</div>
    <nav class="nav flex-column app-nav" aria-label="Workspace">
        <a class="nav-link <?= nav_active('dashboard.php') ?>" href="dashboard.php"><i class="bi bi-grid-1x2-fill nav-icon" aria-hidden="true"></i>Dashboard</a>
        <a class="nav-link <?= nav_active('withdrawals.php') ?>" href="withdrawals.php"><i class="bi bi-inboxes-fill nav-icon" aria-hidden="true"></i>Withdrawal Register</a>
        <a class="nav-link <?= nav_active('withdrawal_form.php') ?>" href="withdrawal_form.php"><i class="bi bi-plus-square-fill nav-icon" aria-hidden="true"></i>Withdrawal Form</a>
        <a class="nav-link <?= nav_active('invoice_preparation.php') ?>" href="invoice_preparation.php"><i class="bi bi-receipt-cutoff nav-icon" aria-hidden="true"></i>Invoice Preparation</a>
        <a class="nav-link <?= nav_active('sales_order_preparation.php') ?>" href="sales_order_preparation.php"><i class="bi bi-file-earmark-text nav-icon" aria-hidden="true"></i>Sales Order Preparation</a>
        <?php if (($_SESSION['user']['role'] ?? '') === 'Admin'): ?><a class="nav-link <?= nav_active('quickbooks_exports.php') ?>" href="quickbooks_exports.php"><i class="bi bi-arrow-repeat nav-icon" aria-hidden="true"></i>QuickBooks Export Preview</a><?php endif; ?>
        <!-- <a class="nav-link <?= nav_active('quickbooks_status.php') ?>" href="quickbooks_status.php"><i class="bi bi-arrow-repeat nav-icon" aria-hidden="true"></i>QuickBooks Status</a> -->
    </nav>
    <div class="nav-label nav-label-spaced">SETUP</div>
    <nav class="nav flex-column app-nav" aria-label="Setup">
        <a class="nav-link <?= nav_active('clients.php') ?>" href="clients.php"><i class="bi bi-people-fill nav-icon" aria-hidden="true"></i>Clients</a>
        <?php if (can_manage_products_sites() || (($_SESSION['user']['role'] ?? '') === 'Employee')): ?><a class="nav-link <?= nav_active('products_sites.php') ?>" href="products_sites.php"><i class="bi bi-box-seam-fill nav-icon" aria-hidden="true"></i>Products &amp; Sites</a><?php endif; ?>
        <a class="nav-link <?= nav_active('settings.php') ?>" href="settings.php"><i class="bi bi-gear-fill nav-icon" aria-hidden="true"></i>Settings</a>
    </nav>
    <div class="sidebar-bottom">
        <div class="connection-dot"></div><span>QuickBooks connection</span><strong>Integration test pending</strong><small>MySQL backend enabled</small>
    </div>
</aside>
<button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>