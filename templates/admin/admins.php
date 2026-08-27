<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;

/**
 * @var array<int, array<string, mixed>> $admins
 * @var int    $currentAdminId
 * @var int    $activeCount
 * @var int    $minPasswordLength
 * @var string $newEmail
 * @var string $newName
 * @var string $error
 * @var string $csrf
 */
?>
<h1>Admins</h1>

<p>
    Every admin is a co-operator of this installation: all admins see all forms
    and all submissions. There are no roles. Retire an admin by deactivating
    them, or delete the account permanently.
</p>

<div class="osf-table-wrap">
    <table class="osf-table">
        <thead>
            <tr>
                <th scope="col">Email</th>
                <th scope="col">Name</th>
                <th scope="col">2FA</th>
                <th scope="col">Status</th>
                <th scope="col">Last login</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $row): ?>
            <?php
            $id = (int) $row['id'];
            $active = (int) $row['is_active'] === 1;
            $isSelf = $id === $currentAdminId;
            // The last remaining active admin can never be deactivated or deleted.
            $canDeactivate = $active && $activeCount > 1;
            // Deleting an inactive admin is always allowed; an active one only
            // when they are not the last active admin. Never yourself, either way.
            $canDelete = !$isSelf && ($active ? $activeCount > 1 : true);
            ?>
            <tr<?= $active ? '' : ' class="osf-row-muted"' ?>>
                <td data-label="Email">
                    <?= h((string) $row['email']) ?>
                    <?php if ($isSelf): ?><span class="osf-badge osf-badge--info">you</span><?php endif; ?>
                </td>
                <td data-label="Name"><?= h((string) $row['display_name']) ?></td>
                <td data-label="2FA">
                    <?php if ((int) $row['totp_enabled'] === 1): ?>
                        <span class="osf-badge osf-badge--ok">on</span>
                    <?php else: ?>
                        <span class="osf-badge osf-badge--muted">off</span>
                    <?php endif; ?>
                </td>
                <td data-label="Status">
                    <?php if ($active): ?>
                        <span class="osf-badge osf-badge--ok">active</span>
                    <?php else: ?>
                        <span class="osf-badge osf-badge--warn">deactivated</span>
                    <?php endif; ?>
                </td>
                <td data-label="Last login"><?= h((string) ($row['last_login_at'] ?? 'never')) ?></td>
                <td data-label="Actions">
                    <?php if ($active && $canDeactivate): ?>
                        <form class="osf-inline-form" method="post"
                              action="/admin/admins/<?= h((string) $id) ?>/deactivate">
                            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                            <button type="submit" class="osf-danger">Deactivate</button>
                        </form>
                    <?php elseif ($active): ?>
                        <span class="osf-badge osf-badge--muted" title="The last active admin cannot be deactivated.">last active admin</span>
                    <?php else: ?>
                        <form class="osf-inline-form" method="post"
                              action="/admin/admins/<?= h((string) $id) ?>/reactivate">
                            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                            <button type="submit" class="secondary">Reactivate</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                        <a href="/admin/admins/<?= h((string) $id) ?>/delete" role="button" class="osf-danger"><?= icon('trash-2') ?> Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<section>
    <h2>Add an admin</h2>
    <?php if (($error ?? '') !== ''): ?>
        <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
    <?php endif; ?>
    <p>
        <small>Set an initial password of at least <?= h((string) $minPasswordLength) ?>
        characters. Share it with the new admin over a secure channel and ask
        them to change it from their account screen after their first sign-in.</small>
    </p>
    <form method="post" action="/admin/admins">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <label for="new_admin_email">Email address</label>
        <input type="email" id="new_admin_email" name="email" value="<?= h($newEmail) ?>" required>
        <label for="new_admin_name">Display name</label>
        <input type="text" id="new_admin_name" name="name" value="<?= h($newName) ?>" required>
        <label for="new_admin_password">Initial password</label>
        <input type="password" id="new_admin_password" name="password"
               autocomplete="new-password" minlength="<?= h((string) $minPasswordLength) ?>" required>
        <button type="submit">Create admin</button>
    </form>
</section>
