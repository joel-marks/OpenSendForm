<?php

declare(strict_types=1);

use OpenSendForm\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = AppFactory::create();
$app->run();
