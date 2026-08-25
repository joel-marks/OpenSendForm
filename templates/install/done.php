<?php
use function OpenSendForm\Admin\h;

/**
 * @var string $version
 */
?>
<h1>OpenSendForm is installed 🎉</h1>

<p>Setup is complete. You can now sign in and start creating forms.</p>

<p><a href="/admin/login" role="button">Go to sign in</a></p>

<h2>Two things to know</h2>

<ul>
    <li>
        <strong>Turn on email next.</strong> Right now submissions are saved but
        no email is sent. After you sign in, open the admin panel to set up email
        delivery so submissions reach your inbox.
    </li>
    <li>
        <strong>Re-running setup.</strong> The installer is now locked. If you
        ever need to run it again, delete the file <code>var/install.lock</code>
        using your host’s file manager, then reload the installer.
    </li>
</ul>

<p><small>Installed version <?= h($version) ?>.</small></p>
