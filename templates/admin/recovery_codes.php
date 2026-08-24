<?php use function OpenSendForm\Admin\h; ?>
<h1>Your recovery codes</h1>

<p role="alert">
    <strong>Copy these codes now and store them somewhere safe.</strong>
    They are shown only once and will never be displayed again. Each code can
    be used a single time to sign in if you lose access to your authenticator.
</p>

<ul>
<?php foreach ($codes as $code): ?>
    <li><code><?= h($code) ?></code></li>
<?php endforeach; ?>
</ul>

<p><a href="/admin">Continue to dashboard</a></p>
