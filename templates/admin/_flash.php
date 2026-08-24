<?php
use function OpenSendForm\Admin\h;

/**
 * Flash-message partial. Renders any one-time post-action notices drained
 * for this request. Each entry is ['type' => success|error|info, 'message'].
 *
 * @var array<int, array{type: string, message: string}> $flashes
 */
$messages = $flashes ?? [];
foreach ($messages as $flash):
    $type = in_array($flash['type'] ?? 'info', ['success', 'error', 'info'], true)
        ? $flash['type']
        : 'info';
    ?>
    <p class="osf-flash osf-flash--<?= h($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <?= h($flash['message'] ?? '') ?>
    </p>
<?php endforeach; ?>
