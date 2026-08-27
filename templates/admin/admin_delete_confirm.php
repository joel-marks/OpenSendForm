<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * @var int    $targetId
 * @var string $targetEmail
 * @var string $error
 * @var string $csrf
 */
?>
<h1>Delete admin</h1>

<p>
    You are about to permanently delete the admin account
    <strong><?= h($targetEmail) ?></strong>.
</p>

<p role="alert">
    <strong>This cannot be undone.</strong> The account, its 2FA enrolment
    and its recovery codes are removed immediately. If you want a reversible
    way to retire this admin instead, go back and use
    <strong>Deactivate</strong>.
</p>

<?php if ($error !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<form method="post" action="/admin/admins/<?= h((string) $targetId) ?>/delete">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="current_password">Your current password</label>
    <input type="password" id="current_password" name="current_password"
           autocomplete="current-password" required>
    <button type="submit" class="osf-danger"><?= icon('trash-2') ?> Permanently delete <?= h($targetEmail) ?></button>
    <a href="/admin/admins" role="button" class="secondary">Cancel</a>
</form>
