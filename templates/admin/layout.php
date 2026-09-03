<?php use function OpenSendForm\Admin\h; ?>
<?php use function OpenSendForm\Admin\asset; ?>
<!DOCTYPE html>
<html lang="en" data-palette="github">
<head>
    <?php /* First in <head>, blocking: applies the stored theme to <html>
             before first paint so there is no flash of the wrong theme. */ ?>
    <script src="<?= h(asset('/assets/theme-init.js')) ?>"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title ?? 'OpenSendForm admin') ?></title>
    <link rel="stylesheet" href="<?= h(asset('/assets/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('/assets/admin.css')) ?>">
</head>
<body>
<?php if (($showNav ?? false) === true): ?>
    <?php require __DIR__ . '/_nav.php'; ?>
<?php endif; ?>
<main class="container">
    <?php require __DIR__ . '/_flash.php'; ?>
    <?= $content /* already-escaped view output */ ?>
</main>
<?php foreach (($extraScripts ?? []) as $src): ?>
    <script src="<?= h(asset($src)) ?>" defer></script>
<?php endforeach; ?>
<script src="<?= h(asset('/assets/admin.js')) ?>" defer></script>
</body>
</html>
