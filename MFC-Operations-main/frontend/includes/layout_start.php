<?php
require_once __DIR__ . '/../../backend/config/app.php';
require_login();
require __DIR__ . '/header.php';
require __DIR__ . '/sidebar.php';
?>
<main class="main-content" id="mainContent" tabindex="-1">
    <?php require __DIR__ . '/topbar.php'; ?>
    <div class="page-content">
        <?php $notice = take_flash();
        if ($notice): ?>
            <div class="alert alert-<?= e($notice['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($notice['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
            </div>
        <?php endif; ?>