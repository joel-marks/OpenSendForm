<?php use function OpenSendForm\Admin\h; ?>
<h1>Two-factor authentication</h1>

<?php if (($enabled ?? false) === true): ?>

    <p>Two-factor authentication is <strong>enabled</strong> on your account.</p>

    <h2>Regenerate recovery codes</h2>
    <p>
        This invalidates your existing recovery codes and issues a new set.
        Confirm with a current code from your authenticator app.
    </p>
    <?php if (($error ?? '') !== ''): ?>
        <p role="alert"><strong><?= h($error) ?></strong></p>
    <?php endif; ?>
    <form method="post" action="/admin/totp/recovery-codes/regenerate">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <p>
            <label for="code">Authentication code</label><br>
            <input type="text" id="code" name="code" inputmode="numeric"
                   autocomplete="one-time-code" required>
        </p>
        <p>
            <button type="submit">Regenerate recovery codes</button>
        </p>
    </form>

<?php else: ?>

    <p>
        Scan the QR code below with your authenticator app, or enter the key
        manually. Then confirm with the 6-digit code the app shows.
    </p>

    <p>Provisioning URI (encode as a QR code):</p>
    <p><code><?= h($otpauthUri) ?></code></p>

    <p>Manual entry key:</p>
    <p><code><?= h($manualKey) ?></code></p>

    <?php if (($error ?? '') !== ''): ?>
        <p role="alert"><strong><?= h($error) ?></strong></p>
    <?php endif; ?>

    <form method="post" action="/admin/totp/setup">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <p>
            <label for="code">Authentication code</label><br>
            <input type="text" id="code" name="code" inputmode="numeric"
                   autocomplete="one-time-code" autofocus required>
        </p>
        <p>
            <button type="submit">Enable two-factor authentication</button>
        </p>
    </form>

<?php endif; ?>

<p><a href="/admin">Back to dashboard</a></p>
