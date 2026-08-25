<?php
use function OpenSendForm\Admin\h;

/** @var string $activeNav */
$active = $activeNav ?? '';
$link = static function (string $key, string $href, string $label) use ($active): string {
    $aria = $key === $active ? ' aria-current="page"' : '';
    return '<a href="' . h($href) . '"' . $aria . '>' . h($label) . '</a>';
};
?>
<nav class="container osf-nav">
    <ul>
        <li class="osf-brand"><a href="/admin">OpenSendForm</a></li>
    </ul>
    <ul>
        <li><?= $link('dashboard', '/admin', 'Dashboard') ?></li>
        <li><?= $link('forms', '/admin/forms', 'Forms') ?></li>
        <li><?= $link('submissions', '/admin/submissions', 'Submissions') ?></li>
        <li><?= $link('admins', '/admin/admins', 'Admins') ?></li>
        <li>
            <button type="button" class="osf-theme-toggle" data-theme-toggle
                    aria-label="Toggle colour theme" title="Toggle colour theme">☾</button>
        </li>
        <?php if (($adminName ?? '') !== ''): ?>
            <li class="osf-admin-name">
                <?= $link('account', '/admin/account', $adminName) ?>
            </li>
        <?php endif; ?>
        <li>
            <form method="post" action="/admin/logout">
                <input type="hidden" name="_csrf" value="<?= h($csrf ?? '') ?>">
                <button type="submit" class="secondary">Log out</button>
            </form>
        </li>
    </ul>
</nav>
