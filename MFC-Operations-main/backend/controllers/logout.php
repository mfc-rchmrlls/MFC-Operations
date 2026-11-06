<?php require_once __DIR__ . '/../config/app.php';
if (!empty($_SESSION['user'])) {
    try {
        log_activity(db(), 'LOGOUT', 'employee', (int)$_SESSION['user']['id'], 'User signed out');
    } catch (Throwable $e) {
    }
}
logout_user();
header('Location: ' . APP_BASE_URL . '/frontend/login.php');
exit;
