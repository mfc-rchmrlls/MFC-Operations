<?php require_once __DIR__ . '/../../backend/config/app.php';
$page_title = $page_title ?? 'Dashboard'; ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="assets/images/MFC-Icon.png" type="image/png">
    <title><?= e($page_title) ?> | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/app.css') ?>" rel="stylesheet">
</head>

<body><a class="skip-link" href="#mainContent">Skip to content</a>
    <div class="app-shell">