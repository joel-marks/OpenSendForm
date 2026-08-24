<?php

declare(strict_types=1);

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Mail\PhpMailerMailer;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnvironment();

// Wire a real SMTP transport only when delivery is enabled and a host is
// configured. MAIL_ENABLED is the primary switch; SMTP_HOST is a secondary
// guard. With either unset/empty the app runs storage-only: submissions are
// stored at 'received' and no send is attempted.
$mailer = ($config->mailEnabled() && $config->smtpHost() !== '') ? new PhpMailerMailer($config) : null;

$app = AppFactory::create($config, null, null, null, $mailer);
$app->run();
