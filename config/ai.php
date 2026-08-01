<?php

declare(strict_types=1);

return [
    'vision' => [
        'host' => $_ENV['VISION_HOST'] ?? 'http://localhost',
        'port' => (int) ($_ENV['VISION_PORT'] ?? 11434),
        'api_key' => $_ENV['VISION_API_KEY'] ?? '',
        'model' => $_ENV['VISION_MODEL'] ?? 'vision-local',
        'timeout' => (int) ($_ENV['VISION_TIMEOUT'] ?? 600),
    ],
    'analysis' => [
        'host' => $_ENV['ANALYSIS_HOST'] ?? 'http://localhost',
        'port' => (int) ($_ENV['ANALYSIS_PORT'] ?? 11435),
        'api_key' => $_ENV['ANALYSIS_API_KEY'] ?? '',
        'model' => $_ENV['ANALYSIS_MODEL'] ?? 'gemma-4-e4b',
        'timeout' => (int) ($_ENV['ANALYSIS_TIMEOUT'] ?? 300),
    ],
];
