<?php use function OpenSendForm\Admin\h; ?>
<h1>Dashboard</h1>

<p>Signed in as <strong><?= h($displayName) ?></strong>.</p>

<?php if (($totpEnabled ?? false) === false): ?>
    <p><a href="/admin/totp/setup">Set up two-factor authentication</a></p>
<?php else: ?>
    <p><a href="/admin/totp/setup">Manage two-factor authentication</a></p>
<?php endif; ?>

<form method="post" action="/admin/logout">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <button type="submit">Log out</button>
</form>
