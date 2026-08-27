<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * @var array<int, array<string, mixed>> $forms
 * @var string $csrf
 */
?>
<h1>Forms</h1>
<p><a href="/admin/forms/new" role="button">New form</a></p>

<?php if ($forms === []): ?>
    <p>No forms yet. <a href="/admin/forms/new">Create your first form</a>.</p>
<?php else: ?>
    <div class="osf-table-wrap">
        <table class="osf-table">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Key</th>
                    <th scope="col">Recipient</th>
                    <th scope="col">Status</th>
                    <th scope="col">Turnstile</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($forms as $form): ?>
                <?php
                $id = (int) $form['id'];
                $active = (int) $form['is_active'] === 1;
                $turnstile = ($form['turnstile_sitekey'] ?? null) !== null
                    && ($form['turnstile_secret'] ?? null) !== null;
                ?>
                <tr>
                    <td data-label="Name"><a href="/admin/forms/<?= h((string) $id) ?>/edit"><?= h((string) $form['name']) ?></a></td>
                    <td data-label="Key">
                        <span class="osf-copy">
                            <code><?= h((string) $form['form_key']) ?></code>
                            <button type="button" class="secondary outline"
                                    data-copy="<?= h((string) $form['form_key']) ?>"><?= icon('copy') ?> Copy</button>
                        </span>
                    </td>
                    <td data-label="Recipient"><?= h((string) $form['recipient_email']) ?></td>
                    <td data-label="Status">
                        <?php if ($active): ?>
                            <span class="osf-badge osf-badge--ok">active</span>
                        <?php else: ?>
                            <span class="osf-badge osf-badge--muted">disabled</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Turnstile">
                        <?php if ($turnstile): ?>
                            <span class="osf-badge osf-badge--info">on</span>
                        <?php else: ?>
                            <span class="osf-badge osf-badge--muted">off</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions">
                        <div class="osf-actions">
                            <a href="/admin/forms/<?= h((string) $id) ?>/edit" role="button" class="secondary osf-btn-sm osf-btn-equal"><?= icon('pencil') ?> Edit</a>
                            <form class="osf-inline-form"
                                  method="post"
                                  action="/admin/forms/<?= h((string) $id) ?>/<?= $active ? 'disable' : 'enable' ?>">
                                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                                <button type="submit" class="<?= $active ? 'osf-danger' : 'secondary' ?> osf-btn-sm osf-btn-equal"><?= $active ? 'Disable' : 'Enable' ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
