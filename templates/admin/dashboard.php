<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\truncate;
use function OpenSendForm\Admin\statusBadgeClass;

/**
 * @var int   $activeForms
 * @var int   $todayCount
 * @var int   $failedCount
 * @var int   $deadCount
 * @var bool  $totpEnabled
 * @var array<int, array<string, mixed>> $recent
 */
?>
<h1>Dashboard</h1>

<?php if (($showNudge ?? false) === true): ?>
    <?php /* Dismissible-per-session nudge urging 2FA enrolment. Dismissing
             sets a session flag (see AdminController::dismissNudge); it
             returns after 2FA is disabled again. */ ?>
    <div class="osf-flash osf-flash--info osf-nudge" role="note">
        <span>
            <strong>Protect your account.</strong>
            Two-factor authentication is not enabled.
            <a href="/admin/totp/setup">Set it up now</a>.
        </span>
        <form method="post" action="/admin/nudge/dismiss" class="osf-inline-form">
            <input type="hidden" name="_csrf" value="<?= h($csrf ?? '') ?>">
            <button type="submit" class="secondary outline">Dismiss</button>
        </form>
    </div>
<?php endif; ?>

<?php if (($showMailNudge ?? false) === true): ?>
    <?php /* Second dismissible nudge, mirroring the 2FA one: email sending is
             off, so submissions are saved but not delivered. Points at the
             mail-setup wizard. Returns if sending is turned off again. */ ?>
    <div class="osf-flash osf-flash--info osf-nudge" role="note">
        <span>
            <strong>Email sending is not set up yet.</strong>
            Submissions are being saved but not emailed to you.
            <a href="/admin/mail">Set up email now</a>.
        </span>
        <form method="post" action="/admin/nudge/mail/dismiss" class="osf-inline-form">
            <input type="hidden" name="_csrf" value="<?= h($csrf ?? '') ?>">
            <button type="submit" class="secondary outline">Dismiss</button>
        </form>
    </div>
<?php endif; ?>

<section class="osf-stats">
    <article class="osf-stat">
        <div class="osf-stat-value"><?= h((string) $activeForms) ?></div>
        <div class="osf-stat-label">Active forms</div>
    </article>
    <article class="osf-stat">
        <div class="osf-stat-value"><?= h((string) $todayCount) ?></div>
        <div class="osf-stat-label">Submissions today</div>
    </article>
    <article class="osf-stat">
        <div class="osf-stat-value"><?= h((string) $failedCount) ?></div>
        <div class="osf-stat-label">Failed (retrying)</div>
    </article>
    <article class="osf-stat">
        <div class="osf-stat-value"><?= h((string) $deadCount) ?></div>
        <div class="osf-stat-label">Dead (gave up)</div>
    </article>
</section>

<h2>Recent delivery problems</h2>
<?php if ($recent === []): ?>
    <p>No failed or dead submissions. 🎉</p>
<?php else: ?>
    <div class="osf-table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Form</th>
                    <th scope="col">Created</th>
                    <th scope="col">Status</th>
                    <th scope="col">Attempts</th>
                    <th scope="col">Last error</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <?php $status = (string) $row['status']; ?>
                <tr>
                    <td>
                        <a href="/admin/submissions?status=<?= h($status) ?>&amp;form=<?= h((string) $row['form_id']) ?>">
                            <?= h((string) ($row['form_name'] ?? ('#' . $row['form_id']))) ?>
                        </a>
                    </td>
                    <td><?= h((string) $row['created_at']) ?></td>
                    <td><span class="osf-badge <?= h(statusBadgeClass($status)) ?>"><?= h($status) ?></span></td>
                    <td><?= h((string) $row['attempts']) ?></td>
                    <td class="osf-truncate" title="<?= h((string) ($row['last_error'] ?? '')) ?>">
                        <?= h(truncate($row['last_error'] ?? '', 80)) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<p>
    <?php if ($totpEnabled === false): ?>
        <a href="/admin/totp/setup">Set up two-factor authentication</a>
    <?php else: ?>
        <a href="/admin/totp/setup">Manage two-factor authentication</a>
    <?php endif; ?>
</p>
