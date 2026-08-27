<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;
?>
<h1>Admin sign in</h1>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><?= icon('alert-triangle') ?> <span><strong><?= h($error) ?></strong></span></p>
<?php endif; ?>

<form method="post" action="/admin/login">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <div class="osf-field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" autocomplete="username"
               value="<?= h($email ?? '') ?>" required autofocus>
    </div>
    <div class="osf-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
    </div>
    <button type="submit">Sign in</button>
</form>
