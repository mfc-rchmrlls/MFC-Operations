<?php

declare(strict_types=1);
function require_login(): void
{
    if (empty($_SESSION['user'])) {
        header('Location: ' . APP_BASE_URL . '/frontend/login.php');
        exit;
    }
}
function require_admin(): void
{
    require_login();
    if (($_SESSION['user']['role'] ?? '') !== 'Admin') {
        http_response_code(403);
        exit('Administrator access is required.');
    }
}
function can_manage_products_sites(?PDO $pdo = null): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'Admin') return true;
    if (empty($_SESSION['user']['id'])) return false;
    try {
        $pdo ??= db();
        $s = $pdo->prepare("SELECT 1 FROM employee_permissions WHERE employee_id=? AND permission_code='manage_products_sites'");
        $s->execute([(int)$_SESSION['user']['id']]);
        return (bool)$s->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}
function require_products_sites_manager(): void
{
    require_login();
    if (!can_manage_products_sites()) {
        http_response_code(403);
        exit('Product and site management access is required.');
    }
}
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$user['id'], 'name' => $user['full_name'], 'username' => $user['username'], 'role' => $user['role']];
}
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
