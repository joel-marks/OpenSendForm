<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * @var int    $submissionId
 * @var string $formName
 * @var string $createdAt
 * @var string $status
 * @var array{status: string, form: string, page: string} $return
 * @var string $backUrl
 * @var string $csrf
 */
?>
<h1>Delete submission</h1>

<p>
    You are about to permanently delete submission
    <strong>#<?= h((string) $submissionId) ?></strong>:
</p>

<ul>
    <li>Form: <strong><?= h($formName) ?></strong></li>
    <li>Date: <?= h($createdAt) ?></li>
    <li>Status: <?= h($status) ?></li>
</ul>

<p role="alert"><strong>This cannot be undone.</strong></p>

<form method="post" action="/admin/submissions/<?= h((string) $submissionId) ?>/delete">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="status" value="<?= h($return['status']) ?>">
    <input type="hidden" name="form" value="<?= h($return['form']) ?>">
    <input type="hidden" name="page" value="<?= h($return['page']) ?>">
    <div class="osf-actions">
        <button type="submit" class="osf-danger"><?= icon('trash-2') ?> Permanently delete</button>
        <a href="<?= h($backUrl) ?>" role="button" class="secondary">Cancel</a>
    </div>
</form>
