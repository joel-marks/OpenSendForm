<?php use function OpenSendForm\Admin\h; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title ?? 'OpenSendForm admin') ?></title>
</head>
<body>
<main>
<?= $content /* already-escaped view output */ ?>
</main>
</body>
</html>
