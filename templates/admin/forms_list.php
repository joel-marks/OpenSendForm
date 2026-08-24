<?php
use function OpenSendForm\Admin\h;

/**
 * @var array<int, array<string, mixed>> $forms
 * @var string $csrf
 */
?>
<header>
    <h1>Forms</h1>
    <p><a href="/admin/forms/new" role="button">New form</a></p>
</header>

<?php if ($forms === []): ?>
    <p>No forms yet. <a href="/admin/forms/new">Create your first form</a>.</p>
<?php else: ?>
    <div class="osf-table-wrap">
        <table>
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
                    <td><a href="/admin/forms/<?= h((string) $id) ?>/edit"><?= h((string) $form['name']) ?></a></td>
                    <td>
                        <span class="osf-copy">
                            <code><?= h((string) $form['form_key']) ?></code>
                            <button type="button" class="secondary outline"
                                    data-copy="<?= h((string) $form['form_key']) ?>">Copy</button>
                        </span>
                    </td>
                    <td><?= h((string) $form['recipient_email']) ?></td>
                    <td>
                        <?php if ($active): ?>
                            <span class="osf-badge osf-badge--ok">active</span>
                        <?php else: ?>
                            <span class="osf-badge osf-badge--muted">disabled</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($turnstile): ?>
                            <span class="osf-badge osf-badge--info">on</span>
                        <?php else: ?>
                            <span class="osf-badge osf-badge--muted">off</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/admin/forms/<?= h((string) $id) ?>/edit">Edit</a>
                        <form class="osf-inline-form"
                              method="post"
                              action="/admin/forms/<?= h((string) $id) ?>/<?= $active ? 'disable' : 'enable' ?>">
                            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                            <button type="submit" class="secondary"><?= $active ? 'Disable' : 'Enable' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
