<?php use function OpenSendForm\Admin\h; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title ?? 'OpenSendForm admin') ?></title>
    <link rel="stylesheet" href="/assets/vendor/pico.min.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <?php /* Blocking so the stored theme is applied before first paint. */ ?>
    <script src="/assets/theme.js"></script>
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
    <script src="<?= h($src) ?>" defer></script>
<?php endforeach; ?>
<script src="/assets/admin.js" defer></script>
</body>
</html>
