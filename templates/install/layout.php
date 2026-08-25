<?php use function OpenSendForm\Admin\h; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title ?? 'Install OpenSendForm') ?></title>
    <link rel="stylesheet" href="/assets/vendor/pico.min.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <?php /* Blocking so the stored theme is applied before first paint. */ ?>
    <script src="/assets/theme.js"></script>
</head>
<body>
<main class="container">
    <p class="osf-brand"><strong>OpenSendForm</strong> · Setup</p>
    <?php foreach (($flashes ?? []) as $message): ?>
        <p class="osf-flash osf-flash--error" role="alert"><?= h($message) ?></p>
    <?php endforeach; ?>
    <?= $content /* already-escaped view output */ ?>
</main>
<script src="/assets/admin.js" defer></script>
</body>
</html>
