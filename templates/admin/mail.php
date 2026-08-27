<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * The mail-setup wizard: SMTP settings, a test send, and the SPF/DKIM/DMARC
 * deliverability checker. Non-technical audience — plain language, copy-paste
 * values. No inline scripts/handlers (strict CSP); the copy buttons reuse the
 * shared [data-copy] enhancement in admin.js.
 *
 * @var string $error
 * @var string $smtpHost
 * @var string $smtpPort
 * @var string $smtpEncryption
 * @var string $smtpUser
 * @var bool   $passwordSet
 * @var string $fromAddress
 * @var string $fromName
 * @var bool   $mailEnabled
 * @var array<int, string> $shadowed
 * @var bool   $offerEnable
 * @var string $testRecipient
 * @var array<string, mixed> $report
 * @var string $selector
 * @var string $csrf
 */

/** One deliverability check row. */
$renderCheck = static function (array $check) use ($report): void {
    $ok = ($check['ok'] ?? false) === true;
    $badge = $ok ? 'osf-badge--ok' : 'osf-badge--warn';
    $stateText = match ((string) ($check['state'] ?? '')) {
        'present'   => 'Published',
        'found'     => 'Published',
        'absent'    => 'Not found',
        'not_found' => 'Not found',
        default     => 'Unknown',
    };
    ?>
    <article>
        <header>
            <strong><?= h((string) $check['label']) ?></strong>
            <span class="osf-badge <?= h($badge) ?>"><?= h($stateText) ?></span>
        </header>
        <p><small><?= h((string) $check['explain']) ?></small></p>

        <?php if ($ok): ?>
            <p><small>Found on <code><?= h((string) ($report['domain'] ?? '')) ?></code>:</small></p>
            <p class="osf-recovery-block"><code><?= h((string) $check['found']) ?></code></p>
        <?php else: ?>
            <?php if ((string) ($check['recommended'] ?? '') !== ''): ?>
                <p><small>Add this <?= h((string) $check['type']) ?> record
                    (name <code><?= h((string) $check['name']) ?></code>):</small></p>
                <span class="osf-copy">
                    <code><?= h((string) $check['recommended']) ?></code>
                    <button type="button" class="secondary outline"
                            data-copy="<?= h((string) $check['recommended']) ?>"><?= icon('copy') ?> Copy</button>
                </span>
            <?php else: ?>
                <p><small>Record to look for: <code><?= h((string) $check['name']) ?></code>.</small></p>
            <?php endif; ?>
            <p><small><?= h((string) $check['where']) ?></small></p>
        <?php endif; ?>
    </article>
<?php
};
?>
<h1>Email</h1>

<p>
    Set up how OpenSendForm sends the emails your forms produce. Enter your
    mailbox’s SMTP details, send yourself a test, then publish the DNS records
    that stop your messages landing in spam.
</p>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<?php if ($shadowed !== []): ?>
    <div class="osf-flash osf-flash--info" role="status">
        <strong>Heads up:</strong> the following
        <?= count($shadowed) === 1 ? 'setting is' : 'settings are' ?> currently set
        by a server environment variable, which overrides what you save here:
        <strong><?= h(implode(', ', $shadowed)) ?></strong>.
        Editing them below has no effect until that override is removed.
    </div>
<?php endif; ?>

