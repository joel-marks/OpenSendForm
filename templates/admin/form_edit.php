<?php
use function OpenSendForm\Admin\h;

/**
 * Create/edit form. Shared by "New form" and "Edit form": $isNew toggles the
 * heading, the POST action and the read-only key display. The Turnstile secret
 * is write-only — it is never rendered back; $turnstileSecretSet drives a
 * set/not-set hint instead.
 *
 * @var bool        $isNew
 * @var int|null    $formId
 * @var string|null $formKey
 * @var string      $name
 * @var string      $recipient
 * @var string      $origins
 * @var bool        $storeContent
 * @var int         $retentionDays
 * @var bool        $isActive
 * @var string      $turnstileSitekey
 * @var bool        $turnstileSecretSet
 * @var string      $error
 * @var string      $csrf
 */
$action = $isNew ? '/admin/forms' : '/admin/forms/' . (int) $formId;
?>
<h1><?= $isNew ? 'New form' : 'Edit form' ?></h1>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<?php if (!$isNew && $formKey !== null): ?>
    <p>
        <strong>Form key</strong> (public identifier used by the embed snippet):<br>
        <span class="osf-copy">
            <code><?= h($formKey) ?></code>
            <button type="button" class="secondary outline" data-copy="<?= h($formKey) ?>">Copy</button>
        </span>
    </p>
<?php endif; ?>

<form method="post" action="<?= h($action) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <label for="name">Name
        <input type="text" id="name" name="name" value="<?= h($name) ?>" required>
    </label>

    <label for="recipient">Recipient email
        <input type="email" id="recipient" name="recipient" value="<?= h($recipient) ?>" required>
        <small>Where passing submissions are relayed. Never shown to submitters.</small>
    </label>

    <label for="origins">Allowed origins (one per line)
        <textarea id="origins" name="origins" rows="3"
                  placeholder="https://example.com&#10;https://www.example.com" required><?= h($origins) ?></textarea>
        <small>Scheme + host (+ optional port), no path. A token/submit is only
            issued to a page whose origin is listed here.</small>
    </label>

    <fieldset>
        <label for="store_content">
            <input type="checkbox" id="store_content" name="store_content" value="1"
                   <?= $storeContent ? 'checked' : '' ?>>
            Retain submitted content after delivery
        </label>
        <small>
            Submitted fields are always held while a message is in flight so a
            failed send can be retried. With this <strong>off</strong> (the
            default), that content is cleared once the email is delivered — only
            metadata is kept. Turn it <strong>on</strong> to keep the submitted
            content in storage after a successful delivery too.
        </small>
    </fieldset>

    <label for="retention_days">Retention (days)
        <input type="number" id="retention_days" name="retention_days"
               value="<?= h((string) $retentionDays) ?>" min="1" max="3650" required>
        <small>Submissions older than this are purged. 1–3650 days.</small>
    </label>

    <label for="is_active">
        <input type="checkbox" id="is_active" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        Active
    </label>
    <small>Inactive forms reject token/submit requests.</small>

    <fieldset>
        <legend><strong>Cloudflare Turnstile</strong> (optional)</legend>
        <small>
            Provide <em>both</em> a site key and a secret to enable Turnstile,
            or clear the site key to disable it. The secret is stored
            server-side and never displayed.
        </small>

        <label for="turnstile_sitekey">Site key
            <input type="text" id="turnstile_sitekey" name="turnstile_sitekey"
                   value="<?= h($turnstileSitekey) ?>" autocomplete="off">
        </label>

        <label for="turnstile_secret">Secret key
            <input type="password" id="turnstile_secret" name="turnstile_secret"
                   autocomplete="off"
                   placeholder="<?= $turnstileSecretSet ? '•••••••• (leave blank to keep)' : 'not set' ?>">
            <small>
                <?php if ($turnstileSecretSet): ?>
                    A secret is currently <strong>set</strong>. Leave this blank
                    to keep it, or type a new one to replace it.
                <?php else: ?>
                    <strong>Not set.</strong>
                <?php endif; ?>
            </small>
        </label>
    </fieldset>

    <button type="submit"><?= $isNew ? 'Create form' : 'Save changes' ?></button>
    <a href="/admin/forms" role="button" class="secondary">Cancel</a>
</form>
