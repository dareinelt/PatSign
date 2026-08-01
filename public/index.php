<?php

declare(strict_types=1);

use App\Core\ApplicationFactory;

require __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);

ApplicationFactory::create($basePath)->run();
