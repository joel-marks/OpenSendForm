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

    <?php /* JS renders an SVG QR from data-qr client-side; the secret never
             leaves the page. With JS off, the otpauth URI below is the fallback. */ ?>
    <div class="osf-qr" data-qr="<?= h($otpauthUri) ?>">
        <noscript><small>Enable JavaScript to see a QR code, or use the key below.</small></noscript>
    </div>

    <p><small>Provisioning URI (if you cannot scan the QR):</small></p>
    <p><code><?= h($otpauthUri) ?></code></p>

    <p>Manual entry key:</p>
    <p>
        <span class="osf-copy">
            <code><?= h($manualKey) ?></code>
            <button type="button" class="secondary outline" data-copy="<?= h($manualKey) ?>">Copy</button>
        </span>
    </p>

    <?php if (($error ?? '') !== ''): ?>
        <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
    <?php endif; ?>

    <form method="post" action="/admin/totp/setup">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <p>
            <label for="code">Authentication code</label><br>
            <input type="text" id="code" name="code" inputmode="numeric"
                   autocomplete="one-time-code" pattern="[0-9]*" maxlength="6"
                   autofocus required data-totp-code>
        </p>
        <p>
            <button type="submit">Enable two-factor authentication</button>
        </p>
    </form>

<?php endif; ?>

<p><a href="/admin">Back to dashboard</a></p>
