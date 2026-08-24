<?php

declare(strict_types=1);

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Mail\PhpMailerMailer;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnvironment();

// Wire a real SMTP transport only when a host is configured. With SMTP_HOST
// empty the app runs storage-only: submissions are stored at 'received' and
// no send is attempted.
$mailer = $config->smtpHost() === '' ? null : new PhpMailerMailer($config);

$app = AppFactory::create($config, null, null, null, $mailer);
$app->run();
