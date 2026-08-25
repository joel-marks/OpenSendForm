<?php
use function OpenSendForm\Admin\h;

/**
 * @var string $csrf
 * @var string $dbSummary
 */
?>
<h1>Ready to finish</h1>

<p>Everything checks out. Here’s what will be set up:</p>

<ul>
    <li><strong>Database:</strong> <?= h($dbSummary) ?></li>
    <li><strong>Administrator:</strong> the account you just created.</li>
    <li><strong>Email sending:</strong> off for now — submissions are saved, and
        you’ll turn on email from the admin panel after signing in.</li>
</ul>

<p>
    Clicking below writes your settings and locks the installer so it can’t be
    run again by accident.
</p>

<form method="post" action="/install/finish">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <button type="submit">Finish setup</button>
</form>
