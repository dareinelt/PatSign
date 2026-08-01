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

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$insert = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');

// Baseline existing databases that predate migration tracking.
if ($applied === []) {
    $tableExists = static function (string $table) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };

    $statusEnumContains = static function (string $value) use ($pdo): bool {
        $stmt = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'status'");
        $stmt->execute();
        return str_contains((string) $stmt->fetchColumn(), "'{$value}'");
    };

    $markers = [
        '001_initial.sql' => static fn (): bool => $tableExists('documents'),
        '002_ui_admin.sql' => static fn (): bool => $statusEnumContains('analyzing'),
        '003_devices.sql' => static fn (): bool => $tableExists('devices'),
        '004_notifications.sql' => static fn (): bool => $tableExists('notifications'),
        '005_clearing.sql' => static fn (): bool => $statusEnumContains('clearing'),
        '006_forms.sql' => static fn (): bool => $tableExists('form_templates'),
    ];

    foreach ($markers as $name => $check) {
        if ($check()) {
            echo "Baselining {$name} (already present in schema)\n";
            $insert->execute([$name]);
            $applied[] = $name;
        }
    }
}

foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $migration) {
    $name = basename($migration);
    if (in_array($name, $applied, true)) {
        echo "Skipping {$migration} (already applied)\n";
        continue;
    }
    echo "Running {$migration}\n";
    $pdo->exec((string) file_get_contents($migration));
    $insert->execute([$name]);
}

foreach (glob(__DIR__ . '/seeders/*.sql') ?: [] as $seeder) {
    echo "Seeding {$seeder}\n";
    $pdo->exec((string) file_get_contents($seeder));
}

echo "Done\n";
