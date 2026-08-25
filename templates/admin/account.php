<?php
use function OpenSendForm\Admin\h;

/**
 * @var string $email
 * @var string $displayName
 * @var bool   $totpEnabled
 * @var int    $minPasswordLength
 * @var string $csrf
 */
?>
<h1>Your account</h1>

<p>
    You are signed in as <strong><?= h($email) ?></strong>. Two-factor
    authentication is <strong><?= $totpEnabled ? 'enabled' : 'disabled' ?></strong>
    (<a href="/admin/totp/setup">manage</a>).
</p>

<section>
    <h2>Display name</h2>
    <form method="post" action="/admin/account/name">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <label for="display_name">Display name</label>
        <input type="text" id="display_name" name="display_name"
               value="<?= h($displayName) ?>" required>
        <button type="submit">Save display name</button>
    </form>
</section>

<section>
    <h2>Email address</h2>
    <p><small>Changing your email requires your current password.</small></p>
    <form method="post" action="/admin/account/email">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= h($email) ?>" required>
        <label for="email_current_password">Current password</label>
        <input type="password" id="email_current_password" name="current_password"
               autocomplete="current-password" required>
        <button type="submit">Update email</button>
    </form>
</section>

<section>
    <h2>Password</h2>
    <p><small>Enter your current password, then a new one of at least
        <?= h((string) $minPasswordLength) ?> characters, twice.</small></p>
    <form method="post" action="/admin/account/password">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password"
               autocomplete="current-password" required>
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password"
               autocomplete="new-password" minlength="<?= h((string) $minPasswordLength) ?>" required>
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               autocomplete="new-password" minlength="<?= h((string) $minPasswordLength) ?>" required>
        <button type="submit">Change password</button>
    </form>
</section>
