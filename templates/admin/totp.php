<?php use function OpenSendForm\Admin\h; ?>
<h1>Two-factor authentication</h1>

<p>Enter the 6-digit code from your authenticator app.</p>

<?php if (($error ?? '') !== ''): ?>
    <p role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<form method="post" action="/admin/totp">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <p>
        <label for="code">Authentication code</label><br>
        <input type="text" id="code" name="code" inputmode="numeric"
               autocomplete="one-time-code" pattern="[0-9]*" maxlength="6"
               autofocus required data-totp-code>
    </p>
    <p>
        <button type="submit">Verify</button>
    </p>
</form>

<h2>Lost your device?</h2>
<p>Enter one of your one-time recovery codes instead. Each code works once.</p>
<form method="post" action="/admin/totp">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <p>
        <label for="recovery">Recovery code</label><br>
        <input type="text" id="recovery" name="code" autocomplete="off">
    </p>
    <p>
        <button type="submit">Use recovery code</button>
    </p>
</form>