<!-- ================= SMTP settings ================= -->
<section>
    <h2>Sending account (SMTP)</h2>
    <p><small>Your host or mailbox provider gives you these details. They are the
        same settings an email program uses to send mail.</small></p>

    <form method="post" action="/admin/mail">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

        <div class="osf-field">
            <label for="smtp_host">SMTP host</label>
            <input type="text" id="smtp_host" name="smtp_host" value="<?= h($smtpHost) ?>"
                   placeholder="mail.yourdomain.com" autocomplete="off">
        </div>

        <div class="grid">
            <div class="osf-field">
                <label for="smtp_port">Port</label>
                <input type="text" id="smtp_port" name="smtp_port" value="<?= h($smtpPort) ?>"
                       placeholder="587" inputmode="numeric" autocomplete="off">
            </div>
            <div class="osf-field">
                <label for="smtp_encryption">Encryption</label>
                <select id="smtp_encryption" name="smtp_encryption">
                    <option value="starttls" <?= $smtpEncryption === 'starttls' ? 'selected' : '' ?>>
                        STARTTLS — usual choice, port 587
                    </option>
                    <option value="smtps" <?= $smtpEncryption === 'smtps' ? 'selected' : '' ?>>
                        SSL/TLS — port 465
                    </option>
                    <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>
                        None — only for a local test server
                    </option>
                </select>
            </div>
        </div>

        <div class="osf-field">
            <label for="smtp_user">Username</label>
            <input type="text" id="smtp_user" name="smtp_user" value="<?= h($smtpUser) ?>"
                   placeholder="you@yourdomain.com" autocomplete="off">
        </div>

        <div class="osf-field">
            <label for="smtp_pass">Password</label>
            <input type="password" id="smtp_pass" name="smtp_pass" autocomplete="new-password"
                   placeholder="<?= $passwordSet ? '••••••••  (leave blank to keep the saved password)' : '' ?>">
            <small><?= $passwordSet
                ? 'A password is saved. Leave this blank to keep it, or type a new one to replace it.'
                : 'No password saved yet. Leave blank if your server sends without a login.' ?></small>
        </div>

        <hr>

        <div class="osf-field">
            <label for="mail_from_address">From address</label>
            <input type="email" id="mail_from_address" name="mail_from_address"
                   value="<?= h($fromAddress) ?>" placeholder="hello@yourdomain.com" autocomplete="off">
            <small>What recipients see the email came from. Use an address at your own
                domain so the DNS records below can vouch for it.</small>
        </div>

        <div class="osf-field">
            <label for="mail_from_name">From name</label>
            <input type="text" id="mail_from_name" name="mail_from_name"
                   value="<?= h($fromName) ?>" placeholder="Your Website" autocomplete="off">
        </div>

        <div class="osf-field">
            <label>
                <input type="checkbox" name="mail_enabled" value="1" <?= $mailEnabled ? 'checked' : '' ?>>
                Send emails for new submissions
            </label>
            <small>When off, submissions are still saved — they just aren’t emailed.</small>
        </div>

        <button type="submit">Save email settings</button>
    </form>
</section>

<!-- ================= Test send ================= -->
<section>
    <h2>Send a test email</h2>
    <p><small>Sends one email using the settings you have <strong>saved</strong>
        (save first if you just changed them). The email arriving is the proof
        it all works.</small></p>

    <form method="post" action="/admin/mail/test">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <div class="osf-field">
            <label for="test_recipient">Send test to</label>
            <input type="email" id="test_recipient" name="test_recipient"
                   value="<?= h($testRecipient) ?>" placeholder="you@example.com" autocomplete="off">
        </div>
        <button type="submit" class="secondary">Send test email</button>
    </form>

    <?php if ($offerEnable): ?>
        <div class="osf-flash osf-flash--info" role="status">
            <p>Your test worked, but email sending is still <strong>off</strong>.
                Turn it on so real submissions get emailed.</p>
            <form method="post" action="/admin/mail/enable" class="osf-inline-form">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                <button type="submit">Enable sending now</button>
            </form>
        </div>
    <?php endif; ?>
</section>

<!-- ================= Deliverability ================= -->
<section>
    <h2>Deliverability (SPF, DKIM, DMARC)</h2>
    <p><small>These three DNS records tell the world your emails are genuine, so
        they reach the inbox instead of the spam folder. We check the domain of
        your From address:
        <strong><?= h((string) ($report['domain'] ?? '')) ?: '(set a valid From address first)' ?></strong>.</small></p>

    <?php if (($report['valid'] ?? false) !== true): ?>
        <p class="osf-flash osf-flash--info" role="status">
            Enter a valid From address at your own domain and save, then the
            records to add will appear here.
        </p>
    <?php else: ?>
        <?php $renderCheck($report['spf']); ?>

        <?php $renderCheck($report['dkim']); ?>
        <form method="get" action="/admin/mail">
            <div class="osf-field">
                <label for="dkim_selector">DKIM selector to check</label>
                <div class="grid">
                    <input type="text" id="dkim_selector" name="dkim_selector"
                           value="<?= h($selector) ?>" placeholder="default" autocomplete="off">
                    <button type="submit" class="secondary outline">Re-check</button>
                </div>
                <small>Most hosts use <code>default</code>. Your provider’s email
                    settings tell you the selector if it differs.</small>
            </div>
        </form>

        <?php $renderCheck($report['dmarc']); ?>

        <p><small>After adding a record, it can take a little while for the change
            to spread. Use <strong>Re-check</strong> to look again.</small></p>
    <?php endif; ?>
</section>
