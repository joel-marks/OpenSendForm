<?php use function OpenSendForm\Admin\h; ?>
<h1>Your recovery codes</h1>

<p class="osf-flash osf-flash--error" role="alert">
    <strong>Copy these codes now and store them somewhere safe.</strong>
    They are shown only once and will never be displayed again. Each code can
    be used a single time to sign in if you lose access to your authenticator.
</p>

<?php /* Plain, selectable list — the fallback with JavaScript disabled. The
         copy/download buttons and the "saved" gate below are enhancements. */ ?>
<ul data-recovery-codes>
<?php foreach ($codes as $code): ?>
    <li><code data-recovery-code><?= h($code) ?></code></li>
<?php endforeach; ?>
</ul>

<p>
    <button type="button" class="secondary" data-recovery-copy hidden>Copy all</button>
    <button type="button" class="secondary" data-recovery-download hidden>Download as .txt</button>
</p>

<?php /* Hidden until JS reveals it; without JS the continue link just works. */ ?>
<p data-recovery-gate hidden>
    <label>
        <input type="checkbox"> I have saved these recovery codes.
    </label>
</p>

<p><a href="/admin" data-recovery-continue>Continue to dashboard</a></p>
