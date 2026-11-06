<?php
require_once __DIR__ . '/../backend/config/app.php';
$message = '';
try {
    $count = (int)db()->query('SELECT COUNT(*) FROM employees')->fetchColumn();
    if ($count > 0) {
        $message = 'Setup is already complete. Please delete or rename setup.php for security.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || $username === '' || strlen($password) < 10) throw new RuntimeException('Enter a name, username, and password with at least 10 characters.');
        $s = db()->prepare("INSERT INTO employees(full_name,username,password_hash,role,active) VALUES(?,?,?,'Admin',1)");
        $s->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        $message = 'Administrator created. Delete setup.php now, then sign in.';
    }
} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $message = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Initial Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <main class="container py-5" style="max-width:560px">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h3">Initial administrator setup</h1>
                <p>Create the first admin account for the local application.</p><?php if ($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?><?php if (!$message || str_contains($message, 'Enter a name')): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" required><label class="form-label">Username</label><input class="form-control mb-3" name="username" required><label class="form-label">Password (10+ characters)</label><input class="form-control mb-3" type="password" name="password" minlength="10" required><button class="btn btn-primary">Create administrator</button></form><?php endif; ?>
            </div>
        </div>
    </main>
</body>

</html>