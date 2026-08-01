<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

$basePath = dirname(__DIR__);
Env::load($basePath . '/.env');
$config = new Config($basePath . '/config');
$pdo = Database::connect($config);

foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $migration) {
    echo "Running {$migration}\n";
    $pdo->exec((string) file_get_contents($migration));
}

foreach (glob(__DIR__ . '/seeders/*.sql') ?: [] as $seeder) {
    echo "Seeding {$seeder}\n";
    $pdo->exec((string) file_get_contents($seeder));
}

echo "Done\n";
