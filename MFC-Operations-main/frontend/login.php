<?php
require_once __DIR__ . '/../backend/config/app.php';
if (!empty($_SESSION['user'])) {
    header('Location: ' . APP_BASE_URL . '/frontend/dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $s = db()->prepare('SELECT * FROM employees WHERE username=? AND active=1 LIMIT 1');
    $s->execute([$username]);
    $u = $s->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        login_user($u);
        $s = db()->prepare('UPDATE employees SET updated_at=updated_at WHERE id=?');
        $s->execute([$u['id']]);
        log_activity(db(), 'LOGIN', 'employee', (int)$u['id'], 'Successful login');
        header('Location: ' . APP_BASE_URL . '/frontend/dashboard.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <link rel="icon" href="assets/images/MFC-Icon.png" type="image/png">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body style="background:url('assets/images/.png') no-repeat center center fixed; 
             background-size:contain;"
    class="d-flex align-items-center justify-content-center vh-100">
    <main class="container" style="max-width:480px">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h3">Withdrawal & Invoicing</h1>
                <p class="text-secondary">Sign in to your local office system.</p>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <label class="form-label">Username</label>
                    <input class="form-control mb-3" name="username" autocomplete="username" required autofocus>
                    <label class="form-label">Password</label>
                    <input class="form-control mb-3" type="password" name="password" autocomplete="current-password" required>
                    <button class="btn btn-primary w-100">Sign in</button>
                </form>
            </div>
        </div>
    </main>
</body>

</html>