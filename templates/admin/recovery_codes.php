<?php
use function OpenSendForm\Admin\h;
use function OpenSendForm\Admin\icon;
?>
<h1>Your recovery codes</h1>

<p class="osf-flash osf-flash--error" role="alert">
    <?= icon('alert-triangle') ?>
    <span><strong>Copy these codes now and store them somewhere safe.</strong>
    They are shown only once and will never be displayed again. Each code works
    exactly once to sign in if you lose access to your authenticator.</span>
</p>

<?php /* One code per line in a monospace block — the plain, selectable
         fallback with JavaScript disabled. "Copy all" joins the lines with
         newlines so the clipboard preserves the one-per-line layout. The
         copy/download buttons and the "saved" gate below are enhancements. */ ?>
<pre class="osf-recovery-block" data-recovery-codes><?php foreach ($codes as $i => $code): ?><?= $i > 0 ? "\n" : '' ?><code data-recovery-code><?= h($code) ?></code><?php endforeach; ?></pre>

<div class="osf-actions">
    <button type="button" class="secondary" data-recovery-copy hidden><?= icon('copy') ?> Copy all</button>
    <button type="button" class="secondary" data-recovery-download hidden><?= icon('download') ?> Download as .txt</button>
</div>

<?php /* Hidden until JS reveals it; without JS the continue link just works. */ ?>
<p data-recovery-gate hidden>
    <label>
        <input type="checkbox"> I have saved these recovery codes.
    </label>
</p>

<p><a href="/admin" data-recovery-continue>Continue to dashboard</a></p>
