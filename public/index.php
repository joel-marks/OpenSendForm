<?php

declare(strict_types=1);

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Install\Paths;
use OpenSendForm\Mail\PhpMailerMailer;

require dirname(__DIR__) . '/vendor/autoload.php';

// Real install paths (honouring any OSF_BASE_DIR relocation), so the config
// file we load and the installed-state gate agree on one location.
$paths = Paths::production();

// Merged configuration: shipped defaults < written config file (once the
// installer has produced one) < environment. With no file present this is a
// pure-env boot, so the dev container is unchanged.
$config = Config::load($paths->configPath);

// Wire a real SMTP transport only when delivery is enabled and a host is
// configured. MAIL_ENABLED is the primary switch; SMTP_HOST is a secondary
// guard. With either unset/empty the app runs storage-only: submissions are
// stored at 'received' and no send is attempted. (A fresh install writes
// MAIL_ENABLED=0 — email setup is completed in the admin panel.)
$mailer = ($config->mailEnabled() && $config->smtpHost() !== '') ? new PhpMailerMailer($config) : null;

// When both the config file and the lock exist the app is installed; otherwise
// every non-install route redirects to /install.
$app = AppFactory::create($config, null, null, null, $mailer, null, null, $paths);
$app->run();
