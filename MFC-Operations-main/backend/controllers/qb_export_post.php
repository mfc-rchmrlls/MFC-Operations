<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php');
    exit;
}
verify_csrf();

flash('warning', 'QuickBooks transaction submission is not supported. Any saved export remains a pending preview; no QuickBooks transaction was created.');

header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php', true, 303);
exit;
