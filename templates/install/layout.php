<?php use function OpenSendForm\Admin\h; ?>
<!DOCTYPE html>
<html lang="en" data-palette="github">
<head>
    <?php /* First in <head>, blocking: applies the stored theme before first
             paint (shares the admin design system). */ ?>
    <script src="/assets/theme-init.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title ?? 'Install OpenSendForm') ?></title>
    <link rel="stylesheet" href="/assets/tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<header class="osf-header">
    <div class="osf-header-inner container">
        <span class="osf-brand">OpenSendForm</span>
        <div class="osf-header-actions">
            <span class="osf-admin-name">Setup</span>
        </div>
    </div>
</header>
<main class="container">
    <?php foreach (($flashes ?? []) as $message): ?>
        <p class="osf-flash osf-flash--error" role="alert"><?= h($message) ?></p>
    <?php endforeach; ?>
    <?= $content /* already-escaped view output */ ?>
</main>
<script src="/assets/admin.js" defer></script>
<script src="/assets/install.js" defer></script>
</body>
</html>
