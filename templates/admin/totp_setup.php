<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;
?>
<h1>Two-factor authentication</h1>

<?php if (($enabled ?? false) === true): ?>

    <p>Two-factor authentication is <strong>enabled</strong> on your account.</p>

    <h2>Regenerate recovery codes</h2>
    <p>
        This invalidates your existing recovery codes and issues a new set.
        Confirm with a current code from your authenticator app.
    </p>
    <?php if (($error ?? '') !== ''): ?>
        <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
    <?php endif; ?>
    <?php /* Same six-box segmented enhancement (data-totp-code) as the login
             screen, from the shared admin.js — no divergent markup. */ ?>
    <form method="post" action="/admin/totp/recovery-codes/regenerate">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <div class="osf-field">
            <label for="code">Authentication code</label>
            <input type="text" id="code" name="code" inputmode="numeric"
                   autocomplete="one-time-code" pattern="[0-9]*" maxlength="6"
                   required data-totp-code>
        </div>
        <button type="submit">Regenerate recovery codes</button>
    </form>

    <?php /* Clearly separated destructive action: disabling 2FA. */ ?>
    <section class="osf-danger-zone">
        <h2>Disable two-factor authentication</h2>
        <p>
            Turning off two-factor authentication removes your authenticator
            secret and all recovery codes. To confirm, enter your current
            password <strong>and</strong> a current code from your app.
        </p>
        <?php if (($disableError ?? '') !== ''): ?>
            <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($disableError) ?></strong></p>
        <?php endif; ?>
        <form method="post" action="/admin/totp/disable">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <div class="osf-field">
                <label for="disable_password">Current password</label>
                <input type="password" id="disable_password" name="current_password"
                       autocomplete="current-password" required>
            </div>
            <div class="osf-field">
                <label for="disable_code">Authentication code</label>
                <input type="text" id="disable_code" name="code" inputmode="numeric"
                       autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" required>
            </div>
            <button type="submit" class="osf-danger">Disable two-factor authentication</button>
        </form>
    </section>

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
            <button type="button" class="secondary outline" data-copy="<?= h($manualKey) ?>"><?= icon('copy') ?> Copy</button>
        </span>
    </p>

    <?php if (($error ?? '') !== ''): ?>
        <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
    <?php endif; ?>

    <form method="post" action="/admin/totp/setup">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <div class="osf-field">
            <label for="code">Authentication code</label>
            <input type="text" id="code" name="code" inputmode="numeric"
                   autocomplete="one-time-code" pattern="[0-9]*" maxlength="6"
                   autofocus required data-totp-code>
        </div>
        <button type="submit">Enable two-factor authentication</button>
    </form>

<?php endif; ?>

<p><a href="/admin">Back to dashboard</a></p>
