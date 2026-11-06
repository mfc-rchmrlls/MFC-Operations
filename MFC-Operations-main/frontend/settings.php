<?php $page_title = 'Settings';

require __DIR__ . '/includes/layout_start.php';
$pdo = db();
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'Admin';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf();
    try {
        if (($_POST['action'] ?? '') === 'product_site_permission') {
            $target = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $grant = ($_POST['granted'] ?? '') === '1';
            if (!$target || $target === (int)$_SESSION['user']['id']) throw new RuntimeException('Select an eligible Employee account.');
            $check = $pdo->prepare("SELECT id,username FROM employees WHERE id=? AND role='Employee'");
            $check->execute([$target]);
            $employee = $check->fetch();
            if (!$employee) throw new RuntimeException('The selected account is not an eligible Employee.');
            if ($grant) {
                $pdo->prepare("INSERT INTO employee_permissions(employee_id,permission_code,granted_by) VALUES(?,'manage_products_sites',?) ON DUPLICATE KEY UPDATE granted_by=VALUES(granted_by),granted_at=CURRENT_TIMESTAMP")->execute([$target, (int)$_SESSION['user']['id']]);
                log_activity($pdo, 'GRANT', 'employee_permission', $target, 'Granted manage_products_sites to ' . $employee['username']);
            } else {
                $pdo->prepare("DELETE FROM employee_permissions WHERE employee_id=? AND permission_code='manage_products_sites'")->execute([$target]);
                log_activity($pdo, 'REVOKE', 'employee_permission', $target, 'Revoked manage_products_sites from ' . $employee['username']);
            }
            flash('success', 'Product & Sites access updated.');
            header('Location: settings.php');
            exit;
        }
        $name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['Admin', 'Employee'], true) ? $_POST['role'] : 'Employee';
        if ($name === '' || $username === '' || strlen($password) < 10) throw new RuntimeException('Full name, username, and a password of at least 10 characters are required.');
        $s = $pdo->prepare('INSERT INTO employees(full_name,username,password_hash,role,active) VALUES(?,?,?,?,1)');
        $s->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
        log_activity($pdo, 'CREATE', 'employee', (int)$pdo->lastInsertId(), 'Created employee ' . $username . ' as ' . $role);
        flash('success', 'Employee account created.');
        header('Location: settings.php');
        exit;
    } catch (Throwable $e) {
        $err = $e instanceof PDOException ? 'Could not create account. The username may already exist.' : $e->getMessage();
    }
}
$employees = $isAdmin ? $pdo->query("SELECT e.id,e.full_name,e.username,e.role,e.active,e.created_at,IF(ep.employee_id IS NULL,0,1) manage_products_sites FROM employees e LEFT JOIN employee_permissions ep ON ep.employee_id=e.id AND ep.permission_code='manage_products_sites' ORDER BY e.id")->fetchAll() : [];
$logs = $isAdmin ? $pdo->query('SELECT a.*,e.full_name FROM activity_logs a LEFT JOIN employees e ON e.id=a.employee_id ORDER BY a.id DESC LIMIT 30')->fetchAll() : []; ?>
<div class="page-intro">
    <div>
        <h2>System Settings</h2>
        <p>Employee access and operational configuration.</p>
    </div><span class="status-pill <?= $isAdmin ? 'status-teal' : 'status-muted' ?>"><?= $isAdmin ? 'Administrator' : 'Employee access' ?></span>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
<?php if ($isAdmin): ?><div class="row g-3 mb-4">
        <div class="col-lg-5">
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h3>Create Employee</h3>
                        <p>Both roles can work on withdrawals and invoice drafts.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="post" data-confirm-submit="Create this employee account with the selected role?" data-confirm-title="Create employee account?" data-confirm-button="Create account"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><label class="form-label">Full name</label><input class="form-control mb-2" name="full_name" required><label class="form-label">Username</label><input class="form-control mb-2" name="username" required><label class="form-label">Temporary password (10+ characters)</label><input class="form-control mb-2" name="password" type="password" minlength="10" required><label class="form-label">Role</label><select class="form-select mb-3" name="role">
                            <option>Employee</option>
                            <option>Admin</option>
                        </select><button class="btn btn-primary">Create account</button></form>
                </div>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h3>Employee Accounts</h3>
                        <p><?= count($employees) ?> account(s)</p>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Manage Products &amp; Sites</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($employees as $emp): ?><tr>
                                    <td><?= e($emp['full_name']) ?></td>
                                    <td><?= e($emp['username']) ?></td>
                                    <td><?= e($emp['role']) ?></td>
                                    <td><span class="status-pill <?= $emp['active'] ? 'status-success' : 'status-danger' ?>"><?= $emp['active'] ? 'Active' : 'Inactive' ?></span></td>
                                    <td><?php if ($emp['role'] === 'Employee'): ?><form method="post" class="d-flex gap-2"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="product_site_permission"><input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>"><select class="form-select" name="granted" aria-label="Product and site permission for <?= e($emp['full_name']) ?>">
                                                    <option value="1" <?= $emp['manage_products_sites'] ? 'selected' : '' ?>>Granted</option>
                                                    <option value="0" <?= !$emp['manage_products_sites'] ? 'selected' : '' ?>>Not Granted</option>
                                                </select><button class="btn btn-sm btn-primary">Save</button></form><?php else: ?>Always granted<?php endif; ?></td>
                                </tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Recent Activity</h3>
                <p>Last 30 auditable actions</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Employee</th>
                        <th>Action</th>
                        <th>Record</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody><?php foreach ($logs as $log): ?><tr>
                            <td><?= e($log['created_at']) ?></td>
                            <td><?= e($log['full_name'] ?? 'System') ?></td>
                            <td><?= e($log['action']) ?></td>
                            <td><?= e($log['entity_type']) ?> <?= e($log['entity_id'] ?? '') ?></td>
                            <td><?= e($log['details'] ?? '') ?></td>
                            <td><?= e($log['ip_address'] ?? '') ?></td>
                        </tr><?php endforeach; ?><?php if (!$logs): ?><tr>
                            <td colspan="6">No activity recorded yet.</td>
                        </tr><?php endif; ?></tbody>
            </table>
        </div>
    </section><?php else: ?><section class="panel">
        <div class="panel-header">
            <div>
                <h3>Application Status</h3>
                <p>Database-backed Phase 2 status</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="setting-row">
                <div><strong>MySQL database</strong>
                    <p>Withdrawal and invoice draft records are stored in the local database.</p>
                </div><span class="status-pill status-success">Connected</span>
            </div>
            <div class="setting-row">
                <div><strong>QuickBooks Web Connector</strong>
                    <p>Phase 2 acceptance requires a live Web Connector and company-file test.</p>
                </div><span class="status-pill status-muted">Pending</span>
            </div>
        </div>
    </section><?php endif; ?>
<section class="panel">
    <div class="panel-header">
        <div>
            <h3>QuickBooks Desktop Connection</h3>
            <p>QuickBooks Enterprise Solutions 24.0</p>
        </div><span class="status-pill status-muted">Phase 2 setup</span>
    </div>
    <div class="panel-body">
        <div class="setting-row">
            <div><strong>Company file and Web Connector</strong>
                <p>Configure and test on one designated integration workstation before production.</p>
            </div><span class="setting-state">Not configured</span>
        </div>
        <div class="setting-row">
            <div><strong>Duplicate protection</strong>
                <p>Database uniqueness and uncertain-result handling must be verified with real Web Connector responses before production.</p>
            </div><span class="setting-state">Schema prepared</span>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>