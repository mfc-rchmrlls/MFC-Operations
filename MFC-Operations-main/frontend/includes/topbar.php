<header class="topbar">
    <div class="topbar-heading"><button class="mobile-nav-toggle" type="button" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false"><i class="bi bi-list" aria-hidden="true"></i></button>
        <div>
            <div class="breadcrumb-line">Workspace <span>/</span> <?= e($page_title) ?></div>
            <h1><?= e($page_title) ?></h1>
        </div>
    </div>
    <div class="topbar-right">
        <div class="user-avatar" aria-hidden="true"><?= e(strtoupper(substr($_SESSION['user']['name'], 0, 2))) ?></div>
        <div class="user-copy"><strong><?= e($_SESSION['user']['name']) ?></strong><small><?= e($_SESSION['user']['role']) ?> | <a href="<?= e(APP_BASE_URL) ?>/backend/controllers/logout.php" data-confirm="Sign out of your account?" data-confirm-title="Sign out?" data-confirm-icon="question">Sign out</a></small></div>
    </div>
</header>