<?php use function OpenSendForm\Admin\h; ?>
<h1>Admin sign in</h1>

<?php if (($error ?? '') !== ''): ?>
    <p role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<form method="post" action="/admin/login">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <p>
        <label for="email">Email</label><br>
        <input type="email" id="email" name="email" autocomplete="username"
               value="<?= h($email ?? '') ?>" required autofocus>
    </p>
    <p>
        <label for="password">Password</label><br>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
    </p>
    <p>
        <button type="submit">Sign in</button>
    </p>
</form>
