<?php

declare(strict_types=1);

return [
    'host' => $_ENV['SMTP_HOST'] ?? 'localhost',
    'port' => (int) ($_ENV['SMTP_PORT'] ?? 25),
    'username' => $_ENV['SMTP_USERNAME'] ?? '',
    'password' => $_ENV['SMTP_PASSWORD'] ?? '',
    'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? '',
    'from' => $_ENV['SMTP_FROM'] ?? 'clinic@example.local',
    'from_name' => $_ENV['SMTP_FROM_NAME'] ?? 'PatSign',
];
