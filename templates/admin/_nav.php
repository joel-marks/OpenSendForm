<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/** @var string $activeNav */
$active = $activeNav ?? '';
$link = static function (string $key, string $href, string $label) use ($active): string {
    $aria = $key === $active ? ' aria-current="page"' : '';
    return '<a class="osf-nav-link" href="' . h($href) . '"' . $aria . '>' . h($label) . '</a>';
};
?>
<header class="osf-header">
    <nav class="osf-nav container">
        <a class="osf-brand" href="/admin">OpenSendForm</a>

        <div class="osf-nav-links">
            <?= $link('dashboard', '/admin', 'Dashboard') ?>
            <?= $link('forms', '/admin/forms', 'Forms') ?>
            <?= $link('submissions', '/admin/submissions', 'Submissions') ?>
            <?= $link('mail', '/admin/mail', 'Email') ?>
            <?= $link('admins', '/admin/admins', 'Admins') ?>
            <?php if (($adminName ?? '') !== ''): ?>
                <?= $link('account', '/admin/account', 'Account') ?>
            <?php endif; ?>
        </div>

        <div class="osf-nav-actions">
            <?php /* External docs; opens in a new tab, announced accessibly. */ ?>
            <a class="osf-nav-link osf-nav-docs" href="https://opensendform.com"
               target="_blank" rel="noopener">
                <?= icon('book-open') ?> Docs
                <span class="osf-visually-hidden"> (opens in a new tab)</span>
            </a>

            <button type="button" class="osf-theme-toggle" data-theme-toggle
                    aria-label="Toggle colour theme" title="Toggle colour theme">
                <?= icon('sun', 'osf-icon-sun') ?><?= icon('moon', 'osf-icon-moon') ?><?= icon('monitor', 'osf-icon-monitor') ?>
            </button>

            <?php if (($adminName ?? '') !== ''): ?>
                <a class="osf-admin-name osf-nav-link" href="/admin/account"><?= h($adminName) ?></a>
            <?php endif; ?>

            <form method="post" action="/admin/logout" class="osf-inline-form">
                <input type="hidden" name="_csrf" value="<?= h($csrf ?? '') ?>">
                <button type="submit" class="secondary"><?= icon('log-out') ?> Log out</button>
            </form>
        </div>
    </nav>
</header>
